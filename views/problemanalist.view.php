<?php declare(strict_types = 0);


// Include required files for event functions
require_once dirname(__FILE__).'/../../../include/events.inc.php';
require_once dirname(__FILE__).'/../../../include/actions.inc.php';
require_once dirname(__FILE__).'/../../../include/users.inc.php';
$this->addJsFile('layout.mode.js');
$this->addJsFile('gtlc.js');
$this->addJsFile('class.calendar.js');

// CSS for Event Cascade Timeline is now integrated into theme files

/**
 * @var CView $this
 */

$event = $data['event'] ?? [];
$trigger = $data['trigger'] ?? null;
$host = $data['host'] ?? null;
$related_events = $data['related_events'] ?? [];
$items = $data['items'] ?? [];
$monthly_comparison = $data['monthly_comparison'] ?? [];
$system_metrics = $data['system_metrics'] ?? [];

// Format timestamps
$event_time = isset($event['clock']) ? zbx_date2str(DATE_TIME_FORMAT_SECONDS, $event['clock']) : '';
$event_date = isset($event['clock']) ? zbx_date2str('Y-m-d', $event['clock']) : '';
$time_ago = isset($event['clock']) ? zbx_date2age($event['clock']) : '';

// Get severity info
$severity = isset($event['severity']) ? (int) $event['severity'] : 0;
$severity_name = CSeverityHelper::getName($severity);
$severity_color = CSeverityHelper::getColor($severity);

/**
 * Create essential metrics table for Zabbix Agent hosts
 */
function createEssentialMetricsTable($metrics) {
    $table = new CTableInfo();
    $table->setHeader([_('Metric'), _('Last Value')]);
    
    if (empty($metrics)) {
        $no_data_row = new CRow([
            new CCol(_('No system metrics available'), null, 2)
        ]);
        $no_data_row->addClass('system-metrics-more');
        $table->addRow($no_data_row);
        return $table;
    }
    
    foreach ($metrics as $metric) {
        $last_value = $metric['last_value'];
        $units = $metric['units'] ?? '';
        
        // Format last value with units
        if (is_numeric($last_value)) {
            // Smart formatting based on value size
            if ($last_value > 1000000000) {
                $value_display = number_format($last_value / 1000000000, 1) . 'G ' . $units;
            } elseif ($last_value > 1000000) {
                $value_display = number_format($last_value / 1000000, 1) . 'M ' . $units;
            } elseif ($last_value > 1000) {
                $value_display = number_format($last_value / 1000, 1) . 'K ' . $units;
            } else {
                $value_display = number_format($last_value, 2) . ' ' . $units;
            }
        } else {
            $value_display = $last_value . ' ' . $units;
        }
        
        $row = new CRow([
            $metric['name'],
            $value_display
        ]);
        
        $table->addRow($row);
    }
    
    return $table;
}

// Create tabs
$tabs = new CTabView();

// Event Overview data (used in TAB 2)
$overview_table = new CTableInfo();
$overview_table->setHeader([_('Property'), _('Value')]);

$overview_table->addRow([_('Event ID'), $event['eventid'] ?? 'N/A']);
$overview_table->addRow([_('Problem name'), $event['name'] ?? 'Unknown Problem']);
$overview_table->addRow([_('Host'), $host ? ($host['name'] ?? $host['host'] ?? 'Unknown') : 'N/A']);
$overview_table->addRow([_('Severity'), 
    (new CSpan($severity_name))
        ->addClass(CSeverityHelper::getStyle($severity))
        ->addClass('analist-severity-text')
        ->setAttribute('data-severity-color', $severity_color)
]);
$overview_table->addRow([_('Time'), $event_time ?: 'N/A']);
$overview_table->addRow([_('Date'), $event_date ?: 'N/A']);
$overview_table->addRow([_('Time ago'), $time_ago ?: 'N/A']);
$overview_table->addRow([_('Status'), ($event['acknowledged'] ?? 0) ? _('Acknowledged') : _('Problem')]);

if ($trigger) {
    if (isset($trigger['expression'])) {
        $overview_table->addRow([_('Trigger expression'), 
            (new CCol($trigger['expression']))->addClass(ZBX_STYLE_WORDBREAK)
        ]);
    }
    if (isset($trigger['comments']) && $trigger['comments']) {
        $overview_table->addRow([_('Comments'), 
            (new CCol($trigger['comments']))->addClass(ZBX_STYLE_WORDBREAK)
        ]);
    }
}

// System metrics section - only for Zabbix Agent hosts
$metrics_section = null;
if (!empty($system_metrics) && $system_metrics['available'] && $system_metrics['type'] === 'agent') {
    $metrics_section = new CDiv();
    $metrics_section->addClass('system-metrics-section');
    
    $metrics_section->addItem(new CTag('h4', false, _('Last value')));
    
    // Create simple metrics table
    $metrics_table = createEssentialMetricsTable($system_metrics['categories']);
    $metrics_section->addItem($metrics_table);
}

// Create overview container that includes both table and monthly comparison
$overview_container = new CDiv();

// Create a flexible container for Last Value and Monthly Comparison side by side
$top_sections_container = new CDiv();
$top_sections_container->addClass('overview-top-sections');
$top_sections_container->addStyle('display: flex; gap: 20px; margin-bottom: 15px;');

// Add system metrics (Last Value) to the left side
if ($metrics_section) {
    $metrics_section->addStyle('flex: 1; min-width: 300px;');
    $top_sections_container->addItem($metrics_section);
}

// Add monthly comparison section to the right side if data is available
if (!empty($monthly_comparison) && !empty($monthly_comparison['current_month'])) {
    // Monthly comparison section
    $comparison_section = new CDiv();
    $comparison_section->addClass('monthly-comparison-section');
    $comparison_section->addStyle('flex: 1; min-width: 250px;');
    $comparison_section->addItem(new CTag('h4', false, _('Monthly Comparison')));
    
    // Create comparison table
    $comparison_table = new CTableInfo();
    $comparison_table->setHeader([_('Period'), _('Incidents'), _('Change')]);
    
    $current_month = $monthly_comparison['current_month'];
    $previous_month = $monthly_comparison['previous_month'];
    $change_percentage = $monthly_comparison['change_percentage'] ?? 0;
    
    // Determine change color and status
    $change_color = '#666666'; // Neutral
    $change_status = 'No change';
    $change_text = '';

    if ($change_percentage > 0) {
        $change_color = '#e74c3c'; // Red for increase
        $change_status = 'Increased';
        $change_text = '+' . $change_percentage . '%';
    } elseif ($change_percentage < 0) {
        $change_color = '#27ae60'; // Green for decrease
        $change_status = 'Decreased';
        $change_text = $change_percentage . '%';
    } else {
        $change_text = '0%';
    }

    // Previous month row
    $comparison_table->addRow([
        $previous_month['name'],
        $previous_month['count'],
        '-'
    ]);

    // Current month row with Zabbix-style status indicator
    $change_badge = new CSpan($change_text);
    $change_badge->addStyle("font-weight: bold;");

    if ($change_percentage != 0) {
        $change_badge->setAttribute('title', $change_status . ' compared to previous month');
        $change_badge->addClass($change_percentage > 0 ? 'status-red' : 'status-green');
    }

    $comparison_table->addRow([
        $current_month['name'],
        $current_month['count'],
        $change_badge
    ]);
    
    $comparison_section->addItem($comparison_table);
    
    // Add summary message
    if ($change_percentage != 0) {
        $trend_message = '';
        if ($change_percentage > 0) {
            $trend_message = _('Incidents increased by') . ' ' . abs($change_percentage) . '% ' . _('compared to previous month');
        } else {
            $trend_message = _('Incidents decreased by') . ' ' . abs($change_percentage) . '% ' . _('compared to previous month');
        }
        
        $summary = new CDiv($trend_message);
        $summary->addStyle("font-style: italic; margin-top: 10px; font-size: 12px;");
        $comparison_section->addItem($summary);
    }
    
    $top_sections_container->addItem($comparison_section);
}

// Add the top sections container to overview if it has content
if ($metrics_section || (!empty($monthly_comparison) && !empty($monthly_comparison['current_month']))) {
    $overview_container->addItem($top_sections_container);
}

// Add the main overview table below the top sections
$overview_container->addItem($overview_table);

// TAB 1: Host Information - Primeiro tab
$host_div = new CDiv();

// Check if host data is available for debugging
if (!$host) {
    $host_div->addItem(new CDiv(_('Host information not available')));
}

if ($host && is_array($host)) {
    // Reorganized sections for better layout flow
    $primary_sections = [];      // Full-width sections at top
    $info_row_1 = [];           // Basic info row: Monitoring + Availability 
    $info_row_2 = [];           // Extended info row: Host groups + Monitored by
    $tags_row = [];             // Tags in separate row for better visibility
    $secondary_sections = [];    // Templates and Inventory in grid
    
    // Description first - full width if exists
    if (!empty($host['description'])) {
        $primary_sections[] = makeAnalistHostSectionDescription($host['description']);
    }
    
    // Info row 1: Core monitoring information side by side
    $info_row_1[] = makeAnalistHostSectionMonitoring($host['hostid'], $host['dashboard_count'] ?? 0, 
        $host['item_count'] ?? 0, $host['graph_count'] ?? 0, $host['web_scenario_count'] ?? 0
    );
    $info_row_1[] = makeAnalistHostSectionAvailability($host['interfaces'] ?? []);
    
    // Info row 2: Configuration information side by side  
    if (!empty($host['hostgroups'])) {
        $info_row_2[] = makeAnalistHostSectionHostGroups($host['hostgroups']);
    }
    $info_row_2[] = makeAnalistHostSectionMonitoredBy($host);
    
    // Tags in separate row for better readability
    if (!empty($host['tags'])) {
        $tags_row[] = makeAnalistHostSectionTags($host['tags']);
    }
    
    // Secondary sections: Templates and Inventory in grid layout
    if (!empty($host['templates'])) {
        $secondary_sections[] = makeAnalistHostSectionTemplates($host['templates']);
    }
    
    if (!empty($host['inventory'])) {
        $secondary_sections[] = makeAnalistHostSectionInventory($host['hostid'], $host['inventory'], []);
    }

    // Create organized layout containers with improved structure
    $sections_container = new CDiv();
    $sections_container->addClass('analisthost-sections-reorganized');
    
    // Add primary sections (full width at top)
    foreach ($primary_sections as $section) {
        $sections_container->addItem(
            (new CDiv($section))->addClass('analisthost-row analisthost-row-primary')
        );
    }
    
    // Add info row 1: Core monitoring info (side by side)
    if (!empty($info_row_1)) {
        $info_container_1 = new CDiv($info_row_1);
        $info_container_1->addClass('analisthost-row analisthost-row-info-primary');
        $sections_container->addItem($info_container_1);
    }
    
    // Add info row 2: Configuration info (side by side) 
    if (!empty($info_row_2)) {
        $info_container_2 = new CDiv($info_row_2);
        $info_container_2->addClass('analisthost-row analisthost-row-info-secondary');
        $sections_container->addItem($info_container_2);
    }
    
    // Add tags row (separate for better visibility)
    if (!empty($tags_row)) {
        $tags_container = new CDiv($tags_row);
        $tags_container->addClass('analisthost-row analisthost-row-tags');
        $sections_container->addItem($tags_container);
    }
    
    // Add secondary sections in grid layout
    if (!empty($secondary_sections)) {
        $secondary_container = new CDiv($secondary_sections);
        $secondary_container->addClass('analisthost-row analisthost-row-secondary-grid');
        $sections_container->addItem($secondary_container);
    }

    $body = (new CDiv([
        makeAnalistHostSectionsHeader($host),
        $sections_container
    ]))->addClass('analisthost-container');
    
    $host_div->addItem($body);
} else {
    $host_div->addItem(new CDiv(_('Host information not available')));
}

$tabs->addTab('host', _('Host Info'), $host_div);

// TAB 2: Overview
$tabs->addTab('overview', _('Overview'), $overview_container);

// TAB 3: Time Patterns
$time_patterns_div = new CDiv();

