<?php declare(strict_types = 0);

require_once dirname(__FILE__).'/../../../include/events.inc.php';
require_once dirname(__FILE__).'/../../../include/actions.inc.php';
require_once dirname(__FILE__).'/../../../include/users.inc.php';
$this->addJsFile('layout.mode.js');
$this->addJsFile('gtlc.js');
$this->addJsFile('class.calendar.js');

$event = $data['event'] ?? [];
$trigger = $data['trigger'] ?? null;
$host = $data['host'] ?? null;
$related_events = $data['related_events'] ?? [];
$six_months_events = $data['six_months_events'] ?? [];
$items = $data['items'] ?? [];
$monthly_comparison = $data['monthly_comparison'] ?? [];
$system_metrics = $data['system_metrics'] ?? [];
$maintenances = $data['maintenances'] ?? [];

$event_time = isset($event['clock']) ? zbx_date2str(DATE_TIME_FORMAT_SECONDS, $event['clock']) : '';
$event_date = isset($event['clock']) ? zbx_date2str('Y-m-d', $event['clock']) : '';
$time_ago = isset($event['clock']) ? zbx_date2age($event['clock']) : '';

$severity = isset($event['severity']) ? (int) $event['severity'] : 0;
$severity_name = CSeverityHelper::getName($severity);
$severity_color = CSeverityHelper::getColor($severity);

