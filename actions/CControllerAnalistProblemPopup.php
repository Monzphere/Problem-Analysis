<?php declare(strict_types = 0);

namespace Modules\AnalistProblem\Actions;

use CController;
use CControllerResponseData;
use API;
use CArrayHelper;
use CSeverityHelper;
use CWebUser;
use CRoleHelper;

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

        $events = API::Event()->get([
            'output' => ['eventid', 'source', 'object', 'objectid', 'clock', 'ns', 'value', 'acknowledged', 'name', 'severity'],
            'eventids' => $eventid,
            'selectTags' => ['tag', 'value']
        ]);

        $event = $events ? $events[0] : [];

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

        $trigger = null;
        $actual_triggerid = $triggerid;

        if (!$actual_triggerid && $event && isset($event['objectid'])) {
            $actual_triggerid = $event['objectid'];
        }
        
        if ($actual_triggerid > 0) {
            $triggers = API::Trigger()->get([
                'output' => ['triggerid', 'description', 'expression', 'comments', 'priority'],
                'triggerids' => $actual_triggerid,
                'selectHosts' => ['hostid', 'host', 'name'],
                'selectItems' => ['itemid', 'hostid', 'name', 'key_'], 
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

        $host = null;
        $actual_hostid = $hostid;

        if (!$actual_hostid && $trigger && isset($trigger['hosts']) && !empty($trigger['hosts'])) {
            $actual_hostid = $trigger['hosts'][0]['hostid'];
            
        }
        
        if ($actual_hostid > 0) {
            $host = $this->getHostCardData($actual_hostid);
            
        } else {
            
        }

        $related_events = [];
        if ($actual_triggerid > 0) {
            $related_events = API::Event()->get([
                'output' => ['eventid', 'clock', 'value', 'acknowledged', 'name', 'severity'],
                'source' => 0, 
                'object' => 0, 
                'objectids' => $actual_triggerid,
                'sortfield' => 'clock',
                'sortorder' => 'DESC',
                'limit' => 15
            ]);

            $trigger_severity = $trigger && isset($trigger['priority']) ? (int) $trigger['priority'] : 0;
            $main_event_severity = isset($event['severity']) ? (int) $event['severity'] : 0;
            $last_problem_severity = 0;

            $events_chronological = array_reverse($related_events);
            foreach ($events_chronological as &$rel_event) {
                if ($rel_event['value'] == 1) {
                    
                    $last_problem_severity = (int) $rel_event['severity'];
                } else {
                    
                    $resolution_severity = $last_problem_severity > 0 ? $last_problem_severity : 
                                         ($main_event_severity > 0 ? $main_event_severity : $trigger_severity);
                    $rel_event['severity'] = $resolution_severity;
                }
            }
            unset($rel_event);

            $related_events = array_reverse($events_chronological);
        }

        $items = [];
        
        if ($trigger && $actual_triggerid > 0) {
            if (isset($trigger['items']) && !empty($trigger['items'])) {
                
                $trigger_itemids = array_column($trigger['items'], 'itemid');
                $unique_itemids = array_unique($trigger_itemids);

                $raw_items = API::Item()->get([
                    'output' => ['itemid', 'name', 'key_', 'hostid', 'value_type'],
                    'itemids' => $unique_itemids,
                    'monitored' => true,
                    'filter' => [
                        'value_type' => [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64] 
                    ]
                ]);

                $items_by_id = [];
                foreach ($raw_items as $item) {
                    $items_by_id[$item['itemid']] = $item;
                }

                $items = array_values($items_by_id);
            }
        }

        $analytics_data = $this->calculateAnalyticsData($actual_triggerid, $hostid, $event);

        $six_months_events = [];
        if ($actual_triggerid > 0) {
            $six_months_from = time() - (180 * 24 * 60 * 60); 
            $six_months_events = API::Event()->get([
                'output' => ['eventid', 'clock', 'value'],
                'source' => 0,
                'object' => 0,
                'objectids' => [$actual_triggerid],
                'time_from' => $six_months_from,
                'value' => 1, 
                'sortfield' => 'clock',
                'sortorder' => 'ASC'
            ]);
        }

        $monthly_comparison = [];
        if ($actual_triggerid > 0 && isset($event['clock'])) {
            $event_timestamp = $event['clock'];

            $current_month_start = mktime(0, 0, 0, date('n', $event_timestamp), 1, date('Y', $event_timestamp));
            $current_month_end = mktime(23, 59, 59, date('n', $event_timestamp), date('t', $event_timestamp), date('Y', $event_timestamp));
            
            $prev_month_start = mktime(0, 0, 0, date('n', $event_timestamp) - 1, 1, date('Y', $event_timestamp));
            $prev_month_end = mktime(23, 59, 59, date('n', $event_timestamp) - 1, date('t', $prev_month_start), date('Y', $prev_month_start));

            if (date('n', $event_timestamp) == 1) {
                $prev_month_start = mktime(0, 0, 0, 12, 1, date('Y', $event_timestamp) - 1);
                $prev_month_end = mktime(23, 59, 59, 12, 31, date('Y', $event_timestamp) - 1);
            }

            $current_month_events = API::Event()->get([
                'output' => ['eventid', 'clock', 'value', 'severity'],
                'source' => 0,
                'object' => 0,
                'objectids' => $actual_triggerid,
                'time_from' => $current_month_start,
                'time_till' => $current_month_end,
                'value' => 1 
            ]);

            $prev_month_events = API::Event()->get([
                'output' => ['eventid', 'clock', 'value', 'severity'],
                'source' => 0,
                'object' => 0,
                'objectids' => $actual_triggerid,
                'time_from' => $prev_month_start,
                'time_till' => $prev_month_end,
                'value' => 1 
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

            if ($monthly_comparison['previous_month']['count'] > 0) {
                $change = (($monthly_comparison['current_month']['count'] - $monthly_comparison['previous_month']['count']) / $monthly_comparison['previous_month']['count']) * 100;
                $monthly_comparison['change_percentage'] = round($change, 1);
            } else {
                $monthly_comparison['change_percentage'] = $monthly_comparison['current_month']['count'] > 0 ? 100 : 0;
            }
        }

        $system_metrics = [];
        if ($host && isset($event['clock']) && isset($host['interfaces'])) {
            $system_metrics = $this->getSystemMetricsAtEventTime($host, $event['clock']);
        }

        $maintenances = [];
        if ($host && $actual_hostid > 0 && CWebUser::checkAccess(CRoleHelper::UI_CONFIGURATION_MAINTENANCE)) {
            try {
                $maintenances = API::Maintenance()->get([
                    'output' => ['maintenanceid', 'name', 'description', 'active_since', 'active_till', 'maintenance_type'],
                    'hostids' => [$actual_hostid],
                    'selectTimeperiods' => ['timeperiod_type', 'period', 'every', 'dayofweek', 'day', 'month', 'start_time', 'start_date'],
                    'preservekeys' => true
                ]);
                CArrayHelper::sort($maintenances, ['active_since' => ZBX_SORT_DOWN]);
                $maintenances = array_values($maintenances);
            } catch (\Exception $e) {
                $maintenances = [];
            }
        }

        $data = [
            'event' => $event,
            'trigger' => $trigger,
            'host' => $host,
            'related_events' => $related_events,
            'six_months_events' => $six_months_events,
            'items' => $items,
            'monthly_comparison' => $monthly_comparison,
            'system_metrics' => $system_metrics,
            'analytics_data' => $analytics_data,
            'maintenances' => $maintenances,
            'user' => [
                'debug_mode' => $this->getDebugMode()
            ]
        ];

        $this->setResponse(new CControllerResponseData($data));
    }

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

        $options['selectGraphs'] = API_OUTPUT_COUNT;
        $options['selectHttpTests'] = API_OUTPUT_COUNT;

        $inventory_fields = getHostInventories();
        $options['selectInventory'] = array_column($inventory_fields, 'db_field');

        $db_hosts = API::Host()->get($options);

        if (!$db_hosts) {
            return null;
        }

        $host = $db_hosts[0];

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

        CArrayHelper::sort($host['hostgroups'], ['name']);

        $db_items_count = API::Item()->get([
            'countOutput' => true,
            'hostids' => [$host['hostid']],
            'webitems' => true,
            'monitored' => true
        ]);

        $host['dashboard_count'] = API::HostDashboard()->get([
            'countOutput' => true,
            'hostids' => $host['hostid']
        ]);

        $host['item_count'] = $db_items_count;
        $host['graph_count'] = $host['graphs'];
        $host['web_scenario_count'] = $host['httpTests'];

        unset($host['graphs'], $host['httpTests']);

        $interface_enabled_items_count = getEnabledItemsCountByInterfaceIds(
            array_column($host['interfaces'], 'interfaceid')
        );

        foreach ($host['interfaces'] as &$interface) {
            $interfaceid = $interface['interfaceid'];
            $interface['has_enabled_items'] = array_key_exists($interfaceid, $interface_enabled_items_count)
                && $interface_enabled_items_count[$interfaceid] > 0;
        }
        unset($interface);

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

        $monitoring_type = $this->getHostMonitoringType($interfaces);
        
        $metrics = [
            'type' => $monitoring_type,
            'available' => false,
            'categories' => []
        ];

        if ($monitoring_type !== 'agent') {
            return $metrics;
        }
        
        try {
            
            $metrics_list = $this->getEssentialSystemMetrics($hostid);
            $metrics['categories'] = $metrics_list;
            
            $metrics['available'] = !empty($metrics_list);
            
        } catch (Exception $e) {
            error_log('Error getting system metrics: ' . $e->getMessage());
        }
        
        return $metrics;
    }

    private function getHostMonitoringType($interfaces) {
        if (empty($interfaces)) {
            return 'unknown';
        }

        foreach ($interfaces as $interface) {
            if ($interface['main'] == 1) {
                switch ($interface['type']) {
                    case 1: return 'agent';    
                    case 2: return 'snmp';     
                    case 3: return 'ipmi';     
                    case 4: return 'jmx';      
                    default: return 'unknown';
                }
            }
        }
        
        return 'unknown';
    }

    private function getEssentialSystemMetrics($hostid) {
        $metrics = [];

        $metric_patterns = [
            'CPU' => ['system.cpu.util', 'system.cpu.utilization'],
            'Memory' => ['vm.memory.util', 'vm.memory.size[available]', 'vm.memory.size[total]'],
            'Load' => ['system.cpu.load[percpu,avg1]', 'system.cpu.load[,avg5]', 'system.cpu.load'],
            'Disk' => ['vfs.fs.size[/,pused]', 'vfs.fs.used[/]', 'vfs.fs.size[/,used]']
        ];

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
                    break; 
                }
            }
            
            if ($found_item) {
                
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

    private function calculateMTTR($triggerid) {
        if (!$triggerid) {
            return [
                'value' => 'N/A',
                'status' => 'No data',
                'display' => 'No historical data available'
            ];
        }

        $time_from = time() - (90 * 24 * 60 * 60); 

        $problem_events = API::Event()->get([
            'output' => ['eventid', 'clock', 'value'],
            'source' => 0,
            'object' => 0,
            'objectids' => [$triggerid],
            'time_from' => $time_from,
            'value' => [0, 1], 
            'sortfield' => 'clock',
            'sortorder' => 'ASC'
        ]);

        $resolution_times = [];
        $current_problem_start = null;

        foreach ($problem_events as $event) {
            if ($event['value'] == 1) {
                
                $current_problem_start = $event['clock'];
            } elseif ($event['value'] == 0 && $current_problem_start) {
                
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

    private function calculateRecurrence($triggerid) {
        if (!$triggerid) {
            return [
                'count' => 0,
                'monthly_avg' => 0,
                'status' => 'No data',
                'display' => 'No historical data'
            ];
        }

        $time_from = time() - (90 * 24 * 60 * 60); 

        $problem_events = API::Event()->get([
            'output' => ['eventid', 'clock'],
            'source' => 0,
            'object' => 0,
            'objectids' => [$triggerid],
            'time_from' => $time_from,
            'value' => 1 
        ]);

        $count = count($problem_events);
        $monthly_avg = round($count / 3, 1);

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

    private function calculateServiceImpact($hostid) {
        if (!$hostid) {
            return [
                'level' => 'Unknown',
                'services_count' => 0,
                'display' => 'No host data available'
            ];
        }

        $services = API::Service()->get([
            'output' => ['serviceid', 'name', 'status', 'algorithm'],
            'selectParents' => ['serviceid', 'name'],
            'selectChildren' => ['serviceid', 'name'],
            'selectProblemTags' => ['tag', 'value']
        ]);

        $critical_services = 0;
        $total_services = 0;

        foreach ($services as $service) {
            if ($service['status'] > 0) { 
                $total_services++;
                if ($service['status'] >= 1) { 
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

    private function calculateSLARisk($triggerid, $event) {
        
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

    private function calculateHistoricalPatterns($triggerid) {
        if (!$triggerid) {
            return [
                'frequency' => 'No data',
                'peak_time' => 'No data',
                'trend' => 'No data'
            ];
        }

        $time_from = time() - (90 * 24 * 60 * 60); 

        $events = API::Event()->get([
            'output' => ['eventid', 'clock'],
            'source' => 0,
            'object' => 0,
            'objectids' => [$triggerid],
            'time_from' => $time_from,
            'value' => 1
        ]);

        $hour_counts = array_fill(0, 24, 0);
        $weekday_counts = array_fill(0, 7, 0);

        foreach ($events as $event) {
            $hour = (int)date('H', $event['clock']);
            $weekday = (int)date('w', $event['clock']);
            $hour_counts[$hour]++;
            $weekday_counts[$weekday]++;
        }

        $peak_hour = array_search(max($hour_counts), $hour_counts);
        $peak_hour_end = ($peak_hour + 2) % 24;

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

    private function calculatePerformanceAnomalies($hostid, $event) {
        if (!$hostid || !isset($event['clock'])) {
            return [
                'cpu_anomaly' => 'No data',
                'memory_anomaly' => 'No data'
            ];
        }

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

    private function calculateImpactAssessmentData($triggerid, $hostid, $event) {
        $impact_data = [
            'dependency_impact' => $this->calculateDependencyImpact($hostid, $event),
            'technical_metrics' => $this->calculateTechnicalMetrics($hostid, $event),
            'cascade_analysis' => $this->calculateCascadeAnalysis($hostid, $triggerid)
        ];

        return $impact_data;
    }

    private function calculateDependencyImpact($hostid, $event) {
        if (!$hostid) {
            return [
                'affected_services' => [],
                'infrastructure_impact' => 'Unknown',
                'dependency_chain' => []
            ];
        }

        $services = API::Service()->get([
            'output' => ['serviceid', 'name', 'status', 'algorithm', 'weight'],
            'selectParents' => ['serviceid', 'name', 'status'],
            'selectChildren' => ['serviceid', 'name', 'status'],
            'selectProblemTags' => ['tag', 'value'],
            'selectStatusRules' => ['type', 'limit_value', 'limit_status', 'new_status']
        ]);

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

        $infrastructure_impact = 'Minimal';
        if ($critical_services > 2) {
            $infrastructure_impact = 'Severe';
        } elseif ($critical_services > 0 || count($affected_services) > 3) {
            $infrastructure_impact = 'Moderate';
        }

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
            'affected_services' => array_slice($affected_services, 0, 5), 
            'infrastructure_impact' => $infrastructure_impact,
            'dependency_chain' => $dependency_chain,
            'critical_services_count' => $critical_services,
            'total_affected_count' => count($affected_services)
        ];
    }

    private function calculateTechnicalMetrics($hostid, $event) {
        if (!$hostid || !isset($event['clock'])) {
            return [
                'host_availability' => 'Unknown',
                'service_type' => 'Unknown',
                'problem_duration' => 0
            ];
        }

        $host_info = API::Host()->get([
            'output' => ['hostid', 'name', 'status', 'available'],
            'hostids' => [$hostid],
            'selectHostGroups' => ['name'],
            'selectInterfaces' => ['type', 'available']
        ]);

        $host = $host_info[0] ?? null;
        $host_groups = $host ? array_column($host['hostGroups'] ?? [], 'name') : [];

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

        $problem_duration_seconds = time() - $event['clock'];
        $problem_duration_formatted = $this->formatTimeDifference($problem_duration_seconds);

        $host_availability = 'Available';
        $interfaces = $host['interfaces'] ?? [];

        foreach ($interfaces as $interface) {
            if ($interface['available'] == 0) { 
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

    private function calculateCascadeAnalysis($hostid, $triggerid) {
        if (!$hostid) {
            return [
                'risk_level' => 'Unknown',
                'potential_cascade_points' => []
            ];
        }

        $host_groups = API::HostGroup()->get([
            'output' => ['groupid', 'name'],
            'hostids' => [$hostid]
        ]);

        $cascade_points = [];
        $risk_level = 'Low';

        foreach ($host_groups as $group) {
            
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

    private function formatTimeDifference($seconds) {
        if ($seconds < 60) {
            return $seconds . 's';
        } elseif ($seconds < 3600) {
            return round($seconds / 60) . 'min';
        } else {
            return round($seconds / 3600, 1) . 'h';
        }
    }

    private function createTimelineCascadeData($events, $event_time) {
        $timeline_data = [];
        $severity_colors = [
            5 => '#d32f2f', 
            4 => '#f57c00', 
            3 => '#fbc02d', 
            2 => '#689f38', 
            1 => '#1976d2'  
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

        usort($timeline_data, function($a, $b) {
            return $a['timestamp'] - $b['timestamp'];
        });

        return $timeline_data;
    }

    private function createTimelineCascadeDataSimplified($events, $event_time) {
        $timeline_data = [];
        $severity_colors = [
            5 => '#d32f2f', 
            4 => '#f57c00', 
            3 => '#fbc02d', 
            2 => '#689f38', 
            1 => '#1976d2'  
        ];

        foreach (array_slice($events, 0, 20) as $event) { 
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

        usort($timeline_data, function($a, $b) {
            return $a['timestamp'] - $b['timestamp'];
        });

        return $timeline_data;
    }

    private function createAdvancedTimelineCascade($events, $event_time, $current_trigger) {
        $timeline_data = [];
        $severity_colors = [
            5 => '#d32f2f', 
            4 => '#f57c00', 
            3 => '#fbc02d', 
            2 => '#689f38', 
            1 => '#1976d2', 
            0 => '#97AAB3'  
        ];

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

            $event_type = 'related';
            $is_root_cause = false;

            if ($time_offset < -300) { 
                $event_type = 'potential_root_cause';
                $is_root_cause = true;
            } elseif ($time_offset > 300) { 
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

        usort($timeline_data, function($a, $b) {
            return $a['timestamp'] - $b['timestamp'];
        });

        return $timeline_data;
    }

    private function createEventTimelineTrace($events, $event_time, $current_trigger = null, $current_host = null) {
        $trace_events = [];
        $total_duration = 0;

        if (!empty($events)) {
            $first_event = min(array_column($events, 'clock'));
            $last_event = max(array_column($events, 'clock'));
            $total_duration = $last_event - $first_event;
        }

        $current_trigger_info = $current_trigger ?: $this->getCurrentTriggerInfo();
        $current_host_info = $current_host ?: $this->getCurrentHostInfo();

        foreach ($events as $event) {
            $time_offset = $event['clock'] - $event_time;
            $position_percentage = $total_duration > 0 ?
                (($event['clock'] - min(array_column($events, 'clock'))) / $total_duration) * 100 : 50;

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

    private function analyzeCascadeChain($events, $event_time, $current_trigger) {
        $cascade_chain = [];
        $root_causes = [];
        $effects = [];

        foreach ($events as $event) {
            $time_offset = $event['clock'] - $event_time;

            if ($time_offset < -300) { 
                $root_causes[] = [
                    'event_id' => $event['eventid'],
                    'event_name' => $event['name'],
                    'severity' => $event['severity'],
                    'time_before' => abs($time_offset),
                    'confidence' => $this->calculateRootCauseConfidence($event, $current_trigger),
                    'type' => 'root_cause'
                ];
            } elseif ($time_offset > 60) { 
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

    private function calculateRootCauseConfidence($event, $current_trigger) {
        $confidence = 50;

        $confidence += ($event['severity'] * 10);

        $trigger_clock = isset($current_trigger['clock']) ? $current_trigger['clock'] : time();
        $time_factor = min(30, abs($event['clock'] - $trigger_clock) / 60);
        $confidence += $time_factor;

        return min(100, $confidence);
    }

    private function calculateImpactLevel($event, $current_trigger) {
        $impact = $event['severity'] * 20;

        $trigger_clock = isset($current_trigger['clock']) ? $current_trigger['clock'] : time();
        $time_factor = max(0, 100 - (abs($event['clock'] - $trigger_clock) / 3600));
        $impact += $time_factor;

        return min(100, $impact);
    }

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

    private function calculateEventConfidence($event, $event_time, $current_trigger, $current_host) {
        $confidence = 0;
        $max_points = 100;

        $time_diff = abs($event['clock'] - $event_time);
        $time_window_30min = 30 * 60; 
        if ($time_diff <= $time_window_30min) {
            $time_points = 30 * (1 - ($time_diff / $time_window_30min));
            $confidence += $time_points;
        }

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
            
        }

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
            
        }

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
            
        }

        if ($current_trigger && isset($current_trigger['priority']) && $event['severity'] == $current_trigger['priority']) {
            $confidence += 10;
        }

        return min(100, round($confidence));
    }

    private function getConfidenceLevel($confidence) {
        if ($confidence >= 80) return 'Very High';
        if ($confidence >= 60) return 'High';
        if ($confidence >= 40) return 'Medium';
        if ($confidence >= 20) return 'Low';
        return 'Very Low';
    }

    private function getConfidenceDetails($event, $event_time, $current_trigger, $current_host) {
        $details = [];

        $time_diff = abs($event['clock'] - $event_time);
        $minutes_diff = round($time_diff / 60);
        if ($minutes_diff <= 30) {
            $details[] = "Within 30min window (+{$minutes_diff}m)";
        } else {
            $details[] = "Outside optimal window ({$minutes_diff}m)";
        }

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

    private function getCurrentTriggerInfo() {
        
        return $this->current_trigger_cache ?? null;
    }

    private function getCurrentHostInfo() {
        
        return $this->current_host_cache ?? null;
    }
}