// Calculate hourly distribution
$hourly_data = [];
for ($h = 0; $h < 24; $h++) {
    $hourly_data[$h] = 0;
}

foreach ($related_events as $rel_event) {
    $hour = date('G', $rel_event['clock']);
    $hourly_data[(int)$hour]++;
}

// Calculate weekly distribution  
$weekdays = [_('Sunday'), _('Monday'), _('Tuesday'), _('Wednesday'), _('Thursday'), _('Friday'), _('Saturday')];
$weekly_data = [0, 0, 0, 0, 0, 0, 0];

// Generate hour labels using Zabbix localization
$hour_labels = [];
for ($h = 0; $h < 24; $h++) {
    // Use Zabbix's time formatting to get localized hour labels
    $timestamp = mktime($h, 0, 0, 1, 1, 2000); // Arbitrary date with specific hour
    
    // Try different formatting approaches to match Zabbix native behavior
    if (function_exists('zbx_date2str')) {
        $hour_str = zbx_date2str('g a', $timestamp); // 'g a' format: 12 am, 1 am, etc.
        $hour_labels[] = str_replace(' ', '', strtolower($hour_str)); // Remove space and lowercase: 12am, 1am, etc.
    } else {
        // Fallback if zbx_date2str not available
        $hour_str = date('g a', $timestamp);
        $hour_labels[] = str_replace(' ', '', strtolower($hour_str));
    }
}

foreach ($related_events as $rel_event) {
    $weekday = date('w', $rel_event['clock']);
    $weekly_data[(int)$weekday]++;
}

// Create containers for D3.js charts
$patterns_container = new CDiv();
$patterns_container->addClass('patterns-d3-container');

// Hourly pattern container
$hourly_container = new CDiv();
$hourly_container->addClass('pattern-chart-container');
$hourly_container->addItem(new CTag('h4', false, _('Hourly Pattern')));
$hourly_chart = new CDiv();
$hourly_chart->setId('hourly-pattern-chart');
$hourly_chart->addClass('pattern-chart');
$hourly_container->addItem($hourly_chart);

// Weekly pattern container
$weekly_container = new CDiv();
$weekly_container->addClass('pattern-chart-container');
$weekly_container->addItem(new CTag('h4', false, _('Weekly Pattern')));
$weekly_chart = new CDiv();
$weekly_chart->setId('weekly-pattern-chart');
$weekly_chart->addClass('pattern-chart');
$weekly_container->addItem($weekly_chart);

$patterns_container->addItem([$hourly_container, $weekly_container]);
$time_patterns_div->addItem($patterns_container);

$tabs->addTab('patterns', _('Time Patterns'), $time_patterns_div);

// TAB 4: Graphs - Fixed time period (1 hour before incident to now)
$graphs_div = new CDiv();

if ($items && isset($event['clock'])) {
    // Calculate fixed time period: 1 hour before event to now
    $event_timestamp = $event['clock'];
    $from_timestamp = $event_timestamp - 3600; // 1 hour before event
    $from_time = date('Y-m-d H:i:s', $from_timestamp);
    $to_time = 'now';
    
    // Create charts container
    $charts_container = new CDiv();
    $charts_container->addClass('charts-container');
    
    // Add period info with consolidated chart information
    $items_count = count($items);
    $period_info = new CDiv(
        $items_count == 1 
            ? sprintf(_('Showing data from %s to now (1 hour before incident)'), $from_time)
            : sprintf(_('Showing data from %s to now (1 hour before incident)'), $from_time, $items_count)
    );
    $period_info->addClass('period-info');
    $charts_container->addItem($period_info);
    
    // Create a single consolidated chart with all items
    $processed_items = [];
    $unique_itemids = [];
    $item_names = [];
    
    // First pass: collect unique itemids and names
    foreach ($items as $item) {
        if (!isset($processed_items[$item['itemid']])) {
            $processed_items[$item['itemid']] = true;
            $unique_itemids[] = $item['itemid'];
            $item_names[] = $item['name'];
        }
    }
    
    if (!empty($unique_itemids)) {
        $chart_div = new CDiv();
        $chart_div->addClass('chart-item');
        
        // Chart title showing all items
        $title_text = count($item_names) == 1 
            ? $item_names[0] 
            : _('Combined metrics') . ' (' . count($item_names) . ' ' . _('items') . ')';
        $title = new CTag('h5', false, $title_text);
        $title->addClass('chart-title');
        $chart_div->addItem($title);
        
        // Build consolidated chart URL with all itemids
        $base_params = [
            'from' => $from_time,
            'to' => $to_time,
            'type' => 0,
            'resolve_macros' => 1,
            'width' => 800,
            'height' => 300,
            '_' => time()
        ];
        
        // Start with base parameters
        $chart_url = 'chart.php?' . http_build_query($base_params);
        
        // Add all itemids as separate parameters manually to ensure correct format
        foreach ($unique_itemids as $itemid) {
            $chart_url .= '&itemids[]=' . urlencode($itemid);
        }
        
        // Show item details if multiple items
        if (count($item_names) > 1) {
            $items_list = new CDiv();
            $items_list->addClass('chart-items-list');
            $items_list->addItem(_('Items') . ': ' . implode(', ', $item_names));
            $chart_div->addItem($items_list);
        }
        
        // Chart image
        $chart_img = new CTag('img', true);
        $chart_img->setAttribute('src', $chart_url);
        $chart_img->setAttribute('alt', $title_text);
        $chart_img->setAttribute('title', _('Consolidated graph with') . ' ' . count($unique_itemids) . ' ' . _('items'));
        $chart_img->addClass('chart-image');
        $chart_div->addItem($chart_img);
        
        $charts_container->addItem($chart_div);
    }
    
    $graphs_div->addItem($charts_container);
    
} elseif ($items && !isset($event['clock'])) {
    $graphs_div->addItem(new CDiv(_('Event timestamp not available for chart generation')));
} else {
    $graphs_div->addItem(new CDiv(_('No graph data available')));
}

$tabs->addTab('graphs', _('Graphs'), $graphs_div);

// TAB 5: Event Timeline
$timeline_div = new CDiv();

// Use Zabbix's built-in function to create the event list
$allowed = [
    'add_comments' => CWebUser::checkAccess(CRoleHelper::ACTIONS_ADD_PROBLEM_COMMENTS),
    'change_severity' => CWebUser::checkAccess(CRoleHelper::ACTIONS_CHANGE_SEVERITY),
    'acknowledge' => CWebUser::checkAccess(CRoleHelper::ACTIONS_ACKNOWLEDGE_PROBLEMS),
    'suppress_problems' => CWebUser::checkAccess(CRoleHelper::ACTIONS_SUPPRESS_PROBLEMS),
    'close' => CWebUser::checkAccess(CRoleHelper::ACTIONS_CLOSE_PROBLEMS) && 
                     isset($trigger['manual_close']) && $trigger['manual_close'] == ZBX_TRIGGER_MANUAL_CLOSE_ALLOWED,
    'rank_change' => CWebUser::checkAccess(CRoleHelper::ACTIONS_CHANGE_PROBLEM_RANKING)
];

// Create the event list table using the native function
$timeline_table = make_small_eventlist($event, $allowed);

// Add header
$timeline_div->addItem(new CTag('h4', false, _('Event list [previous 20]')));
$timeline_div->addItem($timeline_table);
$tabs->addTab('timeline', _('Timeline'), $timeline_div);

// TAB 6: Services 
$services_div = new CDiv();

// Loading indicator
$loading_div = new CDiv(_('Loading services...'));
$loading_div->addClass('services-loading');
$loading_div->addStyle('text-align: center; padding: 20px; font-style: italic;');
$services_div->addItem($loading_div);

// Container for services tree
$services_tree_container = new CDiv();
$services_tree_container->setAttribute('id', 'services-tree-container');
$services_tree_container->addStyle('min-height: 100px;');
$services_div->addItem($services_tree_container);

$tabs->addTab('services', _('Services'), $services_div);

// TAB 7: Analytics & History
$analytics_div = new CDiv();

// Analytics container with similar structure to other tabs
$analytics_container = new CDiv();
$analytics_container->addClass('analytics-container');

// SEÇÃO 1: Resolution & Impact Analytics (Consolidada)
$main_analytics_section = new CDiv();
$main_analytics_section->addClass('resolution-impact-section');
$main_analytics_section->addItem(new CTag('h4', false, _('Resolution & Impact Analytics')));

$main_table = new CTableInfo();
$main_table->setHeader([_('Category'), _('Metric'), _('Current'), _('Status/Level')]);

// Get real analytics data
$analytics = $data['analytics_data'] ?? [];

$main_analytics = [
    [
        'category' => 'Resolution',
        'metric' => _('MTTR (Mean Time To Resolution)'),
        'current' => $analytics['mttr']['display'] ?? 'No data available',
        'status' => '⏱ ' . ($analytics['mttr']['status'] ?? 'Unknown'),
        'tooltip' => _('Average time it takes to resolve this type of problem based on historical data')
    ],
    [
        'category' => 'Resolution',
        'metric' => _('Recurrence Rate'),
        'current' => $analytics['recurrence']['display'] ?? 'No data available',
        'status' => ($analytics['recurrence']['status'] === 'Above average' ? 'Warning: ' : '') . ($analytics['recurrence']['status'] ?? 'Unknown'),
        'tooltip' => _('How frequently this problem occurs compared to historical patterns')
    ],
    [
        'category' => 'Impact',
        'metric' => _('Service Criticality'),
        'current' => $analytics['service_impact']['display'] ?? 'No data available',
        'status' => $analytics['service_impact']['level'] ?? 'Unknown',
        'tooltip' => _('Level of criticality based on affected services and their business importance')
    ],
    [
        'category' => 'Impact',
        'metric' => _('SLA Breach Risk'),
        'current' => $analytics['sla_risk']['display'] ?? 'No data available',
        'status' => '✓ ' . ($analytics['sla_risk']['risk_level'] ?? 'Unknown'),
        'tooltip' => _('Probability of SLA breach if problem continues at current resolution pace')
    ]
];

foreach ($main_analytics as $analytic) {
    $category_span = (new CSpan($analytic['category']))
        ->addStyle('font-weight: bold; color: #666;');

    $metric_name = (new CSpan($analytic['metric']))
        ->setHint($analytic['tooltip']);

    $status_color = '';
    if (strpos($analytic['status'], 'Warning') !== false || strpos($analytic['status'], 'Above average') !== false) {
        $status_color = 'color: #ff9800; font-weight: bold;';
    } elseif (strpos($analytic['status'], 'Low') !== false || strpos($analytic['status'], 'Normal') !== false) {
        $status_color = 'color: #4caf50; font-weight: bold;';
    } elseif (strpos($analytic['status'], 'In Progress') !== false || strpos($analytic['status'], 'Monitoring') !== false) {
        $status_color = 'color: #2196f3; font-weight: bold;';
    } elseif (strpos($analytic['status'], 'High') !== false || strpos($analytic['status'], 'Critical') !== false) {
        $status_color = 'color: #f44336; font-weight: bold;';
    } elseif (strpos($analytic['status'], 'Medium') !== false) {
        $status_color = 'color: #ff9800; font-weight: bold;';
    } elseif (strpos($analytic['status'], 'Unknown') !== false || strpos($analytic['status'], 'No data') !== false) {
        $status_color = 'color: #666; font-style: italic;';
    } else {
        $status_color = 'font-weight: normal;';
    }

    $main_table->addRow([
        $category_span,
        $metric_name,
        $analytic['current'],
        (new CSpan($analytic['status']))->addStyle($status_color)
    ]);
}

$main_analytics_section->addItem($main_table);

// SEÇÃO 2: Historical Patterns & Performance
$secondary_analytics_section = new CDiv();
$secondary_analytics_section->addClass('patterns-performance-section');
$secondary_analytics_section->addItem(new CTag('h4', false, _('Historical Patterns & Performance Baseline')));

$secondary_table = new CTableInfo();
$secondary_table->setHeader([_('Type'), _('Metric'), _('Analysis'), _('Indicator')]);