function createEssentialMetricsTable($metrics) {
    $table = new CTableInfo();
    $table->setHeader([_('Metric'), _('Last Value')]);
    
    if (empty($metrics)) {
        $no_data_row = new CRow([
            new CCol(_('No system metrics available'), null, 2)
        ]);
        $no_data_row->addClass('mnz-system-metrics-more');
        $table->addRow($no_data_row);
        return $table;
    }
    
    foreach ($metrics as $metric) {
        $last_value = $metric['last_value'];
        $units = $metric['units'] ?? '';

        if (is_numeric($last_value)) {
            
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

$tabs = new CTabView();

$overview_table = new CTableInfo();
$overview_table->setHeader([_('Property'), _('Value')]);

$overview_table->addRow([_('Event ID'), $event['eventid'] ?? 'N/A']);
$overview_table->addRow([_('Problem name'), $event['name'] ?? 'Unknown Problem']);
$overview_table->addRow([_('Host'), $host ? ($host['name'] ?? $host['host'] ?? 'Unknown') : 'N/A']);
$overview_table->addRow([_('Severity'), 
    (new CSpan($severity_name))
        ->addClass(CSeverityHelper::getStyle($severity))
        ->addClass('mnz-problemanalist-severity-text')
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

$metrics_section = null;
if (!empty($system_metrics) && $system_metrics['available'] && $system_metrics['type'] === 'agent') {
    $metrics_section = new CDiv();
    $metrics_section->addClass('mnz-system-metrics-section');
    
    $metrics_section->addItem(new CTag('h4', false, _('Last value')));

    $metrics_table = createEssentialMetricsTable($system_metrics['categories']);
    $metrics_section->addItem($metrics_table);
}

$overview_container = (new CDiv())->addClass('mnz-overview-container');

$top_sections_container = new CDiv();
$top_sections_container->addClass('mnz-overview-top-sections');
$top_sections_container->addStyle('display: flex; gap: 12px; margin-bottom: 8px;');

if ($metrics_section) {
    $metrics_section->addStyle('flex: 1; min-width: 300px;');
    $top_sections_container->addItem($metrics_section);
}

if ($metrics_section) {
    $overview_container->addItem($top_sections_container);
}

$overview_main = new CDiv();
$overview_main->addClass('mnz-overview-main');
$overview_main->addItem($overview_table);

$analytics = $data['analytics_data'] ?? [];
$analytics_form = new CFormList('mnz-analytics-form');
$analytics_form->addRow(
    (new CLabel([_('MTTR'), makeHelpIcon(_('Mean time to resolve based on last 90 days.'))], null)),
    (new CDiv($analytics['mttr']['display'] ?? _('No data')))->addClass(ZBX_STYLE_WORDBREAK)
);
$analytics_form->addRow(
    (new CLabel([_('Recurrence'), makeHelpIcon(_('Frequency compared to historical average.'))], null)),
    (new CDiv($analytics['recurrence']['display'] ?? _('No data')))->addClass(ZBX_STYLE_WORDBREAK)
);
$analytics_form->addRow(
    (new CLabel([_('Peak time'), makeHelpIcon(_('Window when problem occurs most often.'))], null)),
    (new CDiv($analytics['patterns']['peak_time'] ?? _('No pattern')))->addClass(ZBX_STYLE_WORDBREAK)
);
$analytics_form->addRow(
    (new CLabel([_('Trend'), makeHelpIcon(_('Last 30 days vs previous 60 days.'))], null)),
    (new CDiv($analytics['patterns']['trend'] ?? _('Unknown')))->addClass(ZBX_STYLE_WORDBREAK)
);
$analytics_form->addRow(
    (new CLabel([_('SLA risk'), makeHelpIcon(_('Probability of breach at current pace.'))], null)),
    (new CDiv($analytics['sla_risk']['display'] ?? _('Unable to calculate')))->addClass(ZBX_STYLE_WORDBREAK)
);
$analytics_div = (new CDiv($analytics_form))->addClass('mnz-problemanalist-analytics');
$analytics_wrapper = (new CDiv())->addClass('mnz-overview-analytics-wrapper');
$analytics_wrapper->addItem((new CTag('h4', false, _('Analytics & History')))->addClass('mnz-overview-analytics-title'));
$analytics_wrapper->addItem($analytics_div);
$overview_main->addItem($analytics_wrapper);
$overview_container->addItem($overview_main);

$host_div = new CDiv();

if (!$host) {
    $host_div->addItem(new CDiv(_('Host information not available')));
}

if ($host && is_array($host)) {
    
    $col_left = [];
    $col_right = [];
    
    $col_left[] = makeAnalistHostSectionMonitoring($host['hostid'], $host['dashboard_count'] ?? 0, 
        $host['item_count'] ?? 0, $host['graph_count'] ?? 0, $host['web_scenario_count'] ?? 0
    );
    
    if (!empty($host['hostgroups'])) {
        $col_left[] = makeAnalistHostSectionHostGroups($host['hostgroups']);
    }
    
    $col_right[] = makeAnalistHostSectionAvailability($host['interfaces'] ?? []);
    $col_right[] = makeAnalistHostSectionMonitoredBy($host);
    
    if (!empty($host['tags'])) {
        $col_left[] = makeAnalistHostSectionTags($host['tags']);
    }
    
    if (!empty($host['templates'])) {
        $col_left[] = makeAnalistHostSectionTemplates($host['templates']);
    }
    
    if (CWebUser::checkAccess(CRoleHelper::UI_INVENTORY_HOSTS)) {
        $col_right[] = makeAnalistHostSectionInventory($host['hostid'], $host['inventory'] ?? [], []);
    }

    $sections_container = new CDiv();
    $sections_container->addClass('mnz-problemanalist-host-sections mnz-problemanalist-host-sections-grid');
    
    if (!empty($host['description'])) {
        $sections_container->addItem(
            (new CDiv(makeAnalistHostSectionDescription($host['description'])))->addClass('mnz-problemanalist-host-row-full')
        );
    }
    
    $cols_wrapper = new CDiv();
    $cols_wrapper->addClass('mnz-problemanalist-host-cols');
    if (!empty($col_left)) {
        $left_div = new CDiv($col_left);
        $left_div->addClass('mnz-problemanalist-host-col mnz-problemanalist-host-col-left');
        $cols_wrapper->addItem($left_div);
    }
    if (!empty($col_right)) {
        $right_div = new CDiv($col_right);
        $right_div->addClass('mnz-problemanalist-host-col mnz-problemanalist-host-col-right');
        $cols_wrapper->addItem($right_div);
    }
    $sections_container->addItem($cols_wrapper);

    $body = (new CDiv([
        makeAnalistHostSectionsHeader($host),
        $sections_container
    ]))->addClass('mnz-problemanalist-host-container');
    
    $host_div->addItem($body);
} else {
    $host_div->addItem(new CDiv(_('Host information not available')));
}

$tabs->addTab('host', _('Host Info'), $host_div);

$mnz_timeperiod_type_names = [
    TIMEPERIOD_TYPE_ONETIME => _('One time only'),
    TIMEPERIOD_TYPE_HOURLY => _('Hourly'),
    TIMEPERIOD_TYPE_DAILY => _('Daily'),
    TIMEPERIOD_TYPE_WEEKLY => _('Weekly'),
    TIMEPERIOD_TYPE_MONTHLY => _('Monthly'),
    TIMEPERIOD_TYPE_YEARLY => _('Yearly')
];
$maintenance_div = new CDiv();
$maintenance_div->addClass('mnz-maintenance-tab');
$maintenance_table = new CTableInfo();
$maintenance_table->setHeader([
    '',
    _('Name'),
    _('Start'),
    _('End'),
    _('Period'),
    _('Duration')
]);
if (empty($maintenances)) {
    $maintenance_table->addRow([
        (new CCol(_('No maintenance periods found for this host.')))->setColSpan(6)
    ]);
} else {
    foreach ($maintenances as $m) {
        $icon = makeMaintenanceIcon(
            (int)($m['maintenance_type'] ?? 0),
            $m['name'] ?? '',
            $m['description'] ?? ''
        );
        $period_labels = [];
        $duration_labels = [];
        foreach ($m['timeperiods'] ?? [] as $tp) {
            $tid = (int)($tp['timeperiod_type'] ?? TIMEPERIOD_TYPE_ONETIME);
            $period_labels[] = $mnz_timeperiod_type_names[$tid] ?? _('Unknown');
            $dur_sec = (int)($tp['period'] ?? 0);
            $duration_labels[] = $dur_sec > 0 ? convertUnitsS($dur_sec) : '-';
        }
        $period_str = !empty($period_labels) ? implode(', ', $period_labels) : '-';
        $duration_str = !empty($duration_labels) ? implode(', ', $duration_labels) : '-';
        $maintenance_table->addRow([
            (new CCol($icon))->addClass('mnz-maintenance-icon-cell'),
            new CCol($m['name'] ?? '-'),
            new CCol(zbx_date2str(DATE_TIME_FORMAT_SECONDS, $m['active_since'] ?? 0)),
            new CCol(zbx_date2str(DATE_TIME_FORMAT_SECONDS, $m['active_till'] ?? 0)),
            new CCol($period_str),
            new CCol($duration_str)
        ]);
    }
}
$maintenance_div->addItem($maintenance_table);
$tabs->addTab('maintenance', _('Maintenance'), $maintenance_div);

$tabs->addTab('overview', _('Overview'), $overview_container);

$time_patterns_div = new CDiv();
$time_patterns_div->addClass('mnz-problemanalist-patterns');

$pattern_events = !empty($six_months_events) ? $six_months_events : $related_events;

$hourly_data = array_fill(0, 24, 0);
$weekly_data = array_fill(0, 7, 0);
$weekly_hourly_details = array_fill(0, 7, array_fill(0, 24, 0));

$weekly_hourly_details = [];
for ($wi = 0; $wi < 7; $wi++) {
    $weekly_hourly_details[$wi] = array_fill(0, 24, 0);
}

foreach ($pattern_events as $rel_event) {
    $hour = (int)date('G', $rel_event['clock']);
    $weekday = (int)date('w', $rel_event['clock']);
    $hourly_data[$hour]++;
    $weekly_data[$weekday]++;
    $weekly_hourly_details[$weekday][$hour]++;
}

$month_keys = [];
$monthly_values = [];
$monthly_labels = [];
$current_time = time();
for ($i = 11; $i >= 0; $i--) {
    $ts = strtotime("-$i months", $current_time);
    $month_keys[] = date('Y-m', $ts);
}
$monthly_counts = array_fill_keys($month_keys, 0);
foreach ($pattern_events as $rel_event) {
    $mk = date('Y-m', $rel_event['clock']);
    if (isset($monthly_counts[$mk])) {
        $monthly_counts[$mk]++;
    }
}
foreach ($month_keys as $mk) {
    $monthly_values[] = $monthly_counts[$mk] ?? 0;
    $monthly_labels[] = zbx_date2str('M/y', strtotime($mk . '-01'));
}
$month_keys_js = $month_keys;

$weekdays = [_('Sun'), _('Mon'), _('Tue'), _('Wed'), _('Thu'), _('Fri'), _('Sat')];
$user_lang = (CWebUser::$data && isset(CWebUser::$data['lang'])) ? CWebUser::$data['lang'] : null;
$force_24h = in_array($user_lang, ['pt_BR', 'pt_PT']);
$use_12h = !$force_24h && (strpos(TIME_FORMAT, 'A') !== false || strpos(TIME_FORMAT, 'a') !== false);

$hour_labels = [];
for ($h = 0; $h < 24; $h++) {
    $ts_utc = gmmktime($h, 0, 0, 1, 1, 2024);
    $hour_labels[] = $use_12h ? zbx_date2str('ga', $ts_utc, 'UTC') : (zbx_date2str('H', $ts_utc, 'UTC') . _x('h', 'hour short'));
}

$mnz_filter_bar = (new CDiv())->setId('mnz-problemanalist-filter-bar')->addClass('mnz-problemanalist-filter-bar');

$mnz_heatmap_container = (new CDiv())
    ->setId('mnz-problemanalist-heatmap')
    ->addClass('mnz-pa-heatmap-container');

$mnz_heatmap_legend = (new CDiv([
    (new CTag('span', true, _('Low')))->addClass('mnz-pa-heatmap-legend-label'),
    (new CDiv())->addClass('mnz-pa-heatmap-legend-bar'),
    (new CTag('span', true, _('High')))->addClass('mnz-pa-heatmap-legend-label')
]))->addClass('mnz-pa-heatmap-legend');

$monthly_chart_container = (new CDiv())
    ->addClass('mnz-monthly-bars-container')
    ->addItem(new CTag('h4', true, _('Incidents per month')))->addClass('mnz-problemanalist-section-title')
    ->addItem((new CDiv(_('Click a bar to filter charts')))->addClass('mnz-problemanalist-hint'))
    ->addItem((new CDiv())->setId('mnz-monthly-bars-chart')->addClass('mnz-monthly-bars-chart'));

$mnz_heatmap_block = (new CDiv([
    $mnz_heatmap_container,
    $mnz_heatmap_legend
]))->addClass('mnz-pa-heatmap-block');

$mnz_heatmap_section = (new CDiv([
    (new CTag('h4', true, _('Incident density (day × hour)')))->addClass('mnz-problemanalist-section-title'),
    (new CDiv(_('Click a cell to filter all charts. Green = fewer incidents. Red = more incidents.')))->addClass('mnz-problemanalist-hint'),
    $mnz_filter_bar,
    (new CDiv([
        $mnz_heatmap_block,
        $monthly_chart_container
    ]))->addClass('mnz-pa-patterns-row')
]))->addClass('mnz-problemanalist-heatmap-section');

$time_patterns_div->addItem($mnz_heatmap_section);

$tabs->addTab('patterns', _('Time Patterns'), $time_patterns_div);

$graphs_div = new CDiv();

if ($items && isset($event['clock'])) {
    
    $event_timestamp = $event['clock'];
    $from_timestamp = $event_timestamp - 3600; 
    $from_time = date('Y-m-d H:i:s', $from_timestamp);
    $to_time = 'now';

    $charts_container = new CDiv();
    $charts_container->addClass('mnz-charts-container');

    $items_count = count($items);
    $period_info = new CDiv(
        $items_count == 1 
            ? sprintf(_('Showing data from %s to now (1 hour before incident)'), $from_time)
            : sprintf(_('Showing data from %s to now (1 hour before incident)'), $from_time, $items_count)
    );
    $period_info->addClass('mnz-period-info');
    $charts_container->addItem($period_info);

    $processed_items = [];
    $unique_itemids = [];
    $item_names = [];

    foreach ($items as $item) {
        if (!isset($processed_items[$item['itemid']])) {
            $processed_items[$item['itemid']] = true;
            $unique_itemids[] = $item['itemid'];
            $item_names[] = $item['name'];
        }
    }
    
    if (!empty($unique_itemids)) {
        $chart_div = new CDiv();
        $chart_div->addClass('mnz-chart-item');

        $title_text = count($item_names) == 1 
            ? $item_names[0] 
            : _('Combined metrics') . ' (' . count($item_names) . ' ' . _('items') . ')';
        $title = new CTag('h5', false, $title_text);
        $title->addClass('mnz-chart-title');
        $chart_div->addItem($title);

        $base_params = [
            'from' => $from_time,
            'to' => $to_time,
            'type' => 0,
            'resolve_macros' => 1,
            'width' => 950,
            'height' => 320,
            '_' => time()
        ];

        $chart_url = 'chart.php?' . http_build_query($base_params);

        foreach ($unique_itemids as $itemid) {
            $chart_url .= '&itemids[]=' . urlencode($itemid);
        }

        if (count($item_names) > 1) {
            $items_list = new CDiv();
            $items_list->addClass('mnz-chart-items-list');
            $items_list->addItem(_('Items') . ': ' . implode(', ', $item_names));
            $chart_div->addItem($items_list);
        }

        $chart_img = new CTag('img', true);
        $chart_img->setAttribute('src', $chart_url);
        $chart_img->setAttribute('alt', $title_text);
        $chart_img->setAttribute('title', _('Consolidated graph with') . ' ' . count($unique_itemids) . ' ' . _('items'));
        $chart_img->addClass('mnz-chart-image');
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

$timeline_div = (new CDiv())->addClass('mnz-timeline-scroll-area');

$allowed = [
    'add_comments' => CWebUser::checkAccess(CRoleHelper::ACTIONS_ADD_PROBLEM_COMMENTS),
    'change_severity' => CWebUser::checkAccess(CRoleHelper::ACTIONS_CHANGE_SEVERITY),
    'acknowledge' => CWebUser::checkAccess(CRoleHelper::ACTIONS_ACKNOWLEDGE_PROBLEMS),
    'suppress_problems' => CWebUser::checkAccess(CRoleHelper::ACTIONS_SUPPRESS_PROBLEMS),
    'close' => CWebUser::checkAccess(CRoleHelper::ACTIONS_CLOSE_PROBLEMS) && 
                     isset($trigger['manual_close']) && $trigger['manual_close'] == ZBX_TRIGGER_MANUAL_CLOSE_ALLOWED,
    'rank_change' => CWebUser::checkAccess(CRoleHelper::ACTIONS_CHANGE_PROBLEM_RANKING)
];

$timeline_table = make_small_eventlist($event, $allowed);

$timeline_div->addItem(new CTag('h4', false, _('Event list [previous 20]')));
$timeline_div->addItem($timeline_table);
$tabs->addTab('timeline', _('Timeline'), $timeline_div);

$services_div = new CDiv();

$loading_div = new CDiv(_('Loading services...'));
$loading_div->addClass('mnz-services-loading');
$loading_div->addStyle('text-align: center; padding: 20px; font-style: italic;');
$services_div->addItem($loading_div);

$services_tree_container = new CDiv();
$services_tree_container->setAttribute('id', 'services-tree-container');
$services_tree_container->addStyle('min-height: 100px;');
$services_div->addItem($services_tree_container);

$tabs->addTab('services', _('Services'), $services_div);

$event_name = $event['name'] ?? 'Unknown Event';

$tabs->setAttribute('id', 'event-details-tabs');

$output = [
    'header' => _('Event Details') . ': ' . $event_name,
    'body' => (new CDiv())
        ->addClass('event-details-popup mnz-problemanalist-modal')
        ->addItem($tabs)
        ->addItem((new CDiv(_('Developed by MonZphere')))->addClass('mnz-module-footer'))
        ->toString(),
    'buttons' => null,
    'script_inline' => (function(    ) use (
        $hourly_data, $weekly_data, $weekdays, $hour_labels, $pattern_events,
        $weekly_hourly_details, $event, $host, $trigger, $month_keys_js, $monthly_labels, $monthly_values
    ) {
        ob_start();
        include dirname(__FILE__).'/js/problemanalist.view.js.php';
        return ob_get_clean();
    })()
];

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
            ]))->addClass('mnz-host-name-container'),
            $problems_indicator ? (new CDiv($problems_indicator))->addClass('mnz-problems-container') : null
        ]))->addClass('mnz-host-header-main')
    ]))->addClass('mnz-problemanalist-host-header');
}

