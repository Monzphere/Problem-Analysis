<?php

$pattern_events = $pattern_events ?? [];
$eventClocks = [];
foreach ($pattern_events as $pe) {
    $clock = isset($pe['clock']) ? (int)$pe['clock'] : 0;
    if ($clock > 0) {
        $eventClocks[] = $clock;
    }
}
$hourly_data = $hourly_data ?? array_fill(0, 24, 0);
$weekly_data = $weekly_data ?? array_fill(0, 7, 0);
$weekdays = $weekdays ?? [];
$hour_labels = $hour_labels ?? [];
$weekly_hourly_details = $weekly_hourly_details ?? array_fill(0, 7, array_fill(0, 24, 0));
$monthly_labels = $monthly_labels ?? [];
$monthly_values = $monthly_values ?? [];
$month_keys_js = $month_keys_js ?? [];
?>
(function() {
    var hourlyData = <?= json_encode(array_values($hourly_data)) ?>;
    var weeklyData = <?= json_encode(array_values($weekly_data)) ?>;
    var weekdayLabels = <?= json_encode($weekdays) ?>;
    var hourLabels = <?= json_encode($hour_labels) ?>;
    var eventClocks = <?= json_encode($eventClocks) ?>;
    var weeklyHourlyDetails = <?= json_encode($weekly_hourly_details) ?>;
    var monthKeys = <?= json_encode($month_keys_js) ?>;
    var monthlyLabels = <?= json_encode($monthly_labels) ?>;
    var monthlyValues = <?= json_encode($monthly_values) ?>;

    window.currentEventData = {
        eventid: <?= json_encode($event['eventid'] ?? '') ?>,
        hostname: <?= json_encode($host['name'] ?? $host['host'] ?? '') ?>,
        hostid: <?= json_encode($host['hostid'] ?? '') ?>,
        triggerid: <?= json_encode($trigger['triggerid'] ?? '') ?>,
        problem_name: <?= json_encode($event['name'] ?? '') ?>,
        severity: <?= json_encode($event['severity'] ?? 0) ?>
    };

    var isDark = document.body.getAttribute("theme") === "dark-theme" || document.documentElement.getAttribute("theme") === "dark-theme";
    var barBg = isDark ? "#3a3a3a" : "#e9ecef";
    var barFill = "#0275b8";

    function computeAggregates(clkList) {
        var hourly = []; for (var h = 0; h < 24; h++) hourly[h] = 0;
        var weekly = []; for (var w = 0; w < 7; w++) weekly[w] = 0;
        var weeklyHourly = []; for (var w = 0; w < 7; w++) { weeklyHourly[w] = []; for (var h = 0; h < 24; h++) weeklyHourly[w][h] = 0; }
        var mkLen = (monthKeys && monthKeys.length) ? monthKeys.length : 12;
        var monthly = []; for (var m = 0; m < mkLen; m++) monthly[m] = 0;
        for (var i = 0; i < (clkList || []).length; i++) {
            var dt = new Date(clkList[i] * 1000);
            var ym = dt.getFullYear() + "-" + (String(dt.getMonth() + 1).padStart(2, "0"));
            var h = dt.getHours();
            var w = dt.getDay();
            hourly[h]++;
            weekly[w]++;
            if (weeklyHourly[w]) weeklyHourly[w][h]++;
            if (monthKeys && monthKeys.length) {
                var idx = monthKeys.indexOf(ym);
                if (idx >= 0) monthly[idx]++;
            }
        }
        return { hourly: hourly, weekly: weekly, weeklyHourlyDetails: weeklyHourly, monthly: monthly };
    }

    var currentFilter = null;
    var currentAgg = null;

    function renderHeatmap(agg) {
        var el = document.getElementById("mnz-problemanalist-heatmap");
        if (!el) return;
        var wh = agg.weeklyHourlyDetails || [];
        var maxVal = 0;
        for (var w = 0; w < 7; w++) {
            for (var h = 0; h < 24; h++) {
                if (wh[w] && wh[w][h] > maxVal) maxVal = wh[w][h];
            }
        }
        if (maxVal === 0) maxVal = 1;

        var html = '<div class="mnz-pa-heatmap-grid mnz-pa-heatmap-vertical"><div class="mnz-pa-heatmap-labels-col"><div class="mnz-pa-heatmap-corner"></div>';
        for (var h = 0; h < 24; h++) {
            html += '<div class="mnz-pa-heatmap-row-label">' + (hourLabels[h] || h) + '</div>';
        }
        html += '</div><div class="mnz-pa-heatmap-body"><div class="mnz-pa-heatmap-days-row">';
        for (var w = 0; w < 7; w++) {
            html += '<div class="mnz-pa-heatmap-day-label">' + (weekdayLabels[w] || "") + '</div>';
        }
        html += '</div>';
        for (var h = 0; h < 24; h++) {
            html += '<div class="mnz-pa-heatmap-row">';
            for (var w = 0; w < 7; w++) {
                var v = (wh[w] && wh[w][h]) || 0;
                var intensity = v / maxVal;
                var col = intensity > 0 ? (intensity > 0.5 ? (intensity > 0.8 ? "#c0392b" : "#e67e22") : "#27ae60") : barBg;
                var cls = v > 0 ? " mnz-pa-heatmap-cell-active" : "";
                html += '<div class="mnz-pa-heatmap-cell' + cls + '" data-w="' + w + '" data-h="' + h + '" style="background:' + col + '" title="' + (weekdayLabels[w] || "") + ' ' + (hourLabels[h] || h) + ': ' + v + '">' + v + '</div>';
            }
            html += '</div>';
        }
        html += '</div></div>';
        el.innerHTML = html;

        jQuery("#mnz-problemanalist-heatmap").off("click", ".mnz-pa-heatmap-cell-active").on("click", ".mnz-pa-heatmap-cell-active", function() {
            var w = parseInt(jQuery(this).data("w"), 10);
            var h = parseInt(jQuery(this).data("h"), 10);
            if (currentFilter && currentFilter.weekday === w && currentFilter.hour === h) {
                currentFilter = null;
                currentAgg = computeAggregates(eventClocks);
                applyAndRender();
                updateFilterBar();
                return;
            }
            var filtered = eventClocks.filter(function(t) {
                var dt = new Date(t * 1000);
                return dt.getDay() === w && dt.getHours() === h;
            });
            currentFilter = { weekday: w, hour: h };
            currentAgg = computeAggregates(filtered);
            applyAndRender();
            updateFilterBar();
        });
    }

    function updateFilterBar() {
        var fb = document.getElementById("mnz-problemanalist-filter-bar");
        if (!fb) return;
        if (!currentFilter) {
            fb.innerHTML = "";
            fb.classList.remove("mnz-filter-active");
            return;
        }
        var lbl;
        if (currentFilter.monthIndex !== undefined) {
            var lab = (monthlyLabels && monthlyLabels[currentFilter.monthIndex]) || (monthKeys && monthKeys[currentFilter.monthIndex]) || (currentFilter.monthIndex + 1);
            lbl = lab;
        } else {
            lbl = (weekdayLabels[currentFilter.weekday] || "") + " " + (hourLabels[currentFilter.hour] || currentFilter.hour + "h");
        }
        fb.classList.add("mnz-filter-active");
        fb.innerHTML = '<span class="mnz-filter-label">' + <?= json_encode(_('Filtered by')) ?> + ': ' + lbl + '</span> <button type="button" class="btn btn-alt mnz-filter-clear">' + <?= json_encode(_('Clear filter')) ?> + '</button>';
        jQuery("#mnz-problemanalist-filter-bar .mnz-filter-clear").off("click").on("click", function() {
            currentFilter = null;
            currentAgg = computeAggregates(eventClocks);
            applyAndRender();
            updateFilterBar();
        });
    }

    function applyAndRender() {
        currentAgg = currentAgg || computeAggregates(eventClocks);
        renderHeatmap(currentAgg);
        renderMonthlyBars(currentAgg.monthly || monthlyValues);
        updateFilterBar();
    }

    function renderMonthlyBars(data) {
        var el = document.getElementById("mnz-monthly-bars-chart");
        if (!el) return;
        var labels = monthlyLabels;
        if (!labels || !labels.length) labels = monthKeys || [];
        data = data || monthlyValues;
        var maxVal = Math.max.apply(Math, data);
        if (maxVal === 0) maxVal = 1;
        var emptyBarColor = barBg;
        var textColor = isDark ? "#e0e0e0" : "#333333";
        var labelColor = isDark ? "#999999" : "#666666";
        var html = '<div class="mnz-monthly-bars mnz-monthly-bars-horizontal">';
        for (var i = 0; i < Math.min(12, data.length); i++) {
            var pct = (data[i] / maxVal) * 100;
            var col = data[i] > 0 ? barFill : emptyBarColor;
            var activeCls = data[i] > 0 ? " mnz-monthly-bar-item-active" : "";
            html += '<div class="mnz-monthly-bar-row' + activeCls + '" data-month-index="' + i + '" title="' + (labels[i] || "") + ': ' + data[i] + '">';
            html += '<span class="mnz-monthly-bar-lbl" style="color:' + labelColor + '">' + (labels[i] || "") + '</span>';
            html += '<div class="mnz-monthly-bar-track"><div class="mnz-monthly-bar" style="width:' + pct + '%;background:' + col + '"></div></div>';
            html += '<span class="mnz-monthly-bar-val" style="color:' + textColor + '">' + (data[i] > 0 ? data[i] : "0") + '</span>';
            html += '</div>';
        }
        html += '</div>';
        el.innerHTML = html;

        jQuery("#mnz-monthly-bars-chart").off("click", ".mnz-monthly-bar-row.mnz-monthly-bar-item-active").on("click", ".mnz-monthly-bar-row.mnz-monthly-bar-item-active", function() {
            var idx = parseInt(jQuery(this).data("month-index"), 10);
            if (currentFilter && currentFilter.monthIndex === idx) {
                currentFilter = null;
                currentAgg = computeAggregates(eventClocks);
                applyAndRender();
                updateFilterBar();
                return;
            }
            var ym = (monthKeys && monthKeys[idx]) ? monthKeys[idx] : null;
            if (!ym) return;
            var filtered = eventClocks.filter(function(t) {
                var dt = new Date(t * 1000);
                var dym = dt.getFullYear() + "-" + (String(dt.getMonth() + 1).padStart(2, "0"));
                return dym === ym;
            });
            currentFilter = { monthIndex: idx, month: ym };
            currentAgg = computeAggregates(filtered);
            applyAndRender();
            updateFilterBar();
        });
    }

    function initTabs() {
        var $tabs = jQuery("#event-details-tabs");
        if (!$tabs.length) return;
        if (typeof jQuery.fn.tabs === "function") {
            try {
                $tabs.tabs({
                    activate: function(event, ui) {
                        if (ui.newPanel.find("#mnz-problemanalist-heatmap, #mnz-monthly-bars-chart").length) {
                            setTimeout(applyAndRender, 100);
                        }
                        if (ui.newPanel.find("#services-tree-container").length && window.loadImpactedServices) {
                            setTimeout(function() { window.loadImpactedServices(); }, 100);
                        }
                    }
                });
            } catch (e) {
                setupManualTabs();
            }
        } else {
            setupManualTabs();
        }
    }

    function setupManualTabs() {
        var $tabs = jQuery("#event-details-tabs");
        if (!$tabs.length || !$tabs.find(".ui-tabs-nav").length) return;
        var $nav = $tabs.find(".ui-tabs-nav li");
        var $panels = $tabs.find(".ui-tabs-panel");
        $nav.each(function(idx) {
            jQuery(this).off("click.manual").on("click.manual", function() {
                $nav.removeClass("ui-tabs-active").attr("aria-selected", "false");
                $panels.removeClass("ui-tabs-panel-active").attr("aria-hidden", "true");
                jQuery(this).addClass("ui-tabs-active").attr("aria-selected", "true");
                var $panel = $panels.eq(idx);
                $panel.addClass("ui-tabs-panel-active").attr("aria-hidden", "false");
                if ($panel.find("#mnz-problemanalist-heatmap, #mnz-monthly-bars-chart").length) {
                    setTimeout(applyAndRender, 100);
                }
                if ($panel.find("#services-tree-container").length && window.loadImpactedServices) {
                    setTimeout(function() { window.loadImpactedServices(); }, 100);
                }
            });
        });
    }

    async function loadImpactedServices() {
        var loadingEl = document.querySelector(".mnz-services-loading");
        var treeContainer = document.getElementById("services-tree-container");
        if (loadingEl) loadingEl.style.display = "block";
        if (treeContainer) treeContainer.innerHTML = "";
        if (!window.currentEventData) {
            displayServicesError(<?= json_encode(_('Event data not available')) ?>);
            if (loadingEl) loadingEl.style.display = "none";
            return;
        }
        try {
            var services = await fetchRelatedServices(window.currentEventData);
            if (services && services.length > 0) {
                displayServicesTree(services);
            } else {
                displayNoServicesMessage();
            }
        } catch (err) {
            displayServicesError(err.message || String(err));
        } finally {
            if (loadingEl) loadingEl.style.display = "none";
        }
    }

    async function fetchRelatedServices(eventData) {
        try {
            var formData = new FormData();
            formData.append("output", "extend");
            formData.append("selectParents", "extend");
            formData.append("selectChildren", "extend");
            formData.append("selectTags", "extend");
            formData.append("selectProblemTags", "extend");
            if (eventData.hostname) formData.append("hostname", eventData.hostname);
            if (eventData.eventid) formData.append("eventid", eventData.eventid);
            if (eventData.hostid) formData.append("hostid", eventData.hostid);
            if (eventData.triggerid) formData.append("triggerid", eventData.triggerid);
            var response = await fetch("zabbix.php?action=problemanalist.service.get", { method: "POST", body: formData });
            if (!response.ok) throw new Error("HTTP " + response.status);
            var result = await response.json();
            if (result && result.success && result.data) return result.data;
            if (result && !result.success) throw new Error(result.error && result.error.message ? result.error.message : "API error");
            return [];
        } catch (e) {
            return [];
        }
    }

    function escapeHtml(text) {
        var div = document.createElement("div");
        div.textContent = text === undefined ? "" : text;
        return div.innerHTML;
    }

    function createSLIDonutChart(service) {
        if (!service.has_sla || service.sli == null) return "";
        var val = Math.min(100, Math.max(0, parseFloat(service.sli)));
        var sloNum = (service.slo != null && !isNaN(parseFloat(service.slo))) ? Math.min(100, Math.max(1, parseFloat(service.slo))) : 99.9;
        var sloLow = Math.max(0, sloNum - 5);
        var fillColor = "#6c757d";
        if (val < sloLow) fillColor = "#c0392b"; else if (val < sloNum) fillColor = "#f1c40f"; else fillColor = "#27ae60";
        var pct = val / 100;
        var R = 45, r = 27;
        function polar(cx, cy, rad, deg) { var a = (deg - 90) * Math.PI / 180; return cx + rad * Math.cos(a) + "," + (cy + rad * Math.sin(a)); }
        function arcPath(r1, r2, a1, a2) { var span = a2 - a1; if (span <= 0) span += 360; var p1 = polar(50, 50, r1, a1), p2 = polar(50, 50, r1, a2), p3 = polar(50, 50, r2, a2), p4 = polar(50, 50, r2, a1); var big = span >= 180 ? 1 : 0; return "M " + p1 + " A " + r1 + "," + r1 + " 0 " + big + ",1 " + p2 + " L " + p3 + " A " + r2 + "," + r2 + " 0 " + big + ",0 " + p4 + " Z"; }
        var aEnd = pct >= 0.999 ? 359.999 : 360 * pct; var aStart = pct <= 0.001 ? 0.001 : 360 * pct;
        var aR = 360 * (sloLow / 100), aY = 360 * (sloNum / 100); if (aR < 0.5) aR = 0.5; if (aY - aR < 0.5) aY = aR + 0.5; if (360 - aY < 0.5) aY = 359.5;
        
        var html = '<div class="mnz-sla-gauge mnz-sla-gauge-donut">';
        html += '<svg viewBox="0 0 100 100" class="mnz-sli-donut-svg" width="160" height="160">';
        html += '<path d="' + arcPath(R, r, 0, aR) + '" fill="#c0392b" class="mnz-sli-donut-bg mnz-sli-donut-bg-red"></path>';
        html += '<path d="' + arcPath(R, r, aR, aY) + '" fill="#f1c40f" class="mnz-sli-donut-bg mnz-sli-donut-bg-yellow"></path>';
        html += '<path d="' + arcPath(R, r, aY, 359.999) + '" fill="#27ae60" class="mnz-sli-donut-bg mnz-sli-donut-bg-green"></path>';
        if (pct < 0.999) html += '<path d="' + arcPath(R, r, aStart, 359.999) + '" fill="#5a6268" class="mnz-sli-donut-empty"></path>';
        html += '<text x="50" y="54" text-anchor="middle" class="mnz-sli-donut-value-text" fill="' + fillColor + '" font-size="13" font-weight="bold">' + val.toFixed(1) + ' %</text>';
        html += '</svg>';
        html += '<div class="mnz-sli-donut-desc">' + <?= json_encode(_('Current SLI')) ?> + ' (SLO ' + sloNum.toFixed(1) + '%)</div></div>';
        return html;
    }

    function buildServiceTree(services) {
        var serviceMap = {};
        services.forEach(function(s) { serviceMap[s.serviceid] = s; });
        var tree = {};
        var addPath = function(path) {
            for (var i = 0; i < path.length; i++) {
                var n = path[i];
                var id = n.serviceid;
                if (!tree[id]) {
                    tree[id] = { node: n, children: {}, fullData: serviceMap[id] || null };
                }
                if (i > 0) {
                    var pid = path[i-1].serviceid;
                    if (!tree[pid].children[id]) tree[pid].children[id] = tree[id];
                }
            }
        };
        services.forEach(function(s) {
            if (s.hierarchy_path && s.hierarchy_path.length > 0) addPath(s.hierarchy_path);
            else addPath([{ serviceid: s.serviceid, name: s.name, status: s.status, problem_tags: s.problem_tags || [] }]);
        });
        var roots = [];
        var added = {};
        for (var id in tree) {
            var hasParent = false;
            for (var pid in tree) {
                if (tree[pid].children[id]) { hasParent = true; break; }
            }
            if (!hasParent) roots.push(tree[id]);
        }
        return { roots: roots, tree: tree };
    }

    function renderTreeNode(node, depth) {
        var n = node.node;
        var full = node.fullData || {};
        var statusNames = ["OK","Info","Warning","Average","High","Disaster"];
        var statusClass = ["status-ok","status-info","status-warning","status-average","status-high","status-disaster"][n.status] || "";
        var isImpacted = (n.problem_tags && n.problem_tags.length > 0) || (full.problem_tags && full.problem_tags.length > 0);
        var childIds = Object.keys(node.children);
        var html = '<div class="mnz-service-tree-node' + (isImpacted ? ' mnz-service-tree-node-impacted' : '') + '" data-serviceid="' + escapeHtml(n.serviceid) + '" style="padding-left:' + (depth * 16) + 'px">';
        html += '<span class="mnz-service-tree-name">' + escapeHtml(n.name) + '</span>';
        html += ' <span class="mnz-service-status ' + statusClass + '">' + (statusNames[n.status] || '-') + '</span>';
        if (full.has_sla && full.sli != null) html += ' <span class="' + (parseFloat(full.sli) >= (full.slo || 99) ? 'mnz-sla-value-success' : 'mnz-sla-value-error') + '">' + parseFloat(full.sli).toFixed(2) + '%</span>';
        if (isImpacted) html += ' <span class="mnz-impacted-badge">' + <?= json_encode(_('Impacted')) ?> + '</span>';
        html += '</div>';
        childIds.sort(function(a,b){ return (node.children[a].node.name || '').localeCompare(node.children[b].node.name || ''); });
        childIds.forEach(function(cid) { html += renderTreeNode(node.children[cid], depth + 1); });
        return html;
    }

    function createServicesTreeHtml(services) {
        var built = buildServiceTree(services);
        if (built.roots.length === 0) return createServicesTableFallback(services);
        var html = '<div class="mnz-services-tree">';
        built.roots.sort(function(a,b){ return (a.node.name || '').localeCompare(b.node.name || ''); });
        built.roots.forEach(function(r) { html += renderTreeNode(r, 0); });
        html += '</div>';
        return html;
    }

    function createServicesTableFallback(services) {
        var headers = [<?= json_encode(_('Name')) ?>, <?= json_encode(_('Status')) ?>, 'SLI', 'SLO', <?= json_encode(_('Impacted')) ?>];
        var html = '<table class="list-table mnz-services-table"><thead><tr>';
        headers.forEach(function(h) { html += '<th>' + escapeHtml(h) + '</th>'; });
        html += '</tr></thead><tbody>';
        services.forEach(function(s) {
            var statusNames = ["OK","Info","Warning","Average","High","Disaster"];
            var statusClass = ["status-ok","status-info","status-warning","status-average","status-high","status-disaster"][s.status] || "";
            var isImpacted = (s.problem_tags && s.problem_tags.length > 0);
            html += '<tr class="mnz-service-row' + (isImpacted ? ' mnz-service-row-impacted' : '') + '" data-serviceid="' + escapeHtml(s.serviceid) + '">';
            html += '<td class="mnz-service-col-name">' + escapeHtml(s.name) + '</td>';
            html += '<td><span class="mnz-service-status ' + statusClass + '">' + (statusNames[s.status] || '-') + '</span></td>';
            html += '<td>' + (s.has_sla && s.sli != null ? '<span class="' + (parseFloat(s.sli) >= (s.slo || 99) ? 'mnz-sla-value-success' : 'mnz-sla-value-error') + '">' + parseFloat(s.sli).toFixed(2) + '%</span>' : '-') + '</td>';
            html += '<td>' + (s.has_sla && s.slo != null ? parseFloat(s.slo).toFixed(1) + '%' : '-') + '</td>';
            html += '<td>' + (isImpacted ? '<span class="mnz-impacted-badge">' + <?= json_encode(_('Yes')) ?> + '</span>' : '-') + '</td>';
            html += '</tr>';
        });
        html += '</tbody></table>';
        return html;
    }

    function displayServicesTree(services) {
        var treeContainer = document.getElementById("services-tree-container");
        if (!treeContainer) return;
        var firstWithSLA = services.find(function(s) { return s.has_sla && s.sli != null; });
        var chartHtml = firstWithSLA ? createSLIDonutChart(firstWithSLA) : "";
        var treeHtml = createServicesTreeHtml(services);
        var layout = '<div class="mnz-services-layout">';
        if (chartHtml) layout += '<div class="mnz-services-chart-section">' + chartHtml + '</div>';
        layout += '<div class="mnz-services-table-section"><div class="mnz-services-summary-header"><h4>' + <?= json_encode(_('Service hierarchy')) ?> + '</h4><p>' + services.length + ' ' + <?= json_encode(_('services')) ?> + '</p></div>' + treeHtml + '</div>';
        layout += '</div>';
        treeContainer.innerHTML = layout;
    }

    function displayNoServicesMessage() {
        var treeContainer = document.getElementById("services-tree-container");
        if (!treeContainer) return;
        treeContainer.innerHTML = '<div class="mnz-services-no-results">' + <?= json_encode(_('No services affected by this incident')) ?> + '</div>';
    }

    function displayServicesError(msg) {
        var treeContainer = document.getElementById("services-tree-container");
        if (!treeContainer) return;
        treeContainer.innerHTML = '<div class="mnz-services-no-results mnz-services-error">' + escapeHtml(msg) + '</div>';
    }

    window.loadImpactedServices = loadImpactedServices;

    currentAgg = computeAggregates(eventClocks);
    applyAndRender();

    jQuery(document).ready(function() {
        initTabs();
        if (jQuery("#event-details-tabs .ui-tabs-panel-active").find("#services-tree-container").length || jQuery("#services-tree-container").length) {
            if (window.currentEventData) setTimeout(loadImpactedServices, 200);
        }
    });
})();