$secondary_analytics = [
    [
        'type' => 'Pattern',
        'metric' => _('Occurrence Frequency'),
        'analysis' => $analytics['patterns']['frequency'] ?? 'No historical data',
        'indicator' => $analytics['patterns']['trend'] ?? 'Unknown',
        'tooltip' => _('How often this problem occurs compared to previous periods')
    ],
    [
        'type' => 'Pattern',
        'metric' => _('Peak Time Window'),
        'analysis' => $analytics['patterns']['peak_time'] ?? 'No pattern identified',
        'indicator' => 'Time Pattern',
        'tooltip' => _('Time periods when this problem is most likely to occur')
    ],
    [
        'type' => 'Performance',
        'metric' => _('CPU Usage Anomaly'),
        'analysis' => $analytics['performance_anomalies']['cpu_anomaly'] ?? 'No CPU data',
        'indicator' => (strpos($analytics['performance_anomalies']['cpu_anomaly'] ?? '', 'High') !== false) ? 'Critical' : 'Normal',
        'tooltip' => _('Current CPU usage compared to normal levels')
    ],
    [
        'type' => 'Performance',
        'metric' => _('Memory Usage Anomaly'),
        'analysis' => $analytics['performance_anomalies']['memory_anomaly'] ?? 'No Memory data',
        'indicator' => (strpos($analytics['performance_anomalies']['memory_anomaly'] ?? '', 'High') !== false) ? 'Critical' : 'Normal',
        'tooltip' => _('Current memory usage compared to normal levels')
    ]
];

foreach ($secondary_analytics as $analytic) {
    $type_span = (new CSpan($analytic['type']))
        ->addStyle('font-weight: bold; color: #666;');

    $metric_name = (new CSpan($analytic['metric']))
        ->setHint($analytic['tooltip']);

    $indicator_color = '';
    if (strpos($analytic['indicator'], 'Critical') !== false) {
        $indicator_color = 'color: #f44336; font-weight: bold;';
    } elseif (strpos($analytic['indicator'], 'Increasing') !== false) {
        $indicator_color = 'color: #ff9800; font-weight: bold;';
    } elseif (strpos($analytic['indicator'], 'Decreasing') !== false) {
        $indicator_color = 'color: #4caf50; font-weight: bold;';
    } elseif (strpos($analytic['indicator'], 'Stable') !== false || strpos($analytic['indicator'], 'Consistent') !== false || strpos($analytic['indicator'], 'Time Pattern') !== false) {
        $indicator_color = 'color: #2196f3; font-weight: bold;';
    } elseif (strpos($analytic['indicator'], 'Normal') !== false) {
        $indicator_color = 'color: #4caf50; font-weight: bold;';
    } elseif (strpos($analytic['indicator'], 'Unknown') !== false || strpos($analytic['indicator'], 'No data') !== false) {
        $indicator_color = 'color: #666; font-style: italic;';
    } else {
        $indicator_color = 'color: #333; font-weight: normal;';
    }

    $secondary_table->addRow([
        $type_span,
        $metric_name,
        $analytic['analysis'],
        (new CSpan($analytic['indicator']))->addStyle($indicator_color)
    ]);
}

$secondary_analytics_section->addItem($secondary_table);

// Add sections to analytics container
$analytics_container->addItem($main_analytics_section);
$analytics_container->addItem($secondary_analytics_section);

$analytics_div->addItem($analytics_container);

$tabs->addTab('analytics', _('Analytics & History'), $analytics_div);

// TAB 8: Impact Assessment
$impact_div = new CDiv();

// Impact Assessment container
$impact_container = new CDiv();
$impact_container->addClass('impact-container');

// Get real impact assessment data
$impact_data = $data['impact_assessment_data'] ?? [];

// SEÇÃO 1: Dependency Impact
$dependency_section = new CDiv();
$dependency_section->addClass('dependency-impact-section');
$dependency_section->addItem(new CTag('h4', false, _('Dependency Impact Analysis')));

$dependency_table = new CTableInfo();
$dependency_table->setHeader([_('Impact Category'), _('Details'), _('Status'), _('Impact Level')]);

$dependency_impact = $impact_data['dependency_impact'] ?? [];
$technical_metrics = $impact_data['technical_metrics'] ?? [];
$cascade_analysis = $impact_data['cascade_analysis'] ?? [];

$dependency_items = [
    [
        'category' => _('Infrastructure Impact'),
        'details' => $dependency_impact['infrastructure_impact'] ?? 'Unknown',
        'status' => ($dependency_impact['total_affected_count'] ?? 0) . ' services affected',
        'level' => $dependency_impact['infrastructure_impact'] ?? 'Unknown'
    ],
    [
        'category' => _('Host Availability'),
        'details' => $technical_metrics['service_type'] ?? 'Unknown',
        'status' => $technical_metrics['host_availability'] ?? 'Unknown',
        'level' => $technical_metrics['host_availability'] === 'Unavailable' ? 'High' :
                  ($technical_metrics['host_availability'] === 'Degraded' ? 'Medium' : 'Low')
    ],
    [
        'category' => _('Problem Duration'),
        'details' => $technical_metrics['problem_duration'] ?? 'Unknown',
        'status' => ($technical_metrics['is_critical_environment'] ?? false) ? 'Critical Environment' : 'Standard Environment',
        'level' => ($technical_metrics['is_critical_environment'] ?? false) ? 'High' : 'Medium'
    ],
    [
        'category' => _('Cascade Risk'),
        'details' => count($cascade_analysis['potential_cascade_points'] ?? []) . ' risk points identified',
        'status' => $cascade_analysis['risk_level'] ?? 'Unknown',
        'level' => $cascade_analysis['risk_level'] ?? 'Unknown'
    ]
];

foreach ($dependency_items as $item) {
    $level_color = '';
    switch (strtolower($item['level'])) {
        case 'high':
        case 'severe':
        case 'critical':
            $level_color = 'color: #f44336; font-weight: bold;';
            break;
        case 'medium':
        case 'moderate':
            $level_color = 'color: #ff9800; font-weight: bold;';
            break;
        case 'low':
        case 'minimal':
            $level_color = 'color: #4caf50; font-weight: bold;';
            break;
        case 'degraded':
            $level_color = 'color: #ff9800; font-weight: bold;';
            break;
        default:
            $level_color = 'color: #666; font-style: italic;';
    }

    $dependency_table->addRow([
        $item['category'],
        $item['details'],
        $item['status'],
        (new CSpan($item['level']))->addStyle($level_color)
    ]);
}

$dependency_section->addItem($dependency_table);

// SEÇÃO 3: Affected Services Detail
if (!empty($dependency_impact['affected_services'])) {
    $services_section = new CDiv();
    $services_section->addClass('affected-services-section');
    $services_section->addItem(new CTag('h4', false, _('Affected Services Detail')));

    $services_table = new CTableInfo();
    $services_table->setHeader([_('Service Name'), _('Status'), _('Impact Level'), _('Dependencies')]);

    foreach ($dependency_impact['affected_services'] as $service) {
        $impact_color = '';
        switch (strtolower($service['impact_level'])) {
            case 'critical':
                $impact_color = 'color: #f44336; font-weight: bold;';
                break;
            case 'high':
                $impact_color = 'color: #ff9800; font-weight: bold;';
                break;
            case 'low':
                $impact_color = 'color: #4caf50; font-weight: bold;';
                break;
            default:
                $impact_color = 'color: #666;';
        }

        $dependencies_text = '';
        if ($service['parents_count'] > 0 || $service['children_count'] > 0) {
            $dependencies_text = $service['parents_count'] . ' parents, ' . $service['children_count'] . ' children';
        } else {
            $dependencies_text = 'No dependencies';
        }

        $services_table->addRow([
            $service['name'],
            'Status: ' . $service['status'],
            (new CSpan($service['impact_level']))->addStyle($impact_color),
            $dependencies_text
        ]);
    }

    $services_section->addItem($services_table);
    $impact_container->addItem($services_section);
}

// Add sections to impact container
$impact_container->addItem($dependency_section);

$impact_div->addItem($impact_container);

$tabs->addTab('impact', _('Impact Assessment'), $impact_div);



$event_name = $event['name'] ?? 'Unknown Event';

// Add a unique ID to tabs for easier selection
$tabs->setAttribute('id', 'event-details-tabs');