function makeAnalistHostSectionHostGroups(array $host_groups): CDiv {
    $groups = [];

    $i = 0;
    $group_count = count($host_groups);

    foreach ($host_groups as $group) {
        $groups[] = (new CSpan([
            (new CSpan($group['name']))
                ->addClass('mnz-host-group-name')
                ->setTitle($group['name']),
            ++$i < $group_count ? (new CSpan(', '))->addClass('mnz-delimiter') : null
        ]))->addClass('mnz-host-group');
    }

    if ($groups) {
        $groups[] = (new CLink(new CIcon('zi-more')))
            ->addClass(ZBX_STYLE_LINK_ALT)
            ->setHint(implode(', ', array_column($host_groups, 'name')), ZBX_STYLE_HINTBOX_WRAP);
    }

    return (new CDiv([
        (new CDiv(_('Host groups')))->addClass('mnz-problemanalist-host-section-name'),
        (new CDiv($groups))
            ->addClass('mnz-problemanalist-host-section-body')
            ->addClass('mnz-host-groups')
    ]))
        ->addClass('mnz-problemanalist-host-section')
        ->addClass('mnz-problemanalist-host-section-groups');
}

function makeAnalistHostSectionDescription(string $description): CDiv {
    return (new CDiv([
        (new CDiv(_('Description')))->addClass('mnz-problemanalist-host-section-name'),
        (new CDiv($description))
            ->addClass(ZBX_STYLE_LINE_CLAMP)
            ->addClass('mnz-problemanalist-host-section-body')
            ->setTitle($description)
    ]))
        ->addClass('mnz-problemanalist-host-section')
        ->addClass('mnz-problemanalist-host-section-description')
        ->addStyle('max-width: 100%; overflow: hidden;');
}

