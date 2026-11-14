jQuery(function () {
    'use strict';

    // External controller for the native WP dashboard "Bookly - Appointments" widget.
    // Mounts the trend chart from the self-contained dashboard-chart bundle — the same
    // library-driven-by-vanilla-JS pattern used across the admin (BooklyDatatables.showForm,
    // showExternalFilters). No inline scripts. On the Bookly dashboard page this job is
    // done by dashboard.js instead.
    if (typeof BooklyDashboardChart === 'undefined' || typeof BooklyDashboardChartL10n === 'undefined') {
        return;
    }

    BooklyDashboardChart.showChart('bookly-dashboard-trend', BooklyDashboardChartL10n);
});