$output = [
    'header' => _('Event Details') . ': ' . $event_name,
    'body' => (new CDiv())
        ->addClass('event-details-popup')
        ->addItem($tabs)
        ->toString(),
    'buttons' => null,
    'script_inline' => '
        (function() {
            // Data for D3.js charts
            var hourlyData = ' . json_encode(array_values($hourly_data)) . ';
            var weeklyData = ' . json_encode(array_values($weekly_data)) . ';
            var weekdayLabels = ' . json_encode($weekdays) . ';
            var hourLabels = ' . json_encode($hour_labels) . ';

            
            // Event data for services loading
            window.currentEventData = {
                eventid: "' . ($event['eventid'] ?? '') . '",
                hostname: "' . ($host['name'] ?? $host['host'] ?? '') . '",
                hostid: "' . ($host['hostid'] ?? '') . '",
                triggerid: "' . ($trigger['triggerid'] ?? '') . '",
                problem_name: "' . addslashes($event['name'] ?? '') . '",
                severity: "' . ($event['severity'] ?? 0) . '"
            };
            
            // Function to create D3.js bar charts or fallback
            var createPatternCharts = function() {
                
                if (typeof d3 === "undefined") {
                    createCSSFallbackCharts();
                    return;
                }
                
                // Hourly pattern chart
                if (jQuery("#hourly-pattern-chart").length) {
                    createBarChart("#hourly-pattern-chart", hourlyData, hourLabels, "#0275b8");
                }
                
                // Weekly pattern chart
                if (jQuery("#weekly-pattern-chart").length) {
                    
                    createBarChart("#weekly-pattern-chart", weeklyData, weekdayLabels, "#28a745");
                } else {
                    
                }
            };
            
            // CSS Fallback for when D3.js is not available
            var createCSSFallbackCharts = function() {
                
                // Hourly chart fallback
                createCSSBarChart("#hourly-pattern-chart", hourlyData, hourLabels, "#0275b8");
                    
                // Weekly chart fallback  
                createCSSBarChart("#weekly-pattern-chart", weeklyData, weekdayLabels, "#28a745");
            };
            
            var createCSSBarChart = function(container, data, labels, color) {
                var $container = jQuery(container);
                if (!$container.length) return;
                
                $container.empty();
                
                // Define missing color variables
                var emptyBarColor = "#e0e0e0";
                var textColor = "#333333";
                var labelColor = "#666666";
                
                // Dark theme support
                if (document.body.getAttribute("theme") === "dark-theme") {
                    emptyBarColor = "#3a3a3a";
                    textColor = "#cccccc";
                    labelColor = "#999999";
                }
                
                var maxValue = Math.max.apply(Math, data);
                if (maxValue === 0) maxValue = 1;
                
                var chartHtml = "<div style=\"display: flex; align-items: end; height: 140px; gap: 2px; padding: 10px;\">";
                
                for (var i = 0; i < data.length; i++) {
                    var height = (data[i] / maxValue) * 110;
                    var barColor = data[i] > 0 ? color : emptyBarColor;
                    
                    chartHtml += "<div style=\"display: flex; flex-direction: column; align-items: center; flex: 1;\">";
                    chartHtml += "<div style=\"font-size: 10px; margin-bottom: 2px; color: " + textColor + ";\">" + (data[i] > 0 ? data[i] : "") + "</div>";
                    chartHtml += "<div style=\"background: " + barColor + "; width: 100%; height: " + height + "px; min-height: 2px;\"></div>";
                    chartHtml += "<div style=\"font-size: 8px; margin-top: 5px; color: " + labelColor + "; white-space: nowrap; text-align: center;\">" + labels[i] + "</div>";
                    chartHtml += "</div>";
                }
                
                chartHtml += "</div>";
                $container.html(chartHtml);
            };
            
            var createBarChart = function(container, data, labels, color) {
                
                
                var $container = jQuery(container);
                if (!$container.length) {
                    
                    return;
                }
                
                var containerEl = $container[0];
                if (!containerEl.offsetWidth) {
                    
                    createCSSBarChart(container, data, labels, color);
                    return;
                }
                
                var margin = {top: 15, right: 25, bottom: 35, left: 35};
                var width = containerEl.offsetWidth - margin.left - margin.right;
                var height = 140 - margin.top - margin.bottom;
                
                if (width <= 0 || height <= 0) {
                    
                    createCSSBarChart(container, data, labels, color);
                    return;
                }
                
                try {
                    // Clear previous chart
                    d3.select(container).selectAll("*").remove();
                    
                    var svg = d3.select(container)
                        .append("svg")
                        .attr("width", width + margin.left + margin.right)
                        .attr("height", height + margin.top + margin.bottom)
                        .append("g")
                        .attr("transform", "translate(" + margin.left + "," + margin.top + ")");
                    
                    var x = d3.scaleBand()
                        .range([0, width])
                        .domain(labels)
                        .padding(0.1);
                    
                    var y = d3.scaleLinear()
                        .domain([0, d3.max(data) || 1])
                        .range([height, 0]);
                    
                    // Add bars
                    svg.selectAll(".bar")
                        .data(data)
                        .enter().append("rect")
                        .attr("class", "bar")
                        .attr("x", function(d, i) { return x(labels[i]); })
                        .attr("width", x.bandwidth())
                        .attr("y", function(d) { return y(d); })
                        .attr("height", function(d) { return height - y(d); })
                        .attr("fill", color)
                        .attr("opacity", 0.8)
                        .on("mouseover", function(event, d) {
                            d3.select(this).attr("opacity", 1);
                        })
                        .on("mouseout", function(event, d) {
                            d3.select(this).attr("opacity", 0.8);
                        });
                    
                    // Add labels on bars
                    svg.selectAll(".text")
                        .data(data)
                        .enter().append("text")
                        .attr("x", function(d, i) { return x(labels[i]) + x.bandwidth()/2; })
                        .attr("y", function(d) { return y(d) - 5; })
                        .attr("text-anchor", "middle")
                        .style("font-size", "10px")
                        .style("fill", "#333")
                        .text(function(d) { return d > 0 ? d : ""; });
                    
                                    // Add X axis
                svg.append("g")
                    .attr("transform", "translate(0," + height + ")")
                    .call(d3.axisBottom(x))
                    .selectAll("text")
                    .style("font-size", "9px")
                    .style("text-anchor", "middle");
                    
                    // Add Y axis
                    svg.append("g")
                        .call(d3.axisLeft(y).ticks(5))
                        .selectAll("text")
                        .style("font-size", "10px");
                        
                    
                        
                } catch (error) {
                    console.error("Error creating D3 chart:", error);
                    
                    createCSSBarChart(container, data, labels, color);
                }
            };
            
            // Wait for DOM and jQuery UI to be ready
            var initTabs = function() {
                var $tabs = jQuery("#event-details-tabs");
                if ($tabs.length > 0) {
                    // Check if jQuery UI tabs is available
                    if (typeof jQuery.fn.tabs === "function") {
                        try {
                            $tabs.tabs({
                                activate: function(event, ui) {
                                    // Recreate D3 charts when patterns tab is activated
                                    if (ui.newPanel.attr("id") === "ui-id-2") {
                                        setTimeout(createPatternCharts, 100);
                                    }
                                    // Create correlation visualizations when correlation tab is activated
                                    if (ui.newPanel.find("#correlation-timeline-chart").length > 0) {
                                        setTimeout(createCorrelationVisualizations, 100);
                                    }
                                
                                }
                            });
                        } catch(e) {
                            console.error("Error initializing tabs with jQuery UI:", e);
                            // Fallback to manual implementation
                            setupManualTabs();
                        }
                    } else {
                        // jQuery UI tabs not available, use manual implementation
                        setupManualTabs();
                    }
                }
                
                // Create D3 charts initially and when patterns tab becomes visible
                setTimeout(function() {
                    createPatternCharts();
                    // Also try to create when patterns tab becomes visible
                    jQuery("#event-details-tabs").on("tabsactivate", function(event, ui) {
                        if (ui.newPanel.find("#hourly-pattern-chart").length > 0) {
                            setTimeout(createPatternCharts, 50);
                        }
                        // Load services when services tab is activated
                        if (ui.newPanel.find("#services-tree-container").length > 0) {
                            setTimeout(function() {
                                if (window.loadImpactedServices) {
                                    window.loadImpactedServices();
                                }
                            }, 100);
                        }
                        // Create correlation visualizations when correlation tab is activated
                        if (ui.newPanel.find("#correlation-timeline-chart").length > 0) {
                            setTimeout(createCorrelationVisualizations, 100);
                        }
                    });
                }, 300);
            };
            
            var setupManualTabs = function() {
                
                var $container = jQuery("#event-details-tabs");
                var $navLinks = $container.find(".ui-tabs-nav a");
                var $panels = $container.find(".ui-tabs-panel");
                
                
                
                // Remove any existing active classes first
                $panels.removeClass("active-panel ui-tabs-panel-active");
                $navLinks.parent().removeClass("ui-tabs-active ui-state-active");
                
                // Force hide all panels first using multiple methods
                $panels.each(function() {
                    jQuery(this).hide();
                    jQuery(this).css("display", "none");
                    jQuery(this).removeClass("active-panel ui-tabs-panel-active");
                });
                
                // Show and mark first panel as active
                var $firstPanel = $panels.first();
                $firstPanel.addClass("active-panel");
                $firstPanel.css("display", "block");
                $firstPanel.show();
                $navLinks.first().parent().addClass("ui-tabs-active ui-state-active");
                
                // Handle tab clicks
                $navLinks.off("click.tabs").on("click.tabs", function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    var $link = jQuery(this);
                    var target = $link.attr("href");
                    
                    
                    // Update active states on nav
                    $navLinks.parent().removeClass("ui-tabs-active ui-state-active");
                    $link.parent().addClass("ui-tabs-active ui-state-active");
                    
                    // Hide all panels forcefully
                    $panels.each(function() {
                        var $panel = jQuery(this);
                        $panel.removeClass("active-panel ui-tabs-panel-active");
                        $panel.hide();
                        $panel.css("display", "none");
                    });
                    
                    // Show target panel and add active class
                    if (target && target.startsWith("#")) {
                        var $targetPanel = jQuery(target);
                        if ($targetPanel.length) {
                            $targetPanel.addClass("active-panel");
                            $targetPanel.css("display", "block");
                            $targetPanel.show();
                            
                            
                            // Recreate D3 charts when patterns tab is shown
                            if (target.includes("patterns") || $link.text().includes("Pattern") ||
                                $targetPanel.find("#hourly-pattern-chart").length > 0) {

                                setTimeout(createPatternCharts, 50);
                                setTimeout(createPatternCharts, 200);
                                setTimeout(createPatternCharts, 500);
                            }

                            // Load services when services tab is shown
                            if (target.includes("services") || $link.text().includes("Services") ||
                                $targetPanel.find("#services-tree-container").length > 0) {

                                setTimeout(function() {
                                    if (window.loadImpactedServices) {
                                        window.loadImpactedServices();
                                    }
                                }, 100);
                            }

                            // Create correlation visualizations when correlation tab is shown
                            if (target.includes("correlation") || $link.text().includes("Correlation") ||
                                $targetPanel.find("#correlation-timeline-chart").length > 0) {

                                setTimeout(createCorrelationVisualizations, 50);
                                setTimeout(createCorrelationVisualizations, 200);
                                setTimeout(createCorrelationVisualizations, 500);
                            }
                            
                            
                            // Double-check other panels are hidden
                            $panels.not($targetPanel).each(function() {
                                jQuery(this).hide();
                                jQuery(this).css("display", "none");
                            });
                        }
                    }
                    
                    return false;
                });
                
                // Also bind to parent li for better clickability
                $container.find(".ui-tabs-nav li").off("click.tabs").on("click.tabs", function(e) {
                    if (!jQuery(e.target).is("a")) {
                        e.preventDefault();
                        jQuery(this).find("a").trigger("click.tabs");
                    }
                });
                
                // Debug info
                
                
                
            };
            
            // Try multiple initialization approaches
            if (typeof jQuery !== "undefined") {
                // Try immediate execution
                initTabs();
                
                // Also try on document ready
                jQuery(document).ready(function() {
                    setTimeout(initTabs, 100);
                    // Additional attempts to create charts
                    setTimeout(createPatternCharts, 500);
                    setTimeout(createPatternCharts, 1000);
                    setTimeout(createPatternCharts, 2000);
                });
                
                // And on window load as last resort
                jQuery(window).on("load", function() {
                    setTimeout(initTabs, 300);
                    setTimeout(createPatternCharts, 800);
                    setTimeout(createPatternCharts, 1500);
                });
                
                // Watch for tab visibility changes
                jQuery(document).on("click", "a[href*=patterns]", function() {
                    
                    setTimeout(createPatternCharts, 100);
                    setTimeout(createPatternCharts, 300);
                });
                
            } else {
                // Fallback if jQuery not ready
                setTimeout(initTabs, 500);
                setTimeout(createPatternCharts, 1000);
                setTimeout(function() {
                    if (window.loadImpactedServices) {
                        window.loadImpactedServices();
                    }
                }, 1500);
            }
            
            // Load services automatically when popup opens
            setTimeout(function() {
                if (window.loadImpactedServices && window.currentEventData) {
                    
                    window.loadImpactedServices();
                }
            }, 2000);

            // ==================== SERVICES MANAGEMENT ====================
            // Services management functions for AnalistProblem module
            
            /**
             * Load impacted services for the current event
             */
            async function loadImpactedServices() {
                try {
                    
                    const loadingElement = document.querySelector(".services-loading");
                    const treeContainer = document.querySelector("#services-tree-container");
                    
                    if (loadingElement) loadingElement.style.display = "block";
                    if (treeContainer) treeContainer.innerHTML = "";

                    // Get current event data from global variable
                    if (!window.currentEventData) {
                        console.error("No event data available");
                        displayServicesError("Event data not available");
                        return;
                    }

                    const eventData = window.currentEventData;


                    // Fetch services related to this host/trigger
                    const services = await fetchRelatedServices(eventData);
                    
                    if (services && services.length > 0) {

                        displayServicesTree(services);
                    } else {

                        displayNoServicesMessage();
                    }

                } catch (error) {
                    console.error("Error loading impacted services:", error);
                    displayServicesError(error.message || String(error));
                } finally {
                    const loadingElement = document.querySelector(".services-loading");
                    if (loadingElement) loadingElement.style.display = "none";
                }
            }

            /**
             * Fetch services related to this event using AnalistProblem API
             */
            async function fetchRelatedServices(eventData) {
                try {

                    
                    const formData = new FormData();
                    formData.append("output", "extend");
                    formData.append("selectParents", "extend");
                    formData.append("selectChildren", "extend");
                    formData.append("selectTags", "extend");
                    formData.append("selectProblemTags", "extend");
                    
                    // Add event-specific filters
                    if (eventData.hostname) {
                        formData.append("hostname", eventData.hostname);

                    }
                    if (eventData.eventid) {
                        formData.append("eventid", eventData.eventid);

                    }
                    if (eventData.hostid) {
                        formData.append("hostid", eventData.hostid);

                    }
                    if (eventData.triggerid) {
                        formData.append("triggerid", eventData.triggerid);

                    }



                    const response = await fetch("zabbix.php?action=problemanalist.service.get", {
                        method: "POST",
                        body: formData
                    });


                    if (!response.ok) {
                        const errorText = await response.text();
                        console.error(" HTTP Error Response:", errorText);
                        throw new Error("HTTP error! status: " + response.status + " - " + errorText);
                    }

                    const result = await response.json();

                    
                    // Direct response format (no main_block)
                    if (result && result.success && result.data) {

                        return result.data;
                    }
                    
                    if (result && !result.success) {
                        console.error(" API returned error:", result.error);
                        throw new Error(result.error?.message || "API returned error");
                    }
                    
                    // Fallback: get all services and filter client-side

                    return await fetchAllServicesAndFilter(eventData);
                    
                } catch (error) {
                    console.error(" Error fetching services:", error);

                    return await fetchAllServicesAndFilter(eventData);
                }
            }

            /**
             * Fallback method to get all services and filter
             */
            async function fetchAllServicesAndFilter(eventData) {
                try {
                    const formData = new FormData();
                    formData.append("output", "extend");
                    formData.append("selectParents", "extend");
                    formData.append("selectChildren", "extend");
                    formData.append("selectTags", "extend");
                    formData.append("selectProblemTags", "extend");

                    const response = await fetch("zabbix.php?action=problemanalist.service.get", {
                        method: "POST",
                        body: formData
                    });

                    if (!response.ok) {
                        throw new Error("HTTP error! status: " + response.status);
                    }

                    const result = await response.json();

                    
                    if (result && result.success && result.data) {
                        return result.data;
                    }
                    
                    return [];
                    
                } catch (error) {
                    console.error("Error fetching all services:", error);
                    return [];
                }
            }

            /**
             * Display single service card with clickable hierarchy tree
             */
            function displayServicesTree(services) {    
                const treeContainer = document.querySelector("#services-tree-container");
                if (!treeContainer) {
                    console.error("Tree container not found!");
                    return;
                }
                
                const serviceMap = new Map();
                
                // Create service map
                services.forEach(service => {
                    serviceMap.set(service.serviceid, {
                        ...service,
                        hierarchyChildren: []
                    });
                });

                // Find the impacted service (the one that matched the event)
                let impactedService = null;
                services.forEach(service => {
                    if (service.problem_tags && service.problem_tags.length > 0) {
                        impactedService = service;
                    }
                });
                
                if (!impactedService) {
                    impactedService = services[0]; // Fallback to first service
                }
                
                // Use the hierarchy_path from backend in natural order (root -> leaf)
                let pathToRoot = [];
                if (impactedService.hierarchy_path && impactedService.hierarchy_path.length > 0) {
                    // Keep as-is to preserve correct parent → child order
                    pathToRoot = [...impactedService.hierarchy_path];
                    // Ensure impacted service is the last item in the path
                    const lastInPath = pathToRoot[pathToRoot.length - 1];
                    if (!lastInPath || String(lastInPath.serviceid) !== String(impactedService.serviceid)) {
                        pathToRoot.push({ serviceid: impactedService.serviceid, name: impactedService.name });
                    }
                } else {
                    // Fallback: create path manually if not available
                    pathToRoot = [impactedService];
                }

                // Generate HTML for the service tree
                let finalHtml = "<div class=\"services-container\">";
                
                // Summary header
                finalHtml += "<div class=\"services-summary-header\"><h4> Impacted Services ({COUNT})</h4><p>Services matching this event\'s tags</p></div>".replace("{COUNT}", services.length);

                // Main service card (impacted service)
                const impactedServiceHtml = createServiceCard(impactedService, true);
                finalHtml += "<div><div>" + createHierarchyPath(pathToRoot) + "</div>" + impactedServiceHtml + "</div>";

                // Other services (if any)
                const otherServices = services.filter(s => s.serviceid !== impactedService.serviceid);
                if (otherServices.length > 0) {
                    finalHtml += "<div style=\\"margin-top: 20px; padding-top: 20px; border-top: 2px solid #ddd;\\"><h5 style=\\"color: #333; margin-bottom: 15px;\\">🔗 Other Related Services (" + otherServices.length + ")</h5><div>";
                    
                    otherServices.forEach(service => {
                        finalHtml += createServiceCard(service, false);
                    });
                    
                    finalHtml += "</div></div>";
                }

                finalHtml += "</div>";
                
                treeContainer.innerHTML = finalHtml;
                
                // Store services globally for later use
                window.currentServices = services;
                window.currentServiceId = impactedService.serviceid;
                
                // Load initial SLI data for impacted service
                loadServiceSLI(impactedService.serviceid);
            }

            /**
             * Create hierarchy path HTML
             */
            function createHierarchyPath(pathToRoot) {
                if (!pathToRoot || pathToRoot.length === 0) return "";
                
                let pathHtml = "<div class=\"services-hierarchy-path\"><span class=\"hierarchy-label\">Hierarchy:</span>";
                
                pathToRoot.forEach((service, index) => {
                    if (index > 0) {
                        pathHtml += " <span class=\"hierarchy-arrow\">&gt;</span> ";
                    }
                    pathHtml += "<span class=\\"hierarchy-service\\" onclick=\\"selectService(\'" + service.serviceid + "\')\\">" + service.name + "</span>";
                });
                
                pathHtml += "</div>";
                return pathHtml;
            }

            /**
             * Create service card HTML
             */
            function createServiceCard(service, isImpacted) {
                if (isImpacted === undefined) isImpacted = false;
                
                const statusClass = getServiceStatusClass(service.status);
                const statusText = getServiceStatusText(service.status);
                
                // SLA info
                const hasRealSla = service.has_sla && service.sli !== null;
                let slaInfo = "";
                
                if (hasRealSla) {
                    // SLI já vem como porcentagem (0-100), não precisa multiplicar por 100
                    const sliFormatted = parseFloat(service.sli).toFixed(2);
                    slaInfo = "<div class=\\"service-sla-info\\"><div class=\\"sla-item\\"><span class=\\"sla-label\\">SLI:</span><span class=\\"sla-value sla-value-success\\">" + sliFormatted + "%</span></div><div class=\\"sla-item\\"><span class=\\"sla-label\\">Uptime:</span><span class=\\"sla-value\\">" + (service.uptime || "N/A") + "</span></div><div class=\\"sla-item\\"><span class=\\"sla-label\\">Downtime:</span><span class=\\"sla-value\\">" + (service.downtime || "N/A") + "</span></div><div class=\\"sla-item\\"><span class=\\"sla-label\\">Error Budget:</span><span class=\\"sla-value sla-value-error\\">" + (service.error_budget || "N/A") + "</span></div></div>";
                } else {
                    slaInfo = "<div class=\\"service-no-sla\\"> No SLA configured</div>";
                }

                let impactedClass = isImpacted ? " service-card-impacted" : "";
                let impactedBadge = isImpacted ? "<span class=\\"impacted-badge\\"> Impacted</span>" : "";

                return "<div class=\"service-card" + impactedClass + "\" data-serviceid=\"" + service.serviceid + "\"><div class=\"service-header\"><div class=\"service-name\"><span class=\"service-name-link\" onclick=\"selectService(\'" + service.serviceid + "\')\">" + service.name + "</span>" + impactedBadge + "</div><div class=\"service-status " + statusClass + "\">" + statusText + "</div></div>" + slaInfo + "</div>";
            }

            function getServiceStatusClass(status) {
                const statusClasses = {
                    0: "status-ok",
                    2: "status-average",
                    3: "status-warning",
                    4: "status-high",
                    5: "status-disaster"
                };
                return statusClasses[status] || "status-unknown";
            }

            function getServiceStatusStyle(status) {
                const statusStyles = {
                    0: "background: #27ae60; color: white;",
                    2: "background: #f39c12; color: white;",
                    3: "background: #e67e22; color: white;",
                    4: "background: #e74c3c; color: white;",
                    5: "background: #8e44ad; color: white;"
                };
                return statusStyles[status] || "background: #95a5a6; color: white;";
            }

            function getServiceStatusText(status) {
                const statusTexts = {
                    0: " OK",
                    2: " Average",
                    3: " Warning",
                    4: " High",
                    5: " Disaster"
                };
                return statusTexts[status] || " Unknown";
            }

            /**
             * Load SLI data for a specific service
             */
            async function loadServiceSLI(serviceid) {
                try {              
                    const formData = new FormData();
                    formData.append("serviceid", serviceid);
                    
                    const response = await fetch("zabbix.php?action=problemanalist.service.get", {
                        method: "POST",
                        body: formData
                    });

                    if (!response.ok) {
                        throw new Error("HTTP error! status: " + response.status);
                    }

                    const result = await response.json();
                    
                    if (result && result.success && result.data) {
                        // Update the service card with new SLI data
                        updateServiceSLIDisplay(serviceid, result.data);
                    }

                } catch (error) {
                    console.error(" Error loading SLI data:", error);
                }
            }

            function updateServiceSLIDisplay(serviceid, serviceData) {               
                // Encontrar o card do serviço
                let serviceCard = document.querySelector("[data-serviceid=\"" + serviceid + "\"]");
                
                // Fallback para o primeiro card caso não exista um card específico
                if (!serviceCard) {
                    const firstCard = document.querySelector(".service-card");
                    if (firstCard) {
                        serviceCard = firstCard;
                    } else {
                        return;
                    }
                }

                // Atualizar status do serviço
                const statusElement = serviceCard.querySelector(".service-status");
                
                if (statusElement && serviceData.status !== undefined) {
                    const statusClass = getServiceStatusClass(serviceData.status);
                    const statusText = getServiceStatusText(serviceData.status);
                    statusElement.className = "service-status " + statusClass;
                    statusElement.textContent = statusText;
                }

                // Atualizar informações SLI - procurar container SLA
                let slaContainer = serviceCard.querySelector(".service-sla-info");
                if (!slaContainer) {
                    slaContainer = serviceCard.querySelector(".service-no-sla");
                }
                
                if (slaContainer) {
                    const hasRealSla = serviceData.has_sla && serviceData.sli !== null;
                    
                    if (hasRealSla) {
                        // Service tem SLA configurado - mostrar valores
                        const sliFormatted = parseFloat(serviceData.sli).toFixed(2);
                        const uptimeValue = serviceData.uptime || "";
                        const downtimeValue = serviceData.downtime || "";
                        const errorBudgetValue = serviceData.error_budget || "";
                        
                        
                        slaContainer.className = "service-sla-info";
                        slaContainer.innerHTML = 
                            "<div class=\\"sla-item\\"><span class=\\"sla-label\\">SLI:</span><span class=\\"sla-value sla-value-success\\">" + 
                            sliFormatted + "%</span></div>" +
                            "<div class=\\"sla-item\\"><span class=\\"sla-label\\">Uptime:</span><span class=\\"sla-value\\">" + 
                            uptimeValue + "</span></div>" +
                            "<div class=\\"sla-item\\"><span class=\\"sla-label\\">Downtime:</span><span class=\\"sla-value\\">" + 
                            downtimeValue + "</span></div>" +
                            "<div class=\\"sla-item\\"><span class=\\"sla-label\\">Error Budget:</span><span class=\\"sla-value sla-value-error\\">" + 
                            errorBudgetValue + "</span></div>";
                    } else {
                        // Service não tem SLA configurado: mostrar campos em branco
                        slaContainer.className = "service-sla-info";
                        slaContainer.innerHTML =
                            "<div class=\\"sla-item\\"><span class=\\"sla-label\\">SLI:</span><span class=\\"sla-value\\"></span></div>" +
                            "<div class=\\"sla-item\\"><span class=\\"sla-label\\">Uptime:</span><span class=\\"sla-value\\"></span></div>" +
                            "<div class=\\"sla-item\\"><span class=\\"sla-label\\">Downtime:</span><span class=\\"sla-value\\"></span></div>" +
                            "<div class=\\"sla-item\\"><span class=\\"sla-label\\">Error Budget:</span><span class=\\"sla-value\\"></span></div>";
                    }
                } else {
                    const allDivs = serviceCard.querySelectorAll("div");
                    allDivs.forEach((div, index) => {
                    });
                }

            }

            /**
             * Select and focus on a specific service
             */
            function selectService(serviceid) {
                
                // Remove previous selections
                document.querySelectorAll("[data-serviceid]").forEach(card => {
                    card.style.borderColor = "#ddd";
                });
                
                // Highlight selected service
                const selectedCard = document.querySelector("[data-serviceid=\"" + serviceid + "\"]");
                if (selectedCard) {
                    selectedCard.style.borderColor = "#007cba";
                    selectedCard.style.boxShadow = "0 0 10px rgba(0,124,186,0.3)";
                    selectedCard.scrollIntoView({ behavior: "smooth", block: "nearest" });
                }
                
                // Load fresh SLI data
                loadServiceSLI(serviceid);
                
                // Store globally
                window.currentServiceId = serviceid;
            }

            /**
             * Display message when no services are found
             */
            function displayNoServicesMessage() {
                const treeContainer = document.querySelector("#services-tree-container");
                if (!treeContainer) return;
                
                treeContainer.innerHTML = "<div class=\"services-no-results\"><p><strong>No impacted services found for this event.</strong></p><p>This happens when:</p><ul><li>Event tags don\'t match any service problem_tags</li><li>Services are not configured with proper problem_tags</li><li>The service might not be related to this type of problem</li></ul><p><small><strong>How it works:</strong> The system matches event tags with service problem_tags to find impacted services.</small></p></div>";
            }

            /**
             * Display error message
             */
            function displayServicesError(errorMessage) {
                const treeContainer = document.querySelector("#services-tree-container");
                if (!treeContainer) return;
                
                treeContainer.innerHTML = "<div style=\\"background: #fef5f5; border: 1px solid #e74c3c; border-radius: 8px; padding: 20px; text-align: center; color: #e74c3c;\\"><p>Error loading services: " + errorMessage + "</p></div>";
            }

            /**
             * Toggle event details in timeline
             */
            function toggleEventDetails(toggleElement) {
                var eventCard = toggleElement.closest(".event-card");
                var detailsDiv = eventCard.querySelector(".event-details");

                if (detailsDiv.classList.contains("expanded")) {
                    detailsDiv.classList.remove("expanded");
                    toggleElement.textContent = "Details";
                } else {
                    detailsDiv.classList.add("expanded");
                    toggleElement.textContent = "Hide";
                }
            }

            // Make functions globally available
            window.loadImpactedServices = loadImpactedServices;
            window.loadServiceSLI = loadServiceSLI;
            window.selectService = selectService;
            window.createCorrelationVisualizations = createCorrelationVisualizations;
            window.toggleEventDetails = toggleEventDetails;

            // Initialize correlation visualizations immediately
            jQuery(document).ready(function() {
                setTimeout(function() {
                    createCorrelationVisualizations();
                }, 1000); // Wait 1 second for DOM to be fully ready
            });

            /**
             * Create Event Cascade Timeline (Datadog-style expandable traces)
             */
            function createEventCascadeTimeline() {
                var container = document.getElementById("event-cascade-timeline");
                if (!container) {
                    return;
                }

                if (!eventTimelineData || !eventTimelineData.events || eventTimelineData.events.length === 0) {
                    container.innerHTML = "<div style=\"text-align: center; padding: 40px; color: #666; font-style: italic;\">No timeline data available</div>";
                    return;
                }

                container.innerHTML = "";

                // Create trace view container (Zabbix style)
                var traceView = document.createElement("div");
                traceView.className = "zabbix-trace-view";
                traceView.style.cssText = "background: var(--background-color, #fff); border: 1px solid var(--border-color, #d4d4d4);";

                // Create trace header (Zabbix style)
                var header = document.createElement("div");
                header.className = "trace-header";
                header.textContent = "Event Correlation Timeline";
                traceView.appendChild(header);

                // Create info bar (Zabbix style)
                var infoBar = document.createElement("div");
                infoBar.className = "trace-info-bar";

                var totalDuration = eventTimelineData.total_duration || 3600;
                var durationText = totalDuration > 3600 ? (totalDuration/3600).toFixed(1) + "h" : (totalDuration/60).toFixed(1) + "m";
                infoBar.innerHTML = "<span>Analysis window: " + durationText + "</span><span>Sorted by confidence</span>";
                traceView.appendChild(infoBar);

                // Create spans container (no max-height to prevent scroll)
                var spansContainer = document.createElement("div");
                spansContainer.className = "spans-container";
                spansContainer.style.cssText = "background: var(--background-color, #fff);";

                // Sort events by timestamp for hierarchical display
                var events = (eventTimelineData.events || []).slice().sort(function(a, b) {
                    return a.timestamp - b.timestamp;
                });

                // Group events by confidence level and limit display
                var highConfidenceEvents = events.filter(function(e) { return e.confidence_percentage >= 60; });
                var mediumConfidenceEvents = events.filter(function(e) { return e.confidence_percentage >= 30 && e.confidence_percentage < 60; });
                var lowConfidenceEvents = events.filter(function(e) { return e.confidence_percentage < 30; });

                // Add root span (current event)
                var rootSpan = createTraceSpan({
                    event_id: "root",
                    event_name: "Current Problem Event",
                    severity: 5,
                    time_offset: 0,
                    timestamp: eventTimelineData.timeline_start || Date.now()/1000,
                    duration: totalDuration,
                    confidence_percentage: 100,
                    confidence_level: "Certain",
                    trace_metadata: {
                        formatted_time: new Date().toLocaleTimeString(),
                        relative_time: "0ms",
                        confidence_details: "Root problem event"
                    },
                    is_root: true,
                    children: events.length
                }, 0, totalDuration, true);

                spansContainer.appendChild(rootSpan);

                // Add high confidence events (show first 3 to avoid modal overflow)
                if (highConfidenceEvents.length > 0) {
                    var highConfidenceGroup = createGroupSpan("High Confidence", highConfidenceEvents.length, 1);
                    spansContainer.appendChild(highConfidenceGroup);

                    highConfidenceEvents.slice(0, 3).forEach(function(event, index) {
                        var span = createTraceSpan(event, 2, totalDuration, false);
                        span.setAttribute("data-group", "high-confidence");
                        spansContainer.appendChild(span);
                    });

                    // Add "show more" for high confidence if more than 3
                    if (highConfidenceEvents.length > 3) {
                        var showMoreHigh = createShowMoreSpan("high-confidence", highConfidenceEvents.length - 3, 2);
                        spansContainer.appendChild(showMoreHigh);
                    }
                }

                // Add medium confidence group (collapsed by default)
                if (mediumConfidenceEvents.length > 0) {
                    var mediumConfidenceGroup = createGroupSpan("Medium Confidence", mediumConfidenceEvents.length, 1, true);
                    spansContainer.appendChild(mediumConfidenceGroup);
                }

                // Add low confidence group (collapsed by default)
                if (lowConfidenceEvents.length > 0) {
                    var lowConfidenceGroup = createGroupSpan("Low Confidence", lowConfidenceEvents.length, 1, true);
                    spansContainer.appendChild(lowConfidenceGroup);
                }

                traceView.appendChild(spansContainer);
                container.appendChild(traceView);
            }

            /**
             * Create individual trace span (Datadog-style)
             */
            function createTraceSpan(event, depth, totalDuration, isRoot) {
                var span = document.createElement("div");
                span.className = "trace-span";
                span.setAttribute("data-event-id", event.event_id);
                span.setAttribute("data-expanded", isRoot ? "true" : "false");

                var paddingLeft = 16 + (depth * 12);
                span.style.cssText = "border-bottom: 1px solid var(--light-border-color, #d4d4d4); cursor: pointer; transition: background-color 0.2s ease;";

                // Span content
                var spanContent = document.createElement("div");
                spanContent.className = "span-content";
                spanContent.style.cssText = "padding: 8px 16px 8px " + paddingLeft + "px; display: flex; align-items: center; position: relative;";

                // Expand/collapse icon (only for root and events with children)
                var expandIcon = document.createElement("span");
                expandIcon.className = "expand-icon";
                expandIcon.style.cssText = "margin-right: 8px; font-size: 12px; color: #586069; transition: transform 0.2s ease; cursor: pointer;";

                if (isRoot || (event.children && event.children > 0)) {
                    expandIcon.innerHTML = isRoot ? "[-]" : "[+]";
                    expandIcon.setAttribute("data-expandable", "true");
                } else {
                    expandIcon.innerHTML = " • ";
                    expandIcon.style.color = "var(--secondary-text-color, #666)";
                }

                // Service/Event name
                var serviceName = document.createElement("span");
                serviceName.className = "service-name";
                serviceName.style.cssText = "font-weight: 500; margin-right: 8px; color: #24292e; font-size: 13px;";
                serviceName.textContent = event.event_name || "Event";

                // Operation name
                var operationName = document.createElement("span");
                operationName.className = "operation-name";
                operationName.style.cssText = "color: #586069; font-size: 12px; margin-right: auto;";

                var operationType = event.time_offset < 0 ? "root-cause" : (event.time_offset > 300 ? "cascade-effect" : "correlation");
                operationName.textContent = operationType;

                // Confidence percentage
                var confidence = document.createElement("span");
                confidence.className = "confidence";
                confidence.style.cssText = "font-size: 11px; font-weight: bold; margin-right: 8px; padding: 2px 6px; border-radius: 3px;";

                var confidenceValue = event.confidence_percentage || 0;
                var confidenceColor = confidenceValue >= 70 ? "#28a745" : (confidenceValue >= 40 ? "#ffc107" : "#dc3545");
                var confidenceTextColor = confidenceValue >= 40 ? "#fff" : "#fff";

                confidence.style.backgroundColor = confidenceColor;
                confidence.style.color = confidenceTextColor;
                confidence.textContent = confidenceValue + "%";

                // Duration/timing
                var duration = document.createElement("span");
                duration.className = "duration";
                duration.style.cssText = "font-size: 11px; color: #586069; margin-right: 12px;";
                duration.textContent = event.trace_metadata ? event.trace_metadata.relative_time : "0ms";

                // Timeline bar
                var timelineBar = document.createElement("div");
                timelineBar.className = "timeline-bar";
                timelineBar.style.cssText = "width: 200px; height: 6px; background: #f1f3f4; border-radius: 3px; position: relative; margin-right: 12px;";

                var progressBar = document.createElement("div");
                progressBar.className = "progress-bar";

                // Calculate width and position based on timing
                var startPercent = isRoot ? 0 : Math.max(0, ((event.time_offset + (totalDuration/2)) / totalDuration) * 100);
                var widthPercent = isRoot ? 100 : Math.min(20, Math.abs(event.duration || 300) / totalDuration * 100);

                startPercent = Math.min(80, startPercent);

                var severityColors = {
                    5: "#d73a49", 4: "#f66a0a", 3: "#ffd33d",
                    2: "#28a745", 1: "#0366d6", 0: "#6a737d"
                };

                var barColor = severityColors[event.severity] || "#6a737d";

                progressBar.style.cssText = "position: absolute; left: " + startPercent + "%; width: " + widthPercent + "%; height: 100%; background: " + barColor + "; border-radius: 3px; opacity: 0.8;";
                timelineBar.appendChild(progressBar);

                // Status indicator
                var statusIndicator = document.createElement("span");
                statusIndicator.className = "status-indicator";
                statusIndicator.style.cssText = "width: 8px; height: 8px; border-radius: 50%; background: " + barColor + "; margin-right: 8px;";

                // Add click handler for expand/collapse
                span.addEventListener("click", function(e) {
                    if (expandIcon.getAttribute("data-expandable") === "true") {
                        var expanded = span.getAttribute("data-expanded") === "true";
                        span.setAttribute("data-expanded", !expanded);
                        expandIcon.innerHTML = !expanded ? "[-]" : "[+]";

                        // Toggle child spans visibility
                        toggleChildSpans(span, !expanded);
                    }
                });

                // Add hover effects
                span.addEventListener("mouseenter", function() {
                    span.style.backgroundColor = "#f6f8fa";
                });

                span.addEventListener("mouseleave", function() {
                    span.style.backgroundColor = "transparent";
                });

                // Assemble span content
                spanContent.appendChild(expandIcon);
                spanContent.appendChild(statusIndicator);
                spanContent.appendChild(serviceName);
                spanContent.appendChild(operationName);
                spanContent.appendChild(timelineBar);
                spanContent.appendChild(confidence);
                spanContent.appendChild(duration);

                span.appendChild(spanContent);

                // Add details section (expandable)
                if (isRoot || event.trace_metadata) {
                    var details = document.createElement("div");
                    details.className = "span-details";
                    details.style.cssText = "padding: 8px 16px 8px " + (paddingLeft + 24) + "px; font-size: 11px; color: #586069; display: " + (isRoot ? "block" : "none") + ";";

                    var detailsContent = "";
                    if (event.trace_metadata) {
                        detailsContent += "<div><strong>Timestamp:</strong> " + (event.trace_metadata.formatted_time || "Unknown") + "</div>";
                        detailsContent += "<div><strong>Event ID:</strong> " + event.event_id + "</div>";
                        detailsContent += "<div><strong>Severity:</strong> " + (event.severity || 0) + "</div>";

                        if (event.confidence_percentage !== undefined) {
                            detailsContent += "<div><strong>Confidence:</strong> " + event.confidence_percentage + "% (" + (event.confidence_level || "Unknown") + ")</div>";
                        }

                        if (event.trace_metadata.confidence_details) {
                            detailsContent += "<div><strong>Criteria:</strong> " + event.trace_metadata.confidence_details + "</div>";
                        }
                    }
                    if (isRoot) {
                        detailsContent += "<div><strong>Type:</strong> Root Problem Event</div>";
                        detailsContent += "<div><strong>Related Events:</strong> " + (event.children || 0) + "</div>";
                    }

                    details.innerHTML = detailsContent;
                    span.appendChild(details);
                }

                return span;
            }

            /**
             * Toggle visibility of child spans
             */
            function toggleChildSpans(parentSpan, show) {
                var allSpans = parentSpan.parentNode.children;
                var parentDepth = getSpanDepth(parentSpan);
                var collecting = false;

                for (var i = 0; i < allSpans.length; i++) {
                    if (allSpans[i] === parentSpan) {
                        collecting = true;
                        continue;
                    }

                    if (collecting) {
                        var childDepth = getSpanDepth(allSpans[i]);
                        if (childDepth <= parentDepth) {
                            break; // Reached sibling or parent level
                        }

                        allSpans[i].style.display = show ? "block" : "none";
                    }
                }
            }

            /**
             * Get span depth from padding
             */
            function getSpanDepth(span) {
                var content = span.querySelector(".span-content");
                if (!content) return 0;
                var paddingLeft = parseInt(content.style.paddingLeft) || 16;
                return Math.floor((paddingLeft - 16) / 20);
            }

            /**
             * Create group span for organizing events by confidence
             */
            function createGroupSpan(groupName, eventCount, depth, collapsed) {
                var span = document.createElement("div");
                span.className = "trace-span group-span";
                span.setAttribute("data-group-name", groupName.toLowerCase().replace(/\s+/g, "-"));
                span.setAttribute("data-expanded", collapsed ? "false" : "true");

                var paddingLeft = 16 + (depth * 20);
                span.style.cssText = "border-bottom: 1px solid #e1e4e8; cursor: pointer; background: #f8f9fa; font-weight: 500;";

                var spanContent = document.createElement("div");
                spanContent.className = "span-content";
                spanContent.style.cssText = "padding: 8px 16px 8px " + paddingLeft + "px; display: flex; align-items: center;";

                var expandIcon = document.createElement("span");
                expandIcon.className = "expand-icon";
                expandIcon.style.cssText = "margin-right: 8px; font-size: 11px; color: var(--secondary-text-color, #666); cursor: pointer; font-family: monospace;";
                expandIcon.innerHTML = collapsed ? "[+]" : "[-]";

                var groupLabel = document.createElement("span");
                groupLabel.style.cssText = "font-weight: 600; color: #24292e; font-size: 13px;";
                groupLabel.textContent = groupName + " (" + eventCount + ")";

                span.addEventListener("click", function() {
                    var expanded = span.getAttribute("data-expanded") === "true";
                    span.setAttribute("data-expanded", !expanded);
                    expandIcon.innerHTML = !expanded ? "[-]" : "[+]";

                    // Toggle group visibility
                    toggleGroupEvents(span, !expanded);
                });

                spanContent.appendChild(expandIcon);
                spanContent.appendChild(groupLabel);
                span.appendChild(spanContent);

                return span;
            }

            /**
             * Create "show more" span for loading additional events
             */
            function createShowMoreSpan(groupName, remainingCount, depth) {
                var span = document.createElement("div");
                span.className = "trace-span show-more-span";
                span.setAttribute("data-group", groupName);

                var paddingLeft = 16 + (depth * 20);
                span.style.cssText = "border-bottom: 1px solid #f1f3f4; cursor: pointer; color: #586069; font-style: italic;";

                var spanContent = document.createElement("div");
                spanContent.className = "span-content";
                spanContent.style.cssText = "padding: 6px 16px 6px " + paddingLeft + "px; display: flex; align-items: center;";

                var showMoreLabel = document.createElement("span");
                showMoreLabel.style.cssText = "font-size: 11px; color: var(--secondary-text-color, #666);";
                showMoreLabel.textContent = "Show " + remainingCount + " more events";

                span.addEventListener("click", function() {
                    loadMoreEvents(groupName, span);
                });

                spanContent.appendChild(showMoreLabel);
                span.appendChild(spanContent);

                return span;
            }

            /**
             * Toggle visibility of events in a group
             */
            function toggleGroupEvents(groupSpan, show) {
                var groupName = groupSpan.getAttribute("data-group-name");
                var container = groupSpan.parentNode;
                var collecting = false;

                for (var i = 0; i < container.children.length; i++) {
                    var child = container.children[i];

                    if (child === groupSpan) {
                        collecting = true;
                        continue;
                    }

                    if (collecting) {
                        // Stop if we hit another group
                        if (child.classList.contains("group-span")) {
                            break;
                        }

                        var childGroup = child.getAttribute("data-group");
                        if (childGroup && childGroup.includes(groupName.split("-")[0])) {
                            child.style.display = show ? "block" : "none";
                        }
                    }
                }
            }

            /**
             * Load more events for a confidence group
             */
            function loadMoreEvents(groupName, showMoreSpan) {
                // This would load more events dynamically
                // For now, just hide the show more button
                showMoreSpan.style.display = "none";
            }

            // Store timeline data globally for access in other functions
            window.eventTimelineData = eventTimelineData;

        })();
    '
];