function makeAnalistHostSectionMonitoring(string $hostid, int $dashboard_count, int $item_count, int $graph_count,
        int $web_scenario_count): CDiv {
    $can_view_monitoring_hosts = CWebUser::checkAccess(CRoleHelper::UI_MONITORING_HOSTS);

    return (new CDiv([
        (new CDiv(_('Monitoring')))->addClass('mnz-problemanalist-host-section-name'),
        (new CDiv([
            (new CDiv([
                $can_view_monitoring_hosts && $dashboard_count > 0
                    ? (new CLink(_('Dashboards'),
                        (new CUrl('zabbix.php'))
                            ->setArgument('action', 'host.dashboard.view')
                            ->setArgument('hostid', $hostid)
                    ))
                        ->addClass('mnz-monitoring-item-name')
                        ->setTitle(_('Dashboards'))
                    : (new CSpan(_('Dashboards')))
                        ->addClass('mnz-monitoring-item-name')
                        ->setTitle(_('Dashboards')),
                (new CSpan($dashboard_count))
                    ->addClass(ZBX_STYLE_ENTITY_COUNT)
                    ->setTitle($dashboard_count)
            ]))->addClass('mnz-monitoring-item'),
            (new CDiv([
                $can_view_monitoring_hosts && $graph_count > 0
                    ? (new CLink(_('Graphs'),
                        (new CUrl('zabbix.php'))
                            ->setArgument('action', 'charts.view')
                            ->setArgument('filter_hostids', [$hostid])
                            ->setArgument('filter_show', GRAPH_FILTER_HOST)
                            ->setArgument('filter_set', '1')
                    ))
                        ->addClass('mnz-monitoring-item-name')
                        ->setTitle(_('Graphs'))
                    : (new CSpan(_('Graphs')))
                        ->addClass('mnz-monitoring-item-name')
                        ->setTitle(_('Graphs')),
                (new CSpan($graph_count))
                    ->addClass(ZBX_STYLE_ENTITY_COUNT)
                    ->setTitle($graph_count)
            ]))->addClass('mnz-monitoring-item'),
            (new CDiv([
                CWebUser::checkAccess(CRoleHelper::UI_MONITORING_LATEST_DATA) && $item_count > 0
                    ? (new CLink(_('Latest data'),
                        (new CUrl('zabbix.php'))
                            ->setArgument('action', 'latest.view')
                            ->setArgument('hostids', [$hostid])
                            ->setArgument('filter_set', '1')
                    ))
                        ->addClass('mnz-monitoring-item-name')
                        ->setTitle(_('Latest data'))
                    : (new CSpan(_('Latest data')))
                        ->addClass('mnz-monitoring-item-name')
                        ->setTitle(_('Latest data')),
                (new CSpan($item_count))
                    ->addClass(ZBX_STYLE_ENTITY_COUNT)
                    ->setTitle($item_count)
            ]))->addClass('mnz-monitoring-item'),
            (new CDiv([
                $can_view_monitoring_hosts && $web_scenario_count > 0
                    ? (new CLink(_('Web'),
                        (new CUrl('zabbix.php'))
                            ->setArgument('action', 'web.view')
                            ->setArgument('filter_hostids', [$hostid])
                            ->setArgument('filter_set', '1')
                    ))
                        ->addClass('mnz-monitoring-item-name')
                        ->setTitle(_('Web scenarios'))
                    : (new CSpan(_('Web')))
                        ->addClass('mnz-monitoring-item-name')
                        ->setTitle(_('Web scenarios')),
                (new CSpan($web_scenario_count))
                    ->addClass(ZBX_STYLE_ENTITY_COUNT)
                    ->setTitle($web_scenario_count)
            ]))->addClass('mnz-monitoring-item')
        ]))
            ->addClass('mnz-problemanalist-host-section-body')
            ->addClass('mnz-problemanalist-host-monitoring')
    ]))
        ->addClass('mnz-problemanalist-host-section')
        ->addClass('mnz-problemanalist-host-section-monitoring');
}

