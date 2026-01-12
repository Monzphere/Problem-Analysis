<?php declare(strict_types = 0);

/**
 * Controller for analist problem popup
 */

namespace Modules\AnalistProblem\Actions;

use CController;
use CControllerResponseData;
use API;
use CArrayHelper;
use CSeverityHelper;

/**
 * Controller for event details popup
 */
class CControllerAnalistProblemPopup extends CController {

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        $fields = [
            'eventid'       => 'required|id',
            'triggerid'     => 'id',
            'hostid'        => 'id',
            'hostname'      => 'string',
            'problem_name'  => 'string',
            'severity'      => 'int32',
            'clock'         => 'int32',
            'acknowledged'  => 'int32'
        ];

        $ret = $this->validateInput($fields);

        if (!$ret) {
            $this->setResponse(
                (new CControllerResponseData(['main_block' => json_encode([
                    'error' => [
                        'messages' => array_column(get_and_clear_messages(), 'message')
                    ]
                ])]))->disableView()
            );
        }

        return $ret;
    }

    protected function checkPermissions(): bool {
        return $this->checkAccess('ui.monitoring.problems');
    }

    protected function doAction(): void {
        $eventid = $this->getInput('eventid');
        $triggerid = $this->getInput('triggerid', 0);
        $hostid = $this->getInput('hostid', 0);



        // Get event details
        $events = API::Event()->get([
            'output' => ['eventid', 'source', 'object', 'objectid', 'clock', 'ns', 'value', 'acknowledged', 'name', 'severity'],
            'eventids' => $eventid,
            'selectTags' => ['tag', 'value']
        ]);

        $event = $events ? $events[0] : [];

        // If no event found, create a minimal event object to prevent errors
        if (!$event) {
            $event = [
                'eventid' => $eventid,
                'name' => _('Event not found'),
                'severity' => 0,
                'clock' => time(),
                'acknowledged' => 0,
                'source' => 0,
                'object' => 0,
                'objectid' => 0,
                'value' => 1
            ];
        }

        // Get trigger details - primeiro tenta com triggerid passado, senão extrai do evento
        $trigger = null;
        $actual_triggerid = $triggerid;

        // Se não temos triggerid, tenta extrair do evento
        if (!$actual_triggerid && $event && isset($event['objectid'])) {
            $actual_triggerid = $event['objectid'];
        }

        if ($actual_triggerid > 0) {
            $triggers = API::Trigger()->get([
                'output' => ['triggerid', 'description', 'expression', 'comments', 'priority'],
                'triggerids' => $actual_triggerid,
                'selectHosts' => ['hostid', 'host', 'name'],
                'selectItems' => ['itemid', 'hostid', 'name', 'key_'], // This will get all items with itemid
                'expandExpression' => true
            ]);
            $trigger = $triggers ? $triggers[0] : null;




            if ($trigger) {



                if (isset($trigger['items'])) {

                } else {

                }
            } else {

            }
        }

        // Get host details with comprehensive data for hostcard
        $host = null;
        $actual_hostid = $hostid;

        // Se não temos hostid, tenta extrair do trigger
        if (!$actual_hostid && $trigger && isset($trigger['hosts']) && !empty($trigger['hosts'])) {
            $actual_hostid = $trigger['hosts'][0]['hostid'];

        }

        if ($actual_hostid > 0) {
            $host = $this->getHostCardData($actual_hostid);

        } else {

        }

        // Get related events for timeline
        $related_events = [];
        if ($actual_triggerid > 0) {
            $related_events = API::Event()->get([
                'output'    => ['eventid', 'clock', 'value', 'acknowledged', 'name', 'severity'],
                'source'    => 0, // EVENT_SOURCE_TRIGGERS
                'object'    => 0, // EVENT_OBJECT_TRIGGER
                'objectids' => $actual_triggerid,
                'sortfield' => 'clock',
                'sortorder' => 'DESC',
                'limit'     => 20
            ]);

            // Fix severity for resolution events
            $trigger_severity     = $trigger && isset($trigger['priority']) ? (int)$trigger['priority'] : 0;
            $main_event_severity  = isset($event['severity']) ? (int)$event['severity'] : 0;
            $last_problem_severity = 0;

            // Process events in chronological order to track problem severity
            $events_chronological = array_reverse($related_events);
            foreach ($events_chronological as &$rel_event) {
                if ($rel_event['value'] == 1) {
                    // Problem event: update the last known severity
                    $last_problem_severity = (int)$rel_event['severity'];
                } else {
                    // Resolution event: normalize severity
                    $resolution_severity = $last_problem_severity > 0
                        ? $last_problem_severity
                        : ($main_event_severity > 0 ? $main_event_severity : $trigger_severity);
                    $rel_event['severity'] = $resolution_severity;
                }
            }
            unset($rel_event);

            // Restore original order (DESC)
            $related_events = array_reverse($events_chronological);

            $related_events = array_filter($related_events, function($ev) {
                return (int)$ev['value'] === 1;
            });
        }

        // Get items for graphs - usar itemids do selectItems diretamente
        $items = [];

        if ($trigger && $actual_triggerid > 0) {
            if (isset($trigger['items']) && !empty($trigger['items'])) {
                // Get itemids from selectItems and ensure uniqueness
                $trigger_itemids = array_column($trigger['items'], 'itemid');
                $unique_itemids = array_unique($trigger_itemids);

                // Get items and ensure no duplicates by using itemid as key
                $raw_items = API::Item()->get([
                    'output' => ['itemid', 'name', 'key_', 'hostid', 'value_type'],
                    'itemids' => $unique_itemids,
                    'monitored' => true,
                    'filter' => [
                        'value_type' => [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64] // Only numeric items for graphs
                    ]
                ]);

                // Use itemid as key to prevent any potential duplicates
                $items_by_id = [];
                foreach ($raw_items as $item) {
                    $items_by_id[$item['itemid']] = $item;
                }

                // Convert back to indexed array
                $items = array_values($items_by_id);
            }
        }

        // Get monthly comparison data (last 6 months)
        $monthly_comparison = [];

        if ($actual_triggerid > 0 && isset($event['clock'])) {
            $event_timestamp = (int)$event['clock'];

            // Base at the first day of the event's month 00:00:00
            $base_month_start = strtotime(date('Y-m-01 00:00:00', $event_timestamp));

            $months = [];

            // Build last 6 months: 0 = current month, 1..5 = back in time
            for ($i = 0; $i < 6; $i++) {
                $start = strtotime("-{$i} month", $base_month_start);
                // Month end = last day of that month 23:59:59
                $end = mktime(23, 59, 59, (int)date('n', $start), (int)date('t', $start), (int)date('Y', $start));

                // Fetch problem events for this month
                $events = API::Event()->get([
                    'output'    => ['eventid', 'clock', 'value', 'severity'],
                    'source'    => 0,
                    'object'    => 0,
                    'objectids' => $actual_triggerid,
                    'time_from' => $start,
                    'time_till' => $end,
                    'value'     => 1 // Only problem events
                ]);

                $months[$i] = [
                    'name'   => date('F Y', $start),
                    'count'  => count($events),
                    'events' => $events,
                    'start'  => $start,
                    'end'    => $end
                ];
            }

            // Add month-over-month percentage change for each entry where we have a previous month
            for ($i = 0; $i < 6; $i++) {
                if (!isset($months[$i + 1])) {
                    $months[$i]['change_percentage'] = null; // no previous to compare to
                    continue;
                }

                $prev = (int)$months[$i + 1]['count'];
                $cur  = (int)$months[$i]['count'];

                if ($prev > 0) {
                    $months[$i]['change_percentage'] = round((($cur - $prev) / $prev) * 100, 1);
                } else {
                    $months[$i]['change_percentage'] = ($cur > 0) ? 100.0 : 0.0;
                }
            }

            $monthly_comparison = [
                'months' => $months,

                // Backward compatibility (your current view code uses these)
                'current_month'    => $months[0],
                'previous_month'   => $months[1] ?? ['name' => '', 'count' => 0, 'events' => [], 'start' => null, 'end' => null],
                'change_percentage'=> $months[0]['change_percentage'] ?? 0
            ];
        }
        // Get monthly comparison data


        // Get system metrics at event time (only for Zabbix Agent hosts)
        $system_metrics = [];
        if ($host && isset($event['clock']) && isset($host['interfaces'])) {
            $system_metrics = $this->getSystemMetricsAtEventTime($host, $event['clock']);
        }

        // Prepare data for view
        $data = [
            'event' => $event,
            'trigger' => $trigger,
            'host' => $host,
            'related_events' => $related_events,
            'items' => $items,
            'monthly_comparison' => $monthly_comparison,
            'system_metrics' => $system_metrics,
            'user' => [
                'debug_mode' => $this->getDebugMode()
            ]
        ];

        $this->setResponse(new CControllerResponseData($data));
    }

    /**
     * Get system metrics at event time based on host interface type
     */
    /**
     * Get comprehensive host data for hostcard display
     */
    private function getHostCardData($hostid) {
        $options = [
            'output' => ['hostid', 'name', 'status', 'maintenanceid', 'maintenance_status', 'maintenance_type',
                'description', 'active_available', 'monitored_by', 'proxyid', 'proxy_groupid'
            ],
            'hostids' => $hostid,
            'selectHostGroups' => ['name'],
            'selectInterfaces' => ['interfaceid', 'ip', 'dns', 'port', 'main', 'type', 'useip', 'available',
                'error', 'details'
            ],
            'selectParentTemplates' => ['templateid'],
            'selectTags' => ['tag', 'value'],
            'selectInheritedTags' => ['tag', 'value']
        ];

        // Always get counts for monitoring section
        $options['selectGraphs'] = API_OUTPUT_COUNT;
        $options['selectHttpTests'] = API_OUTPUT_COUNT;

        // Get inventory fields
        $inventory_fields = getHostInventories();
        $options['selectInventory'] = array_column($inventory_fields, 'db_field');

        $db_hosts = API::Host()->get($options);

        if (!$db_hosts) {
            return null;
        }

        $host = $db_hosts[0];

        // Get maintenance details if in maintenance
        if ($host['status'] == HOST_STATUS_MONITORED && $host['maintenance_status'] == HOST_MAINTENANCE_STATUS_ON) {
            $db_maintenances = API::Maintenance()->get([
                'output' => ['name', 'description'],
                'maintenanceids' => [$host['maintenanceid']]
            ]);

            $host['maintenance'] = $db_maintenances
                ? $db_maintenances[0]
                : [
                    'name' => _('Inaccessible maintenance'),
                    'description' => ''
                ];
        }

        // Get problem count for header
        if ($host['status'] == HOST_STATUS_MONITORED) {
            $db_triggers = API::Trigger()->get([
                'output' => [],
                'hostids' => [$host['hostid']],
                'skipDependent' => true,
                'monitored' => true,
                'preservekeys' => true
            ]);

            $db_problems = API::Problem()->get([
                'output' => ['eventid', 'severity'],
                'source' => EVENT_SOURCE_TRIGGERS,
                'object' => EVENT_OBJECT_TRIGGER,
                'objectids' => array_keys($db_triggers),
                'suppressed' => false,
                'symptom' => false
            ]);

            $host_problems = [];
            foreach ($db_problems as $problem) {
                $host_problems[$problem['severity']][$problem['eventid']] = true;
            }

            for ($severity = TRIGGER_SEVERITY_COUNT - 1; $severity >= TRIGGER_SEVERITY_NOT_CLASSIFIED; $severity--) {
                $host['problem_count'][$severity] = array_key_exists($severity, $host_problems)
                    ? count($host_problems[$severity])
                    : 0;
            }
        }

        // Sort host groups
        CArrayHelper::sort($host['hostgroups'], ['name']);

        // Get items count
        $db_items_count = API::Item()->get([
            'countOutput' => true,
            'hostids' => [$host['hostid']],
            'webitems' => true,
            'monitored' => true
        ]);

        // Get dashboard count (HostDashboard API is not available in all Zabbix versions)
        $host['dashboard_count'] = 0;
        if (method_exists(API::class, 'HostDashboard')) {
            $host['dashboard_count'] = API::HostDashboard()->get([
                'countOutput' => true,
                'hostids' => $host['hostid']
            ]);
        }

        $host['item_count'] = $db_items_count;
        $host['graph_count'] = $host['graphs'];
        $host['web_scenario_count'] = $host['httpTests'];

        unset($host['graphs'], $host['httpTests']);

        // Prepare interfaces for availability section
        $interface_enabled_items_count = getEnabledItemsCountByInterfaceIds(
            array_column($host['interfaces'], 'interfaceid')
        );

        foreach ($host['interfaces'] as &$interface) {
            $interfaceid = $interface['interfaceid'];
            $interface['has_enabled_items'] = array_key_exists($interfaceid, $interface_enabled_items_count)
                && $interface_enabled_items_count[$interfaceid] > 0;
        }
        unset($interface);

        // Add active agent interface if there are enabled active items
        $enabled_active_items_count = getEnabledItemTypeCountByHostId(ITEM_TYPE_ZABBIX_ACTIVE, [$host['hostid']]);
        if ($enabled_active_items_count) {
            $host['interfaces'][] = [
                'type' => INTERFACE_TYPE_AGENT_ACTIVE,
                'available' => $host['active_available'],
                'has_enabled_items' => true,
                'error' => ''
            ];
        }

        unset($host['active_available']);

        // Get proxy/proxy group info
        if ($host['monitored_by'] == ZBX_MONITORED_BY_PROXY) {
            $db_proxies = API::Proxy()->get([
                'output' => ['name'],
                'proxyids' => [$host['proxyid']]
            ]);
            $host['proxy'] = $db_proxies[0];
        }
        elseif ($host['monitored_by'] == ZBX_MONITORED_BY_PROXY_GROUP) {
            $db_proxy_groups = API::ProxyGroup()->get([
                'output' => ['name'],
                'proxy_groupids' => [$host['proxy_groupid']]
            ]);
            $host['proxy_group'] = $db_proxy_groups[0];
        }

        // Get templates
        if ($host['parentTemplates']) {
            $db_templates = API::Template()->get([
                'output' => ['templateid', 'name'],
                'selectParentTemplates' => ['templateid', 'name'],
                'templateids' => array_column($host['parentTemplates'], 'templateid'),
                'preservekeys' => true
            ]);

            CArrayHelper::sort($db_templates, ['name']);

            foreach ($db_templates as &$template) {
                CArrayHelper::sort($template['parentTemplates'], ['name']);
            }
            unset($template);

            $host['templates'] = $db_templates;
        }
        else {
            $host['templates'] = [];
        }

        unset($host['parentTemplates']);

        // Merge host tags with inherited tags
        if (!$host['inheritedTags']) {
            $tags = $host['tags'];
        }
        elseif (!$host['tags']) {
            $tags = $host['inheritedTags'];
        }
        else {
            $tags = $host['tags'];

            foreach ($host['inheritedTags'] as $template_tag) {
                foreach ($tags as $host_tag) {
                    // Skip tags with same name and value
                    if ($host_tag['tag'] === $template_tag['tag']
                            && $host_tag['value'] === $template_tag['value']) {
                        continue 2;
                    }
                }

                $tags[] = $template_tag;
            }
        }

        CArrayHelper::sort($tags, ['tag', 'value']);
        $host['tags'] = $tags;

        return $host;
    }

    private function getSystemMetricsAtEventTime($host, $event_timestamp) {
        $hostid = $host['hostid'];
        $interfaces = $host['interfaces'] ?? [];

        // Determine monitoring type based on main interface
        $monitoring_type = $this->getHostMonitoringType($interfaces);

        $metrics = [
            'type' => $monitoring_type,
            'available' => false,
            'categories' => []
        ];

        // Only proceed if we have Zabbix Agent
        if ($monitoring_type !== 'agent') {
            return $metrics;
        }

        try {
            // Get essential system metrics for Zabbix Agent (using lastvalue)
            $metrics_list = $this->getEssentialSystemMetrics($hostid);
            $metrics['categories'] = $metrics_list;

            $metrics['available'] = !empty($metrics_list);

        } catch (Exception $e) {
            error_log('Error getting system metrics: ' . $e->getMessage());
        }

        return $metrics;
    }

    /**
     * Determine monitoring type based on host interfaces
     */
    private function getHostMonitoringType($interfaces) {
        if (empty($interfaces)) {
            return 'unknown';
        }

        // Find main interface
        foreach ($interfaces as $interface) {
            if ($interface['main'] == 1) {
                switch ($interface['type']) {
                    case 1: return 'agent';    // Zabbix Agent
                    case 2: return 'snmp';     // SNMP
                    case 3: return 'ipmi';     // IPMI
                    case 4: return 'jmx';      // JMX
                    default: return 'unknown';
                }
            }
        }

        return 'unknown';
    }

    /**
     * Get essential system metrics for Zabbix Agent: CPU, Memory, Load, Disk /
     */
    private function getEssentialSystemMetrics($hostid) {
        $metrics = [];

        // Define flexible patterns for different Zabbix versions
        $metric_patterns = [
            //'CPU' => ['system.cpu.util', 'system.cpu.utilization'],
            //'Memory' => ['vm.memory.util', 'vm.memory.size[available]', 'vm.memory.size[total]'],
            //'Load' => ['system.cpu.load[percpu,avg1]', 'system.cpu.load[,avg5]', 'system.cpu.load'],
            'Uptime' => ['system.uptime', 'system.hw.uptime[hrSystemUptime.0]'],
            'Os' => ['vfs.file.contents[/etc/os-release]', 'system.sw.os'],
            '/data' => ['vfs.fs.dependent.size[/data,pused]'],
            '/' => ['vfs.fs.dependent.size[/,pused]']
        ];

        // Search for each category using multiple patterns
        foreach ($metric_patterns as $category => $patterns) {
            $found_item = null;

            foreach ($patterns as $pattern) {
                $items = API::Item()->get([
                    'output' => ['itemid', 'name', 'key_', 'units', 'lastvalue', 'lastclock'],
                    'hostids' => $hostid,
                    'search' => ['key_' => $pattern],
                    'monitored' => true,
                    'filter' => [
                        'value_type' => [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64]
                    ],
                    'limit' => 1
                ]);

                if (!empty($items)) {
                    $found_item = $items[0];
                    break; // Use first matching pattern
                }
            }

            if ($found_item) {
                // Simply get the last value from the item
                $metric_data = [
                    'name' => $found_item['name'],
                    'key' => $found_item['key_'],
                    'units' => $found_item['units'] ?? '',
                    'category' => $category,
                    'last_value' => $found_item['lastvalue'] ?? 'N/A'
                ];

                $metrics[] = $metric_data;
            }
        }

        return $metrics;
    }
}