/**
 * AnalistHost helper functions for Host Info tab - adapted from ProblemKanban
 */

function makeAnalistHostSectionsHeader(array $host): CDiv {
    $host_status = '';
    $maintenance_status = '';
    $problems_indicator = '';

    if ($host['status'] == HOST_STATUS_MONITORED) {
        if ($host['maintenance_status'] == HOST_MAINTENANCE_STATUS_ON) {
            $maintenance_status = makeMaintenanceIcon($host['maintenance_type'], $host['maintenance']['name'],
                $host['maintenance']['description']
            );
        }

        $problems = [];

        if (isset($host['problem_count'])) {
            foreach ($host['problem_count'] as $severity => $count) {
                if ($count > 0) {
                    $problems[] = (new CSpan($count))
                        ->addClass(ZBX_STYLE_PROBLEM_ICON_LIST_ITEM)
                        ->addClass(CSeverityHelper::getStatusStyle($severity))
                        ->setTitle(CSeverityHelper::getName($severity));
                }
            }
        }

        if ($problems) {
            $problems_indicator = CWebUser::checkAccess(CRoleHelper::UI_MONITORING_PROBLEMS)
                ? new CLink(null,
                    (new CUrl('zabbix.php'))
                        ->setArgument('action', 'problem.view')
                        ->setArgument('hostids', [$host['hostid']])
                        ->setArgument('filter_set', '1')
                )
                : new CSpan();

            $problems_indicator
                ->addClass(ZBX_STYLE_PROBLEM_ICON_LINK)
                ->addItem($problems);
        }
    }
    else {
        $host_status = (new CDiv(_('Disabled')))->addClass(ZBX_STYLE_COLOR_NEGATIVE);
    }

    return (new CDiv([
        (new CDiv([
            (new CDiv([
                (new CLinkAction($host['name']))
                    ->setTitle($host['name'])
                    ->setMenuPopup(CMenuPopupHelper::getHost($host['hostid'])),
                $host_status,
                $maintenance_status
            ]))->addClass('host-name-container'),
            $problems_indicator ? (new CDiv($problems_indicator))->addClass('problems-container') : null
        ]))->addClass('host-header-main')
    ]))->addClass('analisthost-sections-header');
}