function makeAnalistHostSectionAvailability(array $interfaces): CDiv {
    
    $indicators = new CDiv();
    $indicators->addClass('mnz-interface-indicators');

    $interface_types = [
        INTERFACE_TYPE_AGENT => 'ZBX',
        INTERFACE_TYPE_SNMP => 'SNMP', 
        INTERFACE_TYPE_IPMI => 'IPMI',
        INTERFACE_TYPE_JMX => 'JMX'
    ];

    $status_colors = [
        INTERFACE_AVAILABLE_UNKNOWN => 'status-grey',
        INTERFACE_AVAILABLE_TRUE => 'status-green',
        INTERFACE_AVAILABLE_FALSE => 'status-red',
        INTERFACE_AVAILABLE_MIXED => 'status-yellow'
    ];

    $type_interfaces = [];
    foreach ($interfaces as $interface) {
        if (isset($interface['type']) && isset($interface['available'])) {
            $type_interfaces[$interface['type']][] = $interface;
        }
    }

    foreach ($interface_types as $type => $label) {
        if (isset($type_interfaces[$type]) && $type_interfaces[$type]) {
            
            $statuses = array_column($type_interfaces[$type], 'available');
            $overall_status = INTERFACE_AVAILABLE_TRUE;
            
            if (in_array(INTERFACE_AVAILABLE_FALSE, $statuses)) {
                $overall_status = INTERFACE_AVAILABLE_FALSE;
            } elseif (in_array(INTERFACE_AVAILABLE_UNKNOWN, $statuses)) {
                $overall_status = INTERFACE_AVAILABLE_UNKNOWN;
            }

            $indicator = (new CSpan($label))
                ->addClass('mnz-interface-indicator')
                ->addClass($status_colors[$overall_status]);

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

    if ($indicators->items === null || count($indicators->items) === 0) {
        $indicators->addItem(
            (new CSpan('N/A'))
                ->addClass('mnz-interface-indicator')
                ->addClass('status-grey')
        );
    }
    
    return (new CDiv([
        (new CDiv(_('Availability')))->addClass('mnz-problemanalist-host-section-name'),
        (new CDiv($indicators))->addClass('mnz-problemanalist-host-section-body')
    ]))
        ->addClass('mnz-problemanalist-host-section')
        ->addClass('mnz-problemanalist-host-section-availability');
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
        (new CDiv(_('Monitored by')))->addClass('mnz-problemanalist-host-section-name'),
        (new CDiv($monitored_by))->addClass('mnz-problemanalist-host-section-body')
    ]))
        ->addClass('mnz-problemanalist-host-section')
        ->addClass('mnz-problemanalist-host-section-monitored');
}

