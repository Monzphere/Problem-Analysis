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
                'output' => ['eventid', 'clock', 'value', 'acknowledged', 'name', 'severity'],
                'source' => 0, // EVENT_SOURCE_TRIGGERS
                'object' => 0, // EVENT_OBJECT_TRIGGER  
                'objectids' => $actual_triggerid,
                'sortfield' => 'clock',
                'sortorder' => 'DESC',
                'limit' => 15
            ]);
            
            // Fix severity for resolution events
            // Resolution events (value = 0) should use the severity from the trigger or original problem
            $trigger_severity = $trigger && isset($trigger['priority']) ? (int) $trigger['priority'] : 0;
            $main_event_severity = isset($event['severity']) ? (int) $event['severity'] : 0;
            $last_problem_severity = 0;
            
            // Process events in chronological order to track problem severity
            $events_chronological = array_reverse($related_events);
            foreach ($events_chronological as &$rel_event) {
                if ($rel_event['value'] == 1) {
                    // This is a problem event, update the last known severity
                    $last_problem_severity = (int) $rel_event['severity'];
                } else {
                    // This is a resolution event, use the last problem severity, main event severity, or trigger severity
                    $resolution_severity = $last_problem_severity > 0 ? $last_problem_severity : 
                                         ($main_event_severity > 0 ? $main_event_severity : $trigger_severity);
                    $rel_event['severity'] = $resolution_severity;
                }
            }
            unset($rel_event);
            
            // Restore original order (DESC)
            $related_events = array_reverse($events_chronological);
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

        // Get analytics data - real calculations
        $analytics_data = $this->calculateAnalyticsData($actual_triggerid, $hostid, $event);

        // Get impact assessment data - real calculations
        $impact_assessment_data = $this->calculateImpactAssessmentData($actual_triggerid, $hostid, $event);


        // Get monthly comparison data
        $monthly_comparison = [];
        if ($actual_triggerid > 0 && isset($event['clock'])) {
            $event_timestamp = $event['clock'];
            
            // Calculate current month and previous month periods
            $current_month_start = mktime(0, 0, 0, date('n', $event_timestamp), 1, date('Y', $event_timestamp));
            $current_month_end = mktime(23, 59, 59, date('n', $event_timestamp), date('t', $event_timestamp), date('Y', $event_timestamp));
            
            $prev_month_start = mktime(0, 0, 0, date('n', $event_timestamp) - 1, 1, date('Y', $event_timestamp));
            $prev_month_end = mktime(23, 59, 59, date('n', $event_timestamp) - 1, date('t', $prev_month_start), date('Y', $prev_month_start));
            
            // Handle year transition
            if (date('n', $event_timestamp) == 1) {
                $prev_month_start = mktime(0, 0, 0, 12, 1, date('Y', $event_timestamp) - 1);
                $prev_month_end = mktime(23, 59, 59, 12, 31, date('Y', $event_timestamp) - 1);
            }
            
            // Get events for current month
            $current_month_events = API::Event()->get([
                'output' => ['eventid', 'clock', 'value', 'severity'],
                'source' => 0,
                'object' => 0,
                'objectids' => $actual_triggerid,
                'time_from' => $current_month_start,
                'time_till' => $current_month_end,
                'value' => 1 // Only problem events
            ]);
            
            // Get events for previous month
            $prev_month_events = API::Event()->get([
                'output' => ['eventid', 'clock', 'value', 'severity'],
                'source' => 0,
                'object' => 0,
                'objectids' => $actual_triggerid,
                'time_from' => $prev_month_start,
                'time_till' => $prev_month_end,
                'value' => 1 // Only problem events
            ]);
            
            $monthly_comparison = [
                'current_month' => [
                    'name' => date('F Y', $event_timestamp),
                    'count' => count($current_month_events),
                    'events' => $current_month_events,
                    'start' => $current_month_start,
                    'end' => $current_month_end
                ],
                'previous_month' => [
                    'name' => date('F Y', $prev_month_start),
                    'count' => count($prev_month_events),
                    'events' => $prev_month_events,
                    'start' => $prev_month_start,
                    'end' => $prev_month_end
                ]
            ];
            
            // Calculate percentage change
            if ($monthly_comparison['previous_month']['count'] > 0) {
                $change = (($monthly_comparison['current_month']['count'] - $monthly_comparison['previous_month']['count']) / $monthly_comparison['previous_month']['count']) * 100;
                $monthly_comparison['change_percentage'] = round($change, 1);
            } else {
                $monthly_comparison['change_percentage'] = $monthly_comparison['current_month']['count'] > 0 ? 100 : 0;
            }
        }

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
            'analytics_data' => $analytics_data,
            'impact_assessment_data' => $impact_assessment_data,
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

        // Get dashboard count
        $host['dashboard_count'] = API::HostDashboard()->get([
            'countOutput' => true,
            'hostids' => $host['hostid']
        ]);

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
            'CPU' => ['system.cpu.util', 'system.cpu.utilization'],
            'Memory' => ['vm.memory.util', 'vm.memory.size[available]', 'vm.memory.size[total]'],
            'Load' => ['system.cpu.load[percpu,avg1]', 'system.cpu.load[,avg5]', 'system.cpu.load'],
            'Disk' => ['vfs.fs.size[/,pused]', 'vfs.fs.used[/]', 'vfs.fs.size[/,used]']
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

    /**
     * Calculate real analytics data for the problem
     */
    private function calculateAnalyticsData($triggerid, $hostid, $event) {
        $analytics = [
            'mttr' => $this->calculateMTTR($triggerid),
            'recurrence' => $this->calculateRecurrence($triggerid),
            'service_impact' => $this->calculateServiceImpact($hostid),
            'sla_risk' => $this->calculateSLARisk($triggerid, $event),
            'patterns' => $this->calculateHistoricalPatterns($triggerid),
            'performance_anomalies' => $this->calculatePerformanceAnomalies($hostid, $event)
        ];

        return $analytics;
    }

    /**
     * Calculate Mean Time To Resolution for this trigger
     */
    private function calculateMTTR($triggerid) {
        if (!$triggerid) {
            return [
                'value' => 'N/A',
                'status' => 'No data',
                'display' => 'No historical data available'
            ];
        }

        // Get resolved problem events from last 90 days
        $time_from = time() - (90 * 24 * 60 * 60); // 90 days ago

        $problem_events = API::Event()->get([
            'output' => ['eventid', 'clock', 'value'],
            'source' => 0,
            'object' => 0,
            'objectids' => [$triggerid],
            'time_from' => $time_from,
            'value' => [0, 1], // Both problem and resolution events
            'sortfield' => 'clock',
            'sortorder' => 'ASC'
        ]);

        $resolution_times = [];
        $current_problem_start = null;

        foreach ($problem_events as $event) {
            if ($event['value'] == 1) {
                // Problem event
                $current_problem_start = $event['clock'];
            } elseif ($event['value'] == 0 && $current_problem_start) {
                // Resolution event
                $resolution_time = $event['clock'] - $current_problem_start;
                $resolution_times[] = $resolution_time;
                $current_problem_start = null;
            }
        }

        if (empty($resolution_times)) {
            return [
                'value' => 'N/A',
                'status' => 'No historical resolutions',
                'display' => 'No resolved incidents in last 90 days'
            ];
        }

        $avg_resolution_time = array_sum($resolution_times) / count($resolution_times);
        $hours = round($avg_resolution_time / 3600, 1);

        return [
            'value' => $avg_resolution_time,
            'status' => 'In Progress',
            'display' => "{$hours}h average ({" . count($resolution_times) . "} incidents)"
        ];
    }

    /**
     * Calculate recurrence rate for this trigger
     */
    private function calculateRecurrence($triggerid) {
        if (!$triggerid) {
            return [
                'count' => 0,
                'monthly_avg' => 0,
                'status' => 'No data',
                'display' => 'No historical data'
            ];
        }

        $time_from = time() - (90 * 24 * 60 * 60); // 90 days ago

        $problem_events = API::Event()->get([
            'output' => ['eventid', 'clock'],
            'source' => 0,
            'object' => 0,
            'objectids' => [$triggerid],
            'time_from' => $time_from,
            'value' => 1 // Only problem events
        ]);

        $count = count($problem_events);
        $monthly_avg = round($count / 3, 1); // 90 days = ~3 months

        // Get current month count
        $current_month_start = mktime(0, 0, 0, date('n'), 1, date('Y'));
        $current_month_events = API::Event()->get([
            'output' => ['eventid'],
            'source' => 0,
            'object' => 0,
            'objectids' => [$triggerid],
            'time_from' => $current_month_start,
            'value' => 1
        ]);

        $current_count = count($current_month_events);
        $status = 'Normal';

        if ($monthly_avg > 0 && $current_count > $monthly_avg * 1.5) {
            $status = 'Above average';
        } elseif ($current_count == 0) {
            $status = 'No occurrences';
        }

        return [
            'count' => $count,
            'monthly_avg' => $monthly_avg,
            'current_month' => $current_count,
            'status' => $status,
            'display' => "{$current_count} times this month ({$monthly_avg}/month avg)"
        ];
    }

    /**
     * Calculate service impact
     */
    private function calculateServiceImpact($hostid) {
        if (!$hostid) {
            return [
                'level' => 'Unknown',
                'services_count' => 0,
                'display' => 'No host data available'
            ];
        }

        // Get services that depend on this host
        $services = API::Service()->get([
            'output' => ['serviceid', 'name', 'status', 'algorithm'],
            'selectParents' => ['serviceid', 'name'],
            'selectChildren' => ['serviceid', 'name'],
            'selectProblemTags' => ['tag', 'value']
        ]);

        // Simple heuristic: check if host is in critical services
        $critical_services = 0;
        $total_services = 0;

        foreach ($services as $service) {
            if ($service['status'] > 0) { // Service has problems
                $total_services++;
                if ($service['status'] >= 1) { // High/Disaster severity
                    $critical_services++;
                }
            }
        }

        $level = 'Low';
        if ($critical_services > 2) {
            $level = 'High';
        } elseif ($critical_services > 0) {
            $level = 'Medium';
        }

        return [
            'level' => $level,
            'services_count' => $critical_services,
            'total_services' => $total_services,
            'display' => $critical_services > 0 ? "{$critical_services} critical services affected" : "No critical services affected"
        ];
    }

    /**
     * Calculate SLA breach risk
     */
    private function calculateSLARisk($triggerid, $event) {
        // Simple calculation based on current duration vs MTTR
        $mttr_data = $this->calculateMTTR($triggerid);

        if (!isset($event['clock']) || $mttr_data['value'] === 'N/A') {
            return [
                'percentage' => 0,
                'risk_level' => 'Unknown',
                'display' => 'Unable to calculate'
            ];
        }

        $current_duration = time() - $event['clock'];
        $avg_resolution_time = $mttr_data['value'];

        if ($avg_resolution_time <= 0) {
            $risk_percentage = 0;
        } else {
            $risk_percentage = min(100, round(($current_duration / $avg_resolution_time) * 50));
        }

        $risk_level = 'Low';
        if ($risk_percentage > 75) {
            $risk_level = 'High';
        } elseif ($risk_percentage > 40) {
            $risk_level = 'Medium';
        }

        return [
            'percentage' => $risk_percentage,
            'risk_level' => $risk_level,
            'display' => "{$risk_percentage}% probability"
        ];
    }

    /**
     * Calculate historical patterns
     */
    private function calculateHistoricalPatterns($triggerid) {
        if (!$triggerid) {
            return [
                'frequency' => 'No data',
                'peak_time' => 'No data',
                'trend' => 'No data'
            ];
        }

        $time_from = time() - (90 * 24 * 60 * 60); // 90 days ago

        $events = API::Event()->get([
            'output' => ['eventid', 'clock'],
            'source' => 0,
            'object' => 0,
            'objectids' => [$triggerid],
            'time_from' => $time_from,
            'value' => 1
        ]);

        // Analyze time patterns
        $hour_counts = array_fill(0, 24, 0);
        $weekday_counts = array_fill(0, 7, 0);

        foreach ($events as $event) {
            $hour = (int)date('H', $event['clock']);
            $weekday = (int)date('w', $event['clock']);
            $hour_counts[$hour]++;
            $weekday_counts[$weekday]++;
        }

        // Find peak hours
        $peak_hour = array_search(max($hour_counts), $hour_counts);
        $peak_hour_end = ($peak_hour + 2) % 24;

        // Calculate trend (compare last 30 days vs previous 60 days)
        $recent_time = time() - (30 * 24 * 60 * 60);
        $recent_events = array_filter($events, function($e) use ($recent_time) {
            return $e['clock'] >= $recent_time;
        });

        $recent_count = count($recent_events);
        $older_count = count($events) - $recent_count;
        $trend_percentage = $older_count > 0 ? round((($recent_count - $older_count) / $older_count) * 100) : 0;

        return [
            'frequency' => count($events) . ' times in 90 days',
            'peak_time' => sprintf('%02d:00-%02d:00 hours', $peak_hour, $peak_hour_end),
            'trend' => $trend_percentage > 0 ? "Increasing +{$trend_percentage}%" : ($trend_percentage < 0 ? "Decreasing {$trend_percentage}%" : "Stable")
        ];
    }

    /**
     * Calculate performance anomalies
     */
    private function calculatePerformanceAnomalies($hostid, $event) {
        if (!$hostid || !isset($event['clock'])) {
            return [
                'cpu_anomaly' => 'No data',
                'memory_anomaly' => 'No data'
            ];
        }

        // Get CPU and Memory items for this host
        $items = API::Item()->get([
            'output' => ['itemid', 'name', 'key_', 'lastvalue'],
            'hostids' => [$hostid],
            'search' => [
                'key_' => ['cpu', 'memory', 'mem']
            ],
            'monitored' => true,
            'limit' => 10
        ]);

        $cpu_anomaly = 'No CPU data';
        $memory_anomaly = 'No Memory data';

        foreach ($items as $item) {
            if (strpos($item['key_'], 'cpu') !== false && strpos($item['key_'], 'util') !== false) {
                $current_value = (float)$item['lastvalue'];
                // Simple anomaly detection: >80% is anomaly
                if ($current_value > 80) {
                    $cpu_anomaly = "{$current_value}% (High)";
                } else {
                    $cpu_anomaly = "{$current_value}% (Normal)";
                }
                break;
            }
        }

        foreach ($items as $item) {
            if (strpos($item['key_'], 'memory') !== false || strpos($item['key_'], 'mem') !== false) {
                $current_value = (float)$item['lastvalue'];
                if ($current_value > 85) {
                    $memory_anomaly = "{$current_value}% (High)";
                } else {
                    $memory_anomaly = "{$current_value}% (Normal)";
                }
                break;
            }
        }

        return [
            'cpu_anomaly' => $cpu_anomaly,
            'memory_anomaly' => $memory_anomaly
        ];
    }

    /**
     * Calculate impact assessment data for correlation and dependency analysis
     */
    private function calculateImpactAssessmentData($triggerid, $hostid, $event) {
        $impact_data = [
            'dependency_impact' => $this->calculateDependencyImpact($hostid, $event),
            'technical_metrics' => $this->calculateTechnicalMetrics($hostid, $event),
            'cascade_analysis' => $this->calculateCascadeAnalysis($hostid, $triggerid)
        ];

        return $impact_data;
    }

    /**
     * Calculate correlation analysis - problems that occur simultaneously
     */

    /**
     * Calculate dependency impact - services and infrastructure affected
     */
    private function calculateDependencyImpact($hostid, $event) {
        if (!$hostid) {
            return [
                'affected_services' => [],
                'infrastructure_impact' => 'Unknown',
                'dependency_chain' => []
            ];
        }

        // Get services that may be affected by this host
        $services = API::Service()->get([
            'output' => ['serviceid', 'name', 'status', 'algorithm', 'weight'],
            'selectParents' => ['serviceid', 'name', 'status'],
            'selectChildren' => ['serviceid', 'name', 'status'],
            'selectProblemTags' => ['tag', 'value'],
            'selectStatusRules' => ['type', 'limit_value', 'limit_status', 'new_status']
        ]);

        // Get host information to understand its role
        $host_info = API::Host()->get([
            'output' => ['hostid', 'name', 'status'],
            'hostids' => [$hostid],
            'selectHostGroups' => ['groupid', 'name'],
            'selectInterfaces' => ['type', 'main', 'ip', 'port'],
            'selectTags' => ['tag', 'value']
        ]);

        $host = $host_info[0] ?? null;

        $affected_services = [];
        $critical_services = 0;

        foreach ($services as $service) {
            // Simple heuristic: services with problems are potentially affected
            if ($service['status'] > 0) {
                $impact_level = 'Low';
                if ($service['status'] >= 4) {
                    $impact_level = 'Critical';
                    $critical_services++;
                } elseif ($service['status'] >= 2) {
                    $impact_level = 'High';
                }

                $affected_services[] = [
                    'name' => $service['name'],
                    'status' => $service['status'],
                    'impact_level' => $impact_level,
                    'parents_count' => count($service['parents'] ?? []),
                    'children_count' => count($service['children'] ?? [])
                ];
            }
        }

        // Calculate infrastructure impact
        $infrastructure_impact = 'Minimal';
        if ($critical_services > 2) {
            $infrastructure_impact = 'Severe';
        } elseif ($critical_services > 0 || count($affected_services) > 3) {
            $infrastructure_impact = 'Moderate';
        }

        // Build dependency chain
        $dependency_chain = [];
        if ($host) {
            $host_groups = array_column($host['hostGroups'] ?? [], 'name');
            foreach ($host_groups as $group_name) {
                if (strpos(strtolower($group_name), 'database') !== false) {
                    $dependency_chain[] = ['type' => 'Database Layer', 'impact' => 'High'];
                } elseif (strpos(strtolower($group_name), 'web') !== false) {
                    $dependency_chain[] = ['type' => 'Web Layer', 'impact' => 'Medium'];
                } elseif (strpos(strtolower($group_name), 'app') !== false) {
                    $dependency_chain[] = ['type' => 'Application Layer', 'impact' => 'Medium'];
                }
            }
        }

        return [
            'affected_services' => array_slice($affected_services, 0, 5), // Limit to top 5
            'infrastructure_impact' => $infrastructure_impact,
            'dependency_chain' => $dependency_chain,
            'critical_services_count' => $critical_services,
            'total_affected_count' => count($affected_services)
        ];
    }

    /**
     * Calculate technical metrics - only real technical data
     */
    private function calculateTechnicalMetrics($hostid, $event) {
        if (!$hostid || !isset($event['clock'])) {
            return [
                'host_availability' => 'Unknown',
                'service_type' => 'Unknown',
                'problem_duration' => 0
            ];
        }

        // Get host information
        $host_info = API::Host()->get([
            'output' => ['hostid', 'name', 'status', 'available'],
            'hostids' => [$hostid],
            'selectHostGroups' => ['name'],
            'selectInterfaces' => ['type', 'available']
        ]);

        $host = $host_info[0] ?? null;
        $host_groups = $host ? array_column($host['hostGroups'] ?? [], 'name') : [];

        // Determine service type based on host groups (technical classification only)
        $service_type = 'Unknown';
        $is_critical = false;

        foreach ($host_groups as $group_name) {
            $group_lower = strtolower($group_name);
            if (strpos($group_lower, 'production') !== false || strpos($group_lower, 'prod') !== false) {
                $service_type = 'Production';
                $is_critical = true;
            } elseif (strpos($group_lower, 'database') !== false || strpos($group_lower, 'db') !== false) {
                $service_type = 'Database';
                $is_critical = true;
            } elseif (strpos($group_lower, 'web') !== false) {
                $service_type = 'Web Service';
            } elseif (strpos($group_lower, 'application') !== false || strpos($group_lower, 'app') !== false) {
                $service_type = 'Application';
            }
        }

        // Calculate problem duration
        $problem_duration_seconds = time() - $event['clock'];
        $problem_duration_formatted = $this->formatTimeDifference($problem_duration_seconds);

        // Determine host availability based on interfaces
        $host_availability = 'Available';
        $interfaces = $host['interfaces'] ?? [];

        foreach ($interfaces as $interface) {
            if ($interface['available'] == 0) { // Interface unavailable
                $host_availability = 'Degraded';
                break;
            }
        }

        if (isset($host['available']) && $host['available'] == 0) {
            $host_availability = 'Unavailable';
        }

        return [
            'host_availability' => $host_availability,
            'service_type' => $service_type,
            'problem_duration' => $problem_duration_formatted,
            'is_critical_environment' => $is_critical,
            'interface_count' => count($interfaces),
            'host_groups_count' => count($host_groups)
        ];
    }

    /**
     * Calculate cascade analysis
     */
    private function calculateCascadeAnalysis($hostid, $triggerid) {
        if (!$hostid) {
            return [
                'risk_level' => 'Unknown',
                'potential_cascade_points' => []
            ];
        }

        // Get related hosts in the same groups
        $host_groups = API::HostGroup()->get([
            'output' => ['groupid', 'name'],
            'hostids' => [$hostid]
        ]);

        $cascade_points = [];
        $risk_level = 'Low';

        foreach ($host_groups as $group) {
            // Get other hosts in the same group
            $group_hosts = API::Host()->get([
                'output' => ['hostid', 'name', 'status'],
                'groupids' => [$group['groupid']],
                'filter' => ['status' => HOST_STATUS_MONITORED],
                'excludeSearch' => ['hostid' => [$hostid]]
            ]);

            if (count($group_hosts) > 5) {
                $cascade_points[] = [
                    'group_name' => $group['name'],
                    'hosts_at_risk' => count($group_hosts),
                    'risk_description' => 'High host density in group'
                ];

                if (count($group_hosts) > 10) {
                    $risk_level = 'High';
                } elseif ($risk_level !== 'High') {
                    $risk_level = 'Medium';
                }
            }
        }

        return [
            'risk_level' => $risk_level,
            'potential_cascade_points' => $cascade_points
        ];
    }

    /**
     * Format time difference in human readable format
     */
    private function formatTimeDifference($seconds) {
        if ($seconds < 60) {
            return $seconds . 's';
        } elseif ($seconds < 3600) {
            return round($seconds / 60) . 'min';
        } else {
            return round($seconds / 3600, 1) . 'h';
        }
    }

    /**
     * Calculate advanced correlation analysis similar to Guru module
     */

    /**
     * Calculate advanced correlation analysis with tags, groups and cascade visualization (LEGACY)
     */

    /**
     * Analyze correlations based on tags
     */

    /**
     * Analyze correlations based on host groups
     */

    /**
     * Create timeline cascade data for visualization
     */
    private function createTimelineCascadeData($events, $event_time) {
        $timeline_data = [];
        $severity_colors = [
            5 => '#d32f2f', // Disaster
            4 => '#f57c00', // High
            3 => '#fbc02d', // Average
            2 => '#689f38', // Warning
            1 => '#1976d2'  // Information
        ];

        foreach ($events as $event) {
            $time_offset = $event['clock'] - $event_time;
            $timeline_data[] = [
                'event_name' => $event['name'],
                'event_id' => $event['eventid'],
                'time_offset' => $time_offset,
                'time_offset_display' => ($time_offset >= 0 ? '+' : '') . $this->formatTimeDifference(abs($time_offset)),
                'severity' => $event['severity'],
                'severity_color' => $severity_colors[$event['severity']] ?? '#666666',
                'timestamp' => $event['clock']
            ];
        }

        // Sort by time
        usort($timeline_data, function($a, $b) {
            return $a['timestamp'] - $b['timestamp'];
        });

        return $timeline_data;
    }

    /**
     * Create correlation graph data for D3.js visualization
     */

    /**
     * Enhanced tag correlation analysis inspired by Guru module
     * Now includes host information and improved confidence calculation
     */

    /**
     * Enhanced group correlation analysis inspired by Guru module
     * Now includes stricter host group validation and confidence scoring
     */

    /**
     * Simplified timeline cascade data
     */
    private function createTimelineCascadeDataSimplified($events, $event_time) {
        $timeline_data = [];
        $severity_colors = [
            5 => '#d32f2f', // Disaster
            4 => '#f57c00', // High
            3 => '#fbc02d', // Average
            2 => '#689f38', // Warning
            1 => '#1976d2'  // Information
        ];

        foreach (array_slice($events, 0, 20) as $event) { // Limit to 20 events
            $time_offset = $event['clock'] - $event_time;
            $timeline_data[] = [
                'event_name' => $event['name'],
                'event_id' => $event['eventid'],
                'time_offset' => $time_offset,
                'time_offset_display' => ($time_offset >= 0 ? '+' : '') . $this->formatTimeDifference(abs($time_offset)),
                'severity' => $event['severity'],
                'severity_color' => $severity_colors[$event['severity']] ?? '#666666',
                'timestamp' => $event['clock']
            ];
        }

        // Sort by time
        usort($timeline_data, function($a, $b) {
            return $a['timestamp'] - $b['timestamp'];
        });

        return $timeline_data;
    }

    /**
     * Create temporal cascade graph data with timeline ordering
     */

    /**
     * Create advanced timeline cascade similar to Grafana traces
     */
    private function createAdvancedTimelineCascade($events, $event_time, $current_trigger) {
        $timeline_data = [];
        $severity_colors = [
            5 => '#d32f2f', // Disaster
            4 => '#f57c00', // High
            3 => '#fbc02d', // Average
            2 => '#689f38', // Warning
            1 => '#1976d2', // Information
            0 => '#97AAB3'  // Not classified
        ];

        // Add root event first
        $timeline_data[] = [
            'event_name' => $current_trigger['description'] ?? 'Current Problem',
            'event_id' => 'current',
            'time_offset' => 0,
            'time_offset_display' => '00:00',
            'severity' => $current_trigger['priority'] ?? 0,
            'severity_color' => $severity_colors[$current_trigger['priority'] ?? 0],
            'timestamp' => $event_time,
            'is_root_cause' => false,
            'is_current_event' => true,
            'event_type' => 'current'
        ];

        foreach ($events as $event) {
            $time_offset = $event['clock'] - $event_time;
            $time_minutes = round(abs($time_offset) / 60, 1);

            // Determine event type based on timing
            $event_type = 'related';
            $is_root_cause = false;

            if ($time_offset < -300) { // 5+ minutes before
                $event_type = 'potential_root_cause';
                $is_root_cause = true;
            } elseif ($time_offset > 300) { // 5+ minutes after
                $event_type = 'cascade_effect';
            }

            $timeline_data[] = [
                'event_name' => $event['name'],
                'event_id' => $event['eventid'],
                'time_offset' => $time_offset,
                'time_offset_display' => ($time_offset >= 0 ? '+' : '-') . $time_minutes . 'm',
                'severity' => $event['severity'],
                'severity_color' => $severity_colors[$event['severity']] ?? '#666666',
                'timestamp' => $event['clock'],
                'is_root_cause' => $is_root_cause,
                'is_current_event' => false,
                'event_type' => $event_type
            ];
        }

        // Sort by time
        usort($timeline_data, function($a, $b) {
            return $a['timestamp'] - $b['timestamp'];
        });

        return $timeline_data;
    }

    /**
     * Create event timeline trace visualization with confidence calculation
     */
    private function createEventTimelineTrace($events, $event_time, $current_trigger = null, $current_host = null) {
        $trace_events = [];
        $total_duration = 0;

        // Calculate total timeline duration
        if (!empty($events)) {
            $first_event = min(array_column($events, 'clock'));
            $last_event = max(array_column($events, 'clock'));
            $total_duration = $last_event - $first_event;
        }

        // Get current trigger and host information for confidence calculation
        $current_trigger_info = $current_trigger ?: $this->getCurrentTriggerInfo();
        $current_host_info = $current_host ?: $this->getCurrentHostInfo();

        foreach ($events as $event) {
            $time_offset = $event['clock'] - $event_time;
            $position_percentage = $total_duration > 0 ?
                (($event['clock'] - min(array_column($events, 'clock'))) / $total_duration) * 100 : 50;

            // Calculate confidence percentage based on multiple criteria
            $confidence = $this->calculateEventConfidence($event, $event_time, $current_trigger_info, $current_host_info);

            $trace_events[] = [
                'event_id' => $event['eventid'],
                'event_name' => $event['name'],
                'timestamp' => $event['clock'],
                'time_offset' => $time_offset,
                'severity' => $event['severity'],
                'position_percentage' => $position_percentage,
                'duration' => $this->calculateEventDuration($event, $events),
                'has_resolution' => !empty($event['r_eventid']),
                'confidence_percentage' => $confidence,
                'confidence_level' => $this->getConfidenceLevel($confidence),
                'trace_metadata' => [
                    'trigger_id' => $event['objectid'],
                    'formatted_time' => date('H:i:s', $event['clock']),
                    'relative_time' => $this->formatTimeDifference(abs($time_offset)),
                    'confidence_details' => $this->getConfidenceDetails($event, $event_time, $current_trigger_info, $current_host_info)
                ]
            ];
        }

        // Sort by confidence (most confident first) and then by time
        usort($trace_events, function($a, $b) {
            if ($a['confidence_percentage'] != $b['confidence_percentage']) {
                return $b['confidence_percentage'] - $a['confidence_percentage'];
            }
            return abs($a['time_offset']) - abs($b['time_offset']);
        });

        return [
            'events' => $trace_events,
            'total_duration' => $total_duration,
            'timeline_start' => !empty($events) ? min(array_column($events, 'clock')) : $event_time,
            'timeline_end' => !empty($events) ? max(array_column($events, 'clock')) : $event_time
        ];
    }

    /**
     * Analyze cascade chain for root cause and effect relationships
     */
    private function analyzeCascadeChain($events, $event_time, $current_trigger) {
        $cascade_chain = [];
        $root_causes = [];
        $effects = [];

        foreach ($events as $event) {
            $time_offset = $event['clock'] - $event_time;

            // Classify events based on timing
            if ($time_offset < -300) { // Events 5+ minutes before
                $root_causes[] = [
                    'event_id' => $event['eventid'],
                    'event_name' => $event['name'],
                    'severity' => $event['severity'],
                    'time_before' => abs($time_offset),
                    'confidence' => $this->calculateRootCauseConfidence($event, $current_trigger),
                    'type' => 'root_cause'
                ];
            } elseif ($time_offset > 60) { // Events 1+ minute after
                $effects[] = [
                    'event_id' => $event['eventid'],
                    'event_name' => $event['name'],
                    'severity' => $event['severity'],
                    'time_after' => $time_offset,
                    'impact_level' => $this->calculateImpactLevel($event, $current_trigger),
                    'type' => 'cascade_effect'
                ];
            }
        }

        // Sort by confidence/impact
        usort($root_causes, function($a, $b) {
            return $b['confidence'] - $a['confidence'];
        });

        usort($effects, function($a, $b) {
            return $b['impact_level'] - $a['impact_level'];
        });

        return [
            'root_causes' => array_slice($root_causes, 0, 5),
            'cascade_effects' => array_slice($effects, 0, 10),
            'chain_strength' => $this->calculateChainStrength($root_causes, $effects),
            'primary_root_cause' => !empty($root_causes) ? $root_causes[0] : null
        ];
    }

    /**
     * Calculate event duration (if resolution event exists)
     */
    private function calculateEventDuration($event, $all_events) {
        if (empty($event['r_eventid'])) {
            return null;
        }

        foreach ($all_events as $potential_resolution) {
            if ($potential_resolution['eventid'] == $event['r_eventid']) {
                return $potential_resolution['clock'] - $event['clock'];
            }
        }

        return null;
    }

    /**
     * Calculate root cause confidence based on timing and severity
     */
    private function calculateRootCauseConfidence($event, $current_trigger) {
        $confidence = 50; // Base confidence

        // Higher severity = higher confidence
        $confidence += ($event['severity'] * 10);

        // Earlier events = higher confidence (but diminishing returns)
        $trigger_clock = isset($current_trigger['clock']) ? $current_trigger['clock'] : time();
        $time_factor = min(30, abs($event['clock'] - $trigger_clock) / 60);
        $confidence += $time_factor;

        return min(100, $confidence);
    }

    /**
     * Calculate impact level for cascade effects
     */
    private function calculateImpactLevel($event, $current_trigger) {
        $impact = $event['severity'] * 20; // Base impact from severity

        // Recent effects have higher impact
        $trigger_clock = isset($current_trigger['clock']) ? $current_trigger['clock'] : time();
        $time_factor = max(0, 100 - (abs($event['clock'] - $trigger_clock) / 3600));
        $impact += $time_factor;

        return min(100, $impact);
    }

    /**
     * Calculate overall chain strength
     */
    private function calculateChainStrength($root_causes, $effects) {
        $strength = 0;

        if (!empty($root_causes)) {
            $strength += (count($root_causes) * 15);
            $strength += ($root_causes[0]['confidence'] ?? 0) * 0.3;
        }

        if (!empty($effects)) {
            $strength += (count($effects) * 10);
        }

        return min(100, $strength);
    }

    /**
     * Calculate event confidence based on multiple criteria
     */
    private function calculateEventConfidence($event, $event_time, $current_trigger, $current_host) {
        $confidence = 0;
        $max_points = 100;

        // 1. Time window factor (30 minutes = max points, further = less points)
        $time_diff = abs($event['clock'] - $event_time);
        $time_window_30min = 30 * 60; // 30 minutes
        if ($time_diff <= $time_window_30min) {
            $time_points = 30 * (1 - ($time_diff / $time_window_30min));
            $confidence += $time_points;
        }

        // 2. Same host factor (25 points if same host)
        try {
            $event_trigger = API::Trigger()->get([
                'output' => ['triggerid'],
                'selectHosts' => ['hostid', 'name'],
                'triggerids' => [$event['objectid']],
                'limit' => 1
            ])[0] ?? null;

            if ($event_trigger && !empty($event_trigger['hosts'])) {
                $event_hostid = $event_trigger['hosts'][0]['hostid'];
                if ($current_host && $event_hostid == $current_host['hostid']) {
                    $confidence += 25;
                }
            }
        } catch (Exception $e) {
            // Ignore API errors for confidence calculation
        }

        // 3. Same host group factor (15 points)
        try {
            if ($event_trigger && !empty($event_trigger['hosts'])) {
                $event_host = API::Host()->get([
                    'output' => ['hostid'],
                    'selectHostGroups' => ['groupid'],
                    'hostids' => [$event_trigger['hosts'][0]['hostid']],
                    'limit' => 1
                ])[0] ?? null;

                if ($event_host && $current_host && !empty($event_host['hostgroups']) && !empty($current_host['hostgroups'])) {
                    $event_groups = array_column($event_host['hostgroups'], 'groupid');
                    $current_groups = array_column($current_host['hostgroups'], 'groupid');
                    $common_groups = array_intersect($event_groups, $current_groups);

                    if (!empty($common_groups)) {
                        $confidence += 15;
                    }
                }
            }
        } catch (Exception $e) {
            // Ignore API errors
        }

        // 4. Similar tags factor (20 points)
        try {
            $event_trigger_full = API::Trigger()->get([
                'output' => ['triggerid'],
                'selectTags' => 'extend',
                'triggerids' => [$event['objectid']],
                'limit' => 1
            ])[0] ?? null;

            if ($event_trigger_full && $current_trigger && !empty($event_trigger_full['tags']) && !empty($current_trigger['tags'])) {
                $event_tags = array_column($event_trigger_full['tags'], 'tag');
                $current_tags = array_column($current_trigger['tags'], 'tag');
                $common_tags = array_intersect($event_tags, $current_tags);

                if (!empty($common_tags)) {
                    $tag_factor = min(1, count($common_tags) / max(count($event_tags), count($current_tags)));
                    $confidence += 20 * $tag_factor;
                }
            }
        } catch (Exception $e) {
            // Ignore API errors
        }

        // 5. Severity similarity factor (10 points)
        if ($current_trigger && isset($current_trigger['priority']) && $event['severity'] == $current_trigger['priority']) {
            $confidence += 10;
        }

        return min(100, round($confidence));
    }

    /**
     * Get confidence level description
     */
    private function getConfidenceLevel($confidence) {
        if ($confidence >= 80) return 'Very High';
        if ($confidence >= 60) return 'High';
        if ($confidence >= 40) return 'Medium';
        if ($confidence >= 20) return 'Low';
        return 'Very Low';
    }

    /**
     * Get detailed confidence breakdown
     */
    private function getConfidenceDetails($event, $event_time, $current_trigger, $current_host) {
        $details = [];

        // Time factor
        $time_diff = abs($event['clock'] - $event_time);
        $minutes_diff = round($time_diff / 60);
        if ($minutes_diff <= 30) {
            $details[] = "Within 30min window (+{$minutes_diff}m)";
        } else {
            $details[] = "Outside optimal window ({$minutes_diff}m)";
        }

        // Host factor
        try {
            $event_trigger = API::Trigger()->get([
                'output' => ['triggerid'],
                'selectHosts' => ['hostid', 'name'],
                'triggerids' => [$event['objectid']],
                'limit' => 1
            ])[0] ?? null;

            if ($event_trigger && !empty($event_trigger['hosts']) && $current_host) {
                $event_hostid = $event_trigger['hosts'][0]['hostid'];
                if ($event_hostid == $current_host['hostid']) {
                    $details[] = "Same host";
                } else {
                    $details[] = "Different host";
                }
            }
        } catch (Exception $e) {
            $details[] = "Host comparison unavailable";
        }

        return implode(', ', $details);
    }

    /**
     * Get current trigger info for confidence calculation
     */
    private function getCurrentTriggerInfo() {
        // This should be set during the main execution
        return $this->current_trigger_cache ?? null;
    }

    /**
     * Get current host info for confidence calculation
     */
    private function getCurrentHostInfo() {
        // This should be set during the main execution
        return $this->current_host_cache ?? null;
    }
}