function makeAnalistHostSectionHostGroups(array $host_groups): CDiv {
    $groups = [];

    $i = 0;
    $group_count = count($host_groups);

    foreach ($host_groups as $group) {
        $groups[] = (new CSpan([
            (new CSpan($group['name']))
                ->addClass('host-group-name')
                ->setTitle($group['name']),
            ++$i < $group_count ? (new CSpan(', '))->addClass('delimiter') : null
        ]))->addClass('host-group');
    }

    if ($groups) {
        $groups[] = (new CLink(new CIcon('zi-more')))
            ->addClass(ZBX_STYLE_LINK_ALT)
            ->setHint(implode(', ', array_column($host_groups, 'name')), ZBX_STYLE_HINTBOX_WRAP);
    }

    return (new CDiv([
        (new CDiv(_('Host groups')))->addClass('analisthost-section-name'),
        (new CDiv($groups))
            ->addClass('analisthost-section-body')
            ->addClass('host-groups')
    ]))
        ->addClass('analisthost-section')
        ->addClass('section-host-groups');
}

function makeAnalistHostSectionDescription(string $description): CDiv {
    return (new CDiv([
        (new CDiv(_('Description')))->addClass('analisthost-section-name'),
        (new CDiv($description))
            ->addClass(ZBX_STYLE_LINE_CLAMP)
            ->addClass('analisthost-section-body')
            ->setTitle($description)
    ]))
        ->addClass('analisthost-section')
        ->addClass('section-description')
        ->addStyle('max-width: 100%; overflow: hidden;');
}