function makeAnalistHostSectionTemplates(array $host_templates): CDiv {
    $templates = [];
    $hint_templates = [];

    foreach ($host_templates as $template) {
        $template_fullname = $template['parentTemplates']
            ? $template['name'].' ('.implode(', ', array_column($template['parentTemplates'], 'name')).')'
            : $template['name'];

        $templates[] = (new CSpan($template['name']))
            ->addClass('mnz-template')
            ->addClass('mnz-template-name')
            ->setTitle($template_fullname);

        $hint_templates[] = $template_fullname;
    }

    if ($templates) {
        $templates[] = (new CLink(new CIcon('zi-more')))
            ->addClass(ZBX_STYLE_LINK_ALT)
            ->setHint(implode(', ', $hint_templates), ZBX_STYLE_HINTBOX_WRAP);
    }

    return (new CDiv([
        (new CDiv(_('Templates')))->addClass('mnz-problemanalist-host-section-name'),
        (new CDiv($templates))
            ->addClass('mnz-problemanalist-host-section-body')
            ->addClass('mnz-templates')
            ->addStyle('
                max-width: 100%; 
                overflow: hidden; 
                display: flex; 
                flex-wrap: wrap; 
                align-items: center;
                gap: 4px;
            ')
    ]))
        ->addClass('mnz-problemanalist-host-section')
        ->addClass('mnz-problemanalist-host-section-templates');
}

function makeAnalistHostSectionInventory(string $hostid, array $host_inventory, array $inventory_fields): CDiv {
    $inventory_url = (new CUrl('hostinventories.php'))->setArgument('hostid', $hostid);
    $inventory_list = [];
    $all_inventory_fields = [];
    $visible_count = 0;
    $max_visible = 3;

    if ($host_inventory) {
        foreach (getHostInventories() as $inventory) {
            if ((!$inventory_fields && ($host_inventory[$inventory['db_field']] ?? '') === '') ||
                ($inventory_fields && !array_key_exists($inventory['db_field'], $host_inventory))) {
                continue;
            }
            $all_inventory_fields[] = [
                'title' => $inventory['title'],
                'value' => $host_inventory[$inventory['db_field']] ?? ''
            ];
        }
        
        foreach ($all_inventory_fields as $index => $field) {
            if ($visible_count >= $max_visible) break;
            $inventory_list[] = (new CDiv($field['title']))
                ->addClass('mnz-inventory-field-name')
                ->setTitle($field['title']);
            $inventory_list[] = (new CDiv($field['value']))
                ->addClass('mnz-inventory-field-value')
                ->setTitle($field['value']);
            $visible_count++;
        }
        
        if (count($all_inventory_fields) > $max_visible) {
            $remaining_fields = array_slice($all_inventory_fields, $max_visible);
            $hint_content = [];
            foreach ($remaining_fields as $field) {
                $hint_content[] = $field['title'] . ': ' . $field['value'];
            }
            $inventory_list[] = (new CLink(new CIcon('zi-more')))
                ->addClass(ZBX_STYLE_LINK_ALT)
                ->setHint(implode("\n\n", $hint_content), ZBX_STYLE_HINTBOX_WRAP)
                ->addClass('mnz-inventory-more');
        }
    }

    $body_items = [];
    if ($inventory_list) {
        $body_items[] = (new CDiv($inventory_list))
            ->addClass('mnz-inventory-preview')
            ->addStyle('max-width: 100%; overflow: hidden;');
    }
    $body_items[] = (new CLink(_('View inventory'), $inventory_url->getUrl()))
        ->addClass('mnz-inventory-link')
        ->addClass(ZBX_STYLE_LINK_ALT);

    return (new CDiv([
        (new CDiv(_('Inventory')))->addClass('mnz-problemanalist-host-section-name'),
        (new CDiv($body_items))->addClass('mnz-problemanalist-host-section-body')
    ]))
        ->addClass('mnz-problemanalist-host-section')
        ->addClass('mnz-problemanalist-host-section-inventory')
        ->setAttribute('data-inventory-url', $inventory_url->getUrl());
}

function makeAnalistHostSectionTags(array $host_tags): CDiv {
    $tags = [];
    $max_visible = 3;
    $total = count($host_tags);
    $visible_tags = array_slice($host_tags, 0, $max_visible);

    foreach ($visible_tags as $tag) {
        $tag_text = $tag['tag'].($tag['value'] === '' ? '' : ': '.$tag['value']);
        $tags[] = (new CSpan($tag_text))->addClass('mnz-tag');
    }

    if ($total > $max_visible) {
        $hidden_list = [];
        foreach (array_slice($host_tags, $max_visible) as $tag) {
            $hidden_list[] = $tag['tag'].($tag['value'] === '' ? '' : ': '.$tag['value']);
        }
        
        $tags[] = (new CLink(new CIcon('zi-more')))
            ->addClass(ZBX_STYLE_LINK_ALT)
            ->addClass('mnz-tag-ellipsis')
            ->setHint(implode(', ', $hidden_list), ZBX_STYLE_HINTBOX_WRAP);
    }

    return (new CDiv([
        (new CDiv(_('Tags')))->addClass('mnz-problemanalist-host-section-name'),
        (new CDiv($tags))
            ->addClass('mnz-problemanalist-host-section-body')
    ]))
        ->addClass('mnz-problemanalist-host-section')
        ->addClass('mnz-problemanalist-host-section-tags');
}

if (isset($data['user']['debug_mode']) && $data['user']['debug_mode'] == 1) {
    if (class_exists('CProfiler')) {
        CProfiler::getInstance()->stop();
        $output['debug'] = CProfiler::getInstance()->make()->toString();
    }
}

echo json_encode($output);
