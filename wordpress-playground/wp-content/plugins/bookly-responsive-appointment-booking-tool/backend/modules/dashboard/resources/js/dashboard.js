jQuery(function ($) {
    'use strict';

    // ─────────────────────────────────────────────────────────────────────────
    // Single orchestrator for the Bookly dashboard page.
    //
    // Every load fires TWO parallel requests: bookly_get_dashboard_data ({ kpi, chart },
    // also persists the filter state) and bookly_get_dashboard_analytics (the Detailed
    // report — the heaviest section, so it neither delays the first paint nor takes the
    // KPI / chart down with it). This controller owns the filter bar + the requests, and
    // broadcasts the results to the section components over a native CustomEvent bus:
    //
    //   bookly.dashboard.loading    — a load started (sections dim their content)
    //   bookly.dashboard.data       — { kpi, chart, basedOn } (KPI row + chart render)
    //   bookly.dashboard.analytics  — { analytics } (datatable renders; null = failed)
    //
    // First paint shows a server-rendered full-page skeleton; once the KPI + chart have
    // rendered, the skeleton is removed and the content revealed — the report fills in
    // when its response lands. The native WP dashboard widget is unaffected — it loads
    // only its chart.
    // ─────────────────────────────────────────────────────────────────────────

    // Mount the KPI row + trend chart from the self-contained dashboard-chart bundle.
    // They render reactively from the data event below — neither fetches on its own.
    if (typeof BooklyDashboardChart !== 'undefined') {
        if (typeof BooklyDashboardKpiL10n !== 'undefined') {
            BooklyDashboardChart.showKpi('bookly-dashboard-kpi', BooklyDashboardKpiL10n);
        }
        if (typeof BooklyDashboardChartL10n !== 'undefined') {
            BooklyDashboardChart.showChart('bookly-dashboard-trend', BooklyDashboardChartL10n);
        }
    }

    if (typeof BooklyDatatables === 'undefined' || !BooklyDatatables.showExternalFilters) {
        return;
    }

    const L = BooklyDashboardFiltersL10n;
    const cd = BooklyDatatables.calendarDate;
    const tz = cd.getLocalTimeZone();
    const t = cd.today(tz);
    const startOfMonth = (d) => d.set({ day: 1 });
    const endOfMonth   = (d) => d.set({ day: 1 }).add({ months: 1 }).subtract({ days: 1 });
    // ISO week (Mon..Sun). 0=Sun → 6, 1=Mon → 0, ...
    function startOfWeek(d) {
        const dow = d.toDate(tz).getDay();
        return d.subtract({ days: (dow + 6) % 7 });
    }
    const endOfWeek   = (d) => startOfWeek(d).add({ days: 6 });
    const startOfYear = (d) => d.set({ month: 1, day: 1 });

    // Past-leaning reporting set — mirrors the Appointments page "creation date" presets
    // (no tasks: the dashboard is reporting-only).
    const presets = [
        { label: L.dateRange.today,      range: { start: t,                                       end: t } },
        { label: L.dateRange.yesterday,  range: { start: t.subtract({ days: 1 }),                 end: t.subtract({ days: 1 }) } },
        { label: L.dateRange.last_7,     range: { start: t.subtract({ days: 7 }),                 end: t } },
        { label: L.dateRange.last_30,    range: { start: t.subtract({ days: 30 }),                end: t } },
        { label: L.dateRange.last_90,    range: { start: t.subtract({ days: 90 }),                end: t } },
        { label: L.dateRange.thisWeek,   range: { start: startOfWeek(t),                          end: endOfWeek(t) } },
        { label: L.dateRange.thisMonth,  range: { start: startOfMonth(t),                         end: endOfMonth(t) } },
        { label: L.dateRange.lastMonth,  range: { start: startOfMonth(t.subtract({ months: 1 })), end: endOfMonth(t.subtract({ months: 1 })) } },
        { label: L.dateRange.yearToDate, range: { start: startOfYear(t),                          end: t } }
    ];

    function parseRange(str) {
        if (!str || str.indexOf(' - ') === -1) return undefined;
        const p = str.split(' - ');
        try { return { start: cd.parseDate(p[0].trim()), end: cd.parseDate(p[1].trim()) }; } catch (e) { return undefined; }
    }

    // Single source of truth for the whole dashboard's filter state. Seeded from the saved
    // state (restored server-side in BooklyDashboardFiltersL10n.initial) so F5 keeps filters.
    const state = {
        range: L.initial.range,
        basedOn: L.initial.basedOn,
        comparedTo: L.initial.comparedTo,
        staff: L.initial.staff || [],
        services: L.initial.services || []
    };

    // ── Combined data load ───────────────────────────────────────────────────
    // One request per load/filter-change. The server also persists the filter state
    // (so F5 restores it), so this controller doesn't save settings separately.
    let firstLoad = true;
    let loadSeq = 0;

    function reveal() {
        const skeleton = document.getElementById('bookly-dashboard-skeleton');
        const content  = document.getElementById('bookly-dashboard-content');
        if (skeleton) skeleton.remove();
        if (content) content.classList.remove('bookly:hidden');
        // The chart + datatable mounted inside the hidden content measured against a
        // zero-width container. A resize tick after reveal lets ECharts and the
        // datatable's ResizeObserver re-lay-out against the now-visible width.
        requestAnimationFrame(() => window.dispatchEvent(new Event('resize')));
    }

    function requestData(action) {
        const fd = new FormData();
        fd.append('action',     action);
        fd.append('csrf_token', BooklyL10nGlobal.csrf_token);
        fd.append('range',      state.range);
        fd.append('based_on',   state.basedOn);
        (state.staff || []).forEach(v => fd.append('filter[staff][]', v));
        (state.services || []).forEach(v => fd.append('filter[services][]', v));
        return fd;
    }

    function loadData() {
        const seq = ++loadSeq;
        document.body.dispatchEvent(new CustomEvent('bookly.dashboard.loading'));

        // KPI + chart (also persists the filter state server-side).
        const main = requestData('bookly_get_dashboard_data');
        main.append('compared_to', state.comparedTo);
        fetch(ajaxurl, { method: 'POST', body: main })
            .then(res => res.json())
            .then(json => {
                if (seq !== loadSeq) return; // superseded by a newer request
                if (!json.success || !json.data) return;
                document.body.dispatchEvent(new CustomEvent('bookly.dashboard.data', {
                    detail: {
                        kpi: json.data.kpi,
                        chart: json.data.chart,
                        basedOn: state.basedOn
                    }
                }));
                if (firstLoad) {
                    firstLoad = false;
                    reveal();
                }
            })
            .catch(() => {
                if (seq !== loadSeq) return;
                // On a failed first load still reveal, so the sections can show their
                // own empty/error state instead of leaving the page stuck on skeleton.
                if (firstLoad) {
                    firstLoad = false;
                    reveal();
                }
            });

        // Detailed report — in parallel, only when the section exists (Pro).
        if (document.getElementById('bookly-analytics-datatables')) {
            fetch(ajaxurl, { method: 'POST', body: requestData('bookly_get_dashboard_analytics') })
                .then(res => res.json())
                .then(json => {
                    if (seq !== loadSeq) return;
                    document.body.dispatchEvent(new CustomEvent('bookly.dashboard.analytics', {
                        detail: { analytics: json.success ? (json.data || {}) : null }
                    }));
                })
                .catch(() => {
                    if (seq !== loadSeq) return;
                    document.body.dispatchEvent(new CustomEvent('bookly.dashboard.analytics', {
                        detail: { analytics: null }
                    }));
                });
        }
    }

    BooklyDatatables.showExternalFilters('bookly-dashboard-filters', {
        datePicker: L.datePicker,
        l10n: L.filterL10n,
        size: 'base',
        filters: [
            {
                type: 'dateRange', name: 'date', label: L.labels.period, presets: presets,
                initialValue: parseRange(state.range),
                permanent: true,   // mandatory — the dashboard always needs a period
                onChange: function (v) {
                    state.range = (v && v.start && v.end) ? (v.start.toString() + ' - ' + v.end.toString()) : '';
                    loadData();
                }
            },
            {
                type: 'select', name: 'basis', label: L.labels.basis,
                initialValue: state.basedOn,
                permanent: true,   // mandatory — always carries a value, no clear (×)
                options: [
                    { value: 'start_date', label: L.labels.appointmentDate },
                    { value: 'created_at', label: L.labels.bookingDate }
                ],
                onChange: function (v) { state.basedOn = v || 'start_date'; loadData(); }
            },
            {
                type: 'select', name: 'comparedTo', label: L.labels.comparedTo,
                initialValue: state.comparedTo,
                permanent: true,
                options: [
                    { value: 'previous_period', label: L.labels.previousPeriod },
                    { value: 'previous_year', label: L.labels.previousYear }
                ],
                onChange: function (v) { state.comparedTo = v || 'previous_period'; loadData(); }
            },
            {
                type: 'checkboxGroup', name: 'staff', label: L.labels.staff,
                initialValue: state.staff, options: L.staffOptions,
                onChange: function (v) { state.staff = v; loadData(); }
            },
            {
                type: 'checkboxGroup', name: 'services', label: L.labels.service,
                initialValue: state.services, options: L.serviceOptions,
                onChange: function (v) { state.services = v; loadData(); }
            }
        ]
    });

    // Kick off the first combined load — the sections paint from its data event.
    loadData();
});