function makeAnalistHostSectionMonitoring(string $hostid, int $dashboard_count, int $item_count, int $graph_count,
        int $web_scenario_count): CDiv {
    $can_view_monitoring_hosts = CWebUser::checkAccess(CRoleHelper::UI_MONITORING_HOSTS);

    return (new CDiv([
        (new CDiv(_('Monitoring')))->addClass('analisthost-section-name'),
        (new CDiv([
            (new CDiv([
                $can_view_monitoring_hosts && $dashboard_count > 0
                    ? (new CLink(_('Dashboards'),
                        (new CUrl('zabbix.php'))
                            ->setArgument('action', 'host.dashboard.view')
                            ->setArgument('hostid', $hostid)
                    ))
                        ->addClass('monitoring-item-name')
                        ->setTitle(_('Dashboards'))
                    : (new CSpan(_('Dashboards')))
                        ->addClass('monitoring-item-name')
                        ->setTitle(_('Dashboards')),
                (new CSpan($dashboard_count))
                    ->addClass(ZBX_STYLE_ENTITY_COUNT)
                    ->setTitle($dashboard_count)
            ]))->addClass('monitoring-item'),
            (new CDiv([
                $can_view_monitoring_hosts && $graph_count > 0
                    ? (new CLink(_('Graphs'),
                        (new CUrl('zabbix.php'))
                            ->setArgument('action', 'charts.view')
                            ->setArgument('filter_hostids', [$hostid])
                            ->setArgument('filter_show', GRAPH_FILTER_HOST)
                            ->setArgument('filter_set', '1')
                    ))
                        ->addClass('monitoring-item-name')
                        ->setTitle(_('Graphs'))
                    : (new CSpan(_('Graphs')))
                        ->addClass('monitoring-item-name')
                        ->setTitle(_('Graphs')),
                (new CSpan($graph_count))
                    ->addClass(ZBX_STYLE_ENTITY_COUNT)
                    ->setTitle($graph_count)
            ]))->addClass('monitoring-item'),
            (new CDiv([
                CWebUser::checkAccess(CRoleHelper::UI_MONITORING_LATEST_DATA) && $item_count > 0
                    ? (new CLink(_('Latest data'),
                        (new CUrl('zabbix.php'))
                            ->setArgument('action', 'latest.view')
                            ->setArgument('hostids', [$hostid])
                            ->setArgument('filter_set', '1')
                    ))
                        ->addClass('monitoring-item-name')
                        ->setTitle(_('Latest data'))
                    : (new CSpan(_('Latest data')))
                        ->addClass('monitoring-item-name')
                        ->setTitle(_('Latest data')),
                (new CSpan($item_count))
                    ->addClass(ZBX_STYLE_ENTITY_COUNT)
                    ->setTitle($item_count)
            ]))->addClass('monitoring-item'),
            (new CDiv([
                $can_view_monitoring_hosts && $web_scenario_count > 0
                    ? (new CLink(_('Web'),
                        (new CUrl('zabbix.php'))
                            ->setArgument('action', 'web.view')
                            ->setArgument('filter_hostids', [$hostid])
                            ->setArgument('filter_set', '1')
                    ))
                        ->addClass('monitoring-item-name')
                        ->setTitle(_('Web scenarios'))
                    : (new CSpan(_('Web')))
                        ->addClass('monitoring-item-name')
                        ->setTitle(_('Web scenarios')),
                (new CSpan($web_scenario_count))
                    ->addClass(ZBX_STYLE_ENTITY_COUNT)
                    ->setTitle($web_scenario_count)
            ]))->addClass('monitoring-item')
        ]))
            ->addClass('analisthost-section-body')
            ->addClass('monitoring')
    ]))
        ->addClass('analisthost-section')
        ->addClass('section-monitoring');
}

function makeAnalistHostSectionAvailability(array $interfaces): CDiv {
    // Criar container para os indicadores de interface
    $indicators = new CDiv();
    $indicators->addClass('interface-indicators');
    
    // Definir os tipos de interface e seus labels
    $interface_types = [
        INTERFACE_TYPE_AGENT => 'ZBX',
        INTERFACE_TYPE_SNMP => 'SNMP', 
        INTERFACE_TYPE_IPMI => 'IPMI',
        INTERFACE_TYPE_JMX => 'JMX'
    ];
    
    // Definir cores baseadas no status
    $status_colors = [
        INTERFACE_AVAILABLE_UNKNOWN => 'status-grey',
        INTERFACE_AVAILABLE_TRUE => 'status-green',
        INTERFACE_AVAILABLE_FALSE => 'status-red',
        INTERFACE_AVAILABLE_MIXED => 'status-yellow'
    ];
    
    // Agrupar interfaces por tipo
    $type_interfaces = [];
    foreach ($interfaces as $interface) {
        if (isset($interface['type']) && isset($interface['available'])) {
            $type_interfaces[$interface['type']][] = $interface;
        }
    }
    
    // Processar cada tipo de interface
    foreach ($interface_types as $type => $label) {
        if (isset($type_interfaces[$type]) && $type_interfaces[$type]) {
            // Determinar o status geral para este tipo
            $statuses = array_column($type_interfaces[$type], 'available');
            $overall_status = INTERFACE_AVAILABLE_TRUE;
            
            if (in_array(INTERFACE_AVAILABLE_FALSE, $statuses)) {
                $overall_status = INTERFACE_AVAILABLE_FALSE;
            } elseif (in_array(INTERFACE_AVAILABLE_UNKNOWN, $statuses)) {
                $overall_status = INTERFACE_AVAILABLE_UNKNOWN;
            }
            
            // Criar o badge/indicador
            $indicator = (new CSpan($label))
                ->addClass('interface-indicator')
                ->addClass($status_colors[$overall_status]);
            
            // Adicionar hint com detalhes das interfaces
            $hint_table = new CTableInfo();
            $hint_table->setHeader([_('Interface'), _('Status'), _('Error')]);
            
            foreach ($type_interfaces[$type] as $interface) {
                $interface_text = '';
                if (isset($interface['ip']) && $interface['ip']) {
                    $interface_text = $interface['ip'];
                    if (isset($interface['port'])) {
                        $interface_text .= ':' . $interface['port'];
                    }
                } elseif (isset($interface['dns']) && $interface['dns']) {
                    $interface_text = $interface['dns'];
                    if (isset($interface['port'])) {
                        $interface_text .= ':' . $interface['port'];
                    }
                }
                
                $status_text = [
                    INTERFACE_AVAILABLE_UNKNOWN => _('Unknown'),
                    INTERFACE_AVAILABLE_TRUE => _('Available'),
                    INTERFACE_AVAILABLE_FALSE => _('Not available')
                ];
                
                $hint_table->addRow([
                    $interface_text,
                    (new CSpan($status_text[$interface['available']]))
                        ->addClass($status_colors[$interface['available']]),
                    isset($interface['error']) ? $interface['error'] : ''
                ]);
            }
            
            $indicator->setHint($hint_table);
            $indicators->addItem($indicator);
        }
    }
    
    // Se não houver interfaces, mostrar um indicador padrão
    if ($indicators->items === null || count($indicators->items) === 0) {
        $indicators->addItem(
            (new CSpan('N/A'))
                ->addClass('interface-indicator')
                ->addClass('status-grey')
        );
    }
    
    return (new CDiv([
        (new CDiv(_('Availability')))->addClass('analisthost-section-name'),
        (new CDiv($indicators))->addClass('analisthost-section-body')
    ]))
        ->addClass('analisthost-section')
        ->addClass('section-availability');
}

function makeAnalistHostSectionMonitoredBy(array $host): CDiv {
    switch ($host['monitored_by']) {
        case ZBX_MONITORED_BY_SERVER:
            $monitored_by = [
                new CIcon('zi-server', _('Zabbix server')),
                _('Zabbix server')
            ];
            break;

        case ZBX_MONITORED_BY_PROXY:
            $proxy_url = (new CUrl('zabbix.php'))
                ->setArgument('action', 'popup')
                ->setArgument('popup', 'proxy.edit')
                ->setArgument('proxyid', $host['proxyid'])
                ->getUrl();

            $proxy = CWebUser::checkAccess(CRoleHelper::UI_ADMINISTRATION_PROXIES)
                ? new CLink($host['proxy']['name'], $proxy_url)
                : new CSpan($host['proxy']['name']);

            $proxy->setTitle($host['proxy']['name']);

            $monitored_by = [
                new CIcon('zi-proxy', _('Proxy')),
                $proxy
            ];
            break;

        case ZBX_MONITORED_BY_PROXY_GROUP:
            $proxy_group_url = (new CUrl('zabbix.php'))
                ->setArgument('action', 'popup')
                ->setArgument('popup', 'proxygroup.edit')
                ->setArgument('proxy_groupid', $host['proxy_groupid'])
                ->getUrl();

            $proxy_group = CWebUser::checkAccess(CRoleHelper::UI_ADMINISTRATION_PROXY_GROUPS)
                ? new CLink($host['proxy_group']['name'], $proxy_group_url)
                : new CSpan($host['proxy_group']['name']);

            $proxy_group->setTitle($host['proxy_group']['name']);

            $monitored_by = [
                new CIcon('zi-proxy', _('Proxy group')),
                $proxy_group
            ];
    }

    return (new CDiv([
        (new CDiv(_('Monitored by')))->addClass('analisthost-section-name'),
        (new CDiv($monitored_by))->addClass('analisthost-section-body')
    ]))
        ->addClass('analisthost-section')
        ->addClass('section-monitored-by');
}

function makeAnalistHostSectionTemplates(array $host_templates): CDiv {
    $templates = [];
    $hint_templates = [];

    foreach ($host_templates as $template) {
        $template_fullname = $template['parentTemplates']
            ? $template['name'].' ('.implode(', ', array_column($template['parentTemplates'], 'name')).')'
            : $template['name'];

        $templates[] = (new CSpan($template['name']))
            ->addClass('template')
            ->addClass('template-name')
            ->setTitle($template_fullname);

        $hint_templates[] = $template_fullname;
    }

    if ($templates) {
        $templates[] = (new CLink(new CIcon('zi-more')))
            ->addClass(ZBX_STYLE_LINK_ALT)
            ->setHint(implode(', ', $hint_templates), ZBX_STYLE_HINTBOX_WRAP);
    }

    return (new CDiv([
        (new CDiv(_('Templates')))->addClass('analisthost-section-name'),
        (new CDiv($templates))
            ->addClass('analisthost-section-body')
            ->addClass('templates')
            ->addStyle('
                max-width: 100%; 
                overflow: hidden; 
                display: flex; 
                flex-wrap: wrap; 
                align-items: center;
                gap: 4px;
            ')
    ]))
        ->addClass('analisthost-section')
        ->addClass('section-templates')
        ->addStyle('max-width: 100%; overflow: hidden;');
}

function makeAnalistHostSectionInventory(string $hostid, array $host_inventory, array $inventory_fields): CDiv {
    $inventory_list = [];
    $all_inventory_fields = [];
    $visible_count = 0;
    $max_visible = 3;

    if ($host_inventory) {
        // Coletamos todos os campos de inventário primeiro
        foreach (getHostInventories() as $inventory) {
            if ((!$inventory_fields && $host_inventory[$inventory['db_field']] === '') ||
                ($inventory_fields && !array_key_exists($inventory['db_field'], $host_inventory))) {
                continue;
            }
            
            $all_inventory_fields[] = [
                'title' => $inventory['title'],
                'value' => $host_inventory[$inventory['db_field']]
            ];
        }
        
        // Mostrar apenas os primeiros 3 campos
        foreach ($all_inventory_fields as $index => $field) {
            if ($visible_count >= $max_visible) {
                break;
            }

            // Campo do inventário (nome)
            $inventory_list[] = (new CDiv($field['title']))
                ->addClass('inventory-field-name')
                ->setTitle($field['title'])
                ->addStyle('font-weight: bold; color: #666; margin-bottom: 2px;');
            
            // Valor do inventário com quebra de linha para textos longos
            $inventory_list[] = (new CDiv($field['value']))
                ->addClass('inventory-field-value')
                ->setTitle($field['value']);
                
            $visible_count++;
        }
        
        // Se há mais campos, adicionar botão "more"
        if (count($all_inventory_fields) > $max_visible) {
            $remaining_fields = array_slice($all_inventory_fields, $max_visible);
            $hint_content = [];
            
            foreach ($remaining_fields as $field) {
                $hint_content[] = $field['title'] . ': ' . $field['value'];
            }
            
            $inventory_list[] = (new CLink(new CIcon('zi-more')))
                ->addClass(ZBX_STYLE_LINK_ALT)
                ->setHint(implode("\n\n", $hint_content), ZBX_STYLE_HINTBOX_WRAP)
                ->addStyle('margin-top: 8px; display: inline-block;');
        }
    }

    return (new CDiv([
        (new CDiv(
            CWebuser::checkAccess(CRoleHelper::UI_INVENTORY_HOSTS)
                ? new CLink(_('Inventory'), (new CUrl('hostinventories.php'))->setArgument('hostid', $hostid))
                : _('Inventory')
        ))->addClass('analisthost-section-name'),
        (new CDiv($inventory_list))
            ->addClass('analisthost-section-body')
            ->addStyle('max-width: 100%; overflow: hidden;') // Previne overflow horizontal
    ]))
        ->addClass('analisthost-section')
        ->addClass('section-inventory')
        ->addStyle('max-width: 100%; overflow: hidden;'); // Previne overflow no container principal
}

function makeAnalistHostSectionTags(array $host_tags): CDiv {
    $tags = [];

    foreach ($host_tags as $tag) {
        $tag_text = $tag['tag'].($tag['value'] === '' ? '' : ': '.$tag['value']);
        $tags[] = (new CSpan($tag_text))->addClass('tag');
    }

    return (new CDiv([
        (new CDiv(_('Tags')))->addClass('analisthost-section-name'),
        (new CDiv($tags))
            ->addClass('analisthost-section-body')
            ->addStyle('
                max-width: 100%; 
                overflow: hidden; 
                display: flex; 
                flex-wrap: wrap;
                gap: 4px;
                align-items: center;
            ')
    ]))
        ->addClass('analisthost-section')
        ->addClass('section-tags')
        ->addStyle('max-width: 100%; overflow: hidden;');
}

if (isset($data['user']['debug_mode']) && $data['user']['debug_mode'] == 1) {
    if (class_exists('CProfiler')) {
        CProfiler::getInstance()->stop();
        $output['debug'] = CProfiler::getInstance()->make()->toString();
    }
}

echo json_encode($output);
