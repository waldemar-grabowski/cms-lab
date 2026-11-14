<?php
namespace Bookly\Backend\Modules\Dashboard;

use Bookly\Lib;

class Page extends Lib\Base\Component
{
    /**
     * Render page.
     */
    public static function render()
    {
        self::enqueueStyles( array(
            'alias' => array( 'bookly-backend-globals', ),
        ) );

        self::enqueueScripts( array(
            // dashboard.js is the page's external controller: it mounts the filter bar,
            // the KPI row and the trend chart. Depends on the chart bundle so the
            // BooklyDashboardChart library + localized configs exist when it runs.
            'module' => array( 'js/dashboard.js' => array( 'bookly-backend-globals', 'bookly-dashboard-chart.js' ), ),
            // KPI row + "needs attention" strip (same self-contained dashboard bundle as the chart).
            // The bundle lives in the appointments component's resources/js; enqueue it here
            // too (early) so the KPI config can be localized on it and dashboard.js can depend
            // on it. Same handle/URL as the component-side enqueue — registration is idempotent.
            'plugin' => array( 'backend/components/dashboard/appointments/resources/js/dashboard-chart.js' => array( 'jquery' ), ),
        ) );
        $based_on = get_option( 'bookly_dashboard_based_on_appointment' );

        // Staff / service options for the global filter bar (flat checkboxGroup lists).
        $staff_options = array();
        foreach ( Lib\Entities\Staff::query()->find() as $staff ) {
            $staff_options[] = array( 'value' => (int) $staff->getId(), 'label' => $staff->getFullName() );
        }
        $service_options = array( array( 'value' => 0, 'label' => __( 'Custom', 'bookly-responsive-appointment-booking-tool' ) ) );
        foreach ( Lib\Utils\Common::getServiceDataForDropDown( 's.type = "simple"' ) as $category ) {
            foreach ( $category['items'] as $service ) {
                $service_options[] = array( 'value' => (int) $service['id'], 'label' => $service['title'] );
            }
        }
        $datatables = Lib\Utils\Tables::getSettings( array() );

        // Restore the saved filter bar state (persisted by getDashboardData on every data
        // load). First visit falls back to defaults. Empty staff/services = all. The same
        // values seed the bar, the KPI row and the chart so the page paints consistently
        // after F5 — no flash, no double fetch.
        $saved = Lib\Utils\Tables::getSettings( 'dashboard' );
        $saved = isset( $saved['dashboard']['settings']['filter'] ) ? $saved['dashboard']['settings']['filter'] : array();
        $init_range       = ! empty( $saved['range'] ) ? $saved['range'] : sprintf( '%s - %s', date( 'Y-m-d', strtotime( '-7 days' ) ), date( 'Y-m-d' ) );
        $init_based_on    = isset( $saved['based_on'] ) ? $saved['based_on'] : ( $based_on ?: 'start_date' );
        $init_compared_to = isset( $saved['compared_to'] ) ? $saved['compared_to'] : 'previous_period';
        $init_staff       = isset( $saved['staff'] ) ? array_map( 'intval', (array) $saved['staff'] ) : array();
        $init_services    = isset( $saved['services'] ) ? array_map( 'intval', (array) $saved['services'] ) : array();

        wp_localize_script( 'bookly-dashboard.js', 'BooklyDashboardFiltersL10n', array(
            'datePicker' => Lib\Utils\DateTime::datePickerOptions(),
            'filterL10n' => $datatables['l10n'],
            'staffOptions' => $staff_options,
            'serviceOptions' => $service_options,
            'initial' => array(
                'range' => $init_range,
                'basedOn' => $init_based_on,
                'comparedTo' => $init_compared_to,
                'staff' => $init_staff,
                'services' => $init_services,
            ),
            // Canonical date-range labels (same source as the Appointments page) — the
            // reporting preset set is built from these in dashboard.js.
            'dateRange' => Lib\Utils\DateTime::dateRangeOptions(),
            'labels' => array(
                'period' => __( 'Period', 'bookly-responsive-appointment-booking-tool' ),
                'basis' => __( 'Based on', 'bookly-responsive-appointment-booking-tool' ),
                'appointmentDate' => __( 'Appointment date', 'bookly-responsive-appointment-booking-tool' ),
                'bookingDate' => __( 'Booking date', 'bookly-responsive-appointment-booking-tool' ),
                'comparedTo' => __( 'Compared to', 'bookly-responsive-appointment-booking-tool' ),
                'previousPeriod' => __( 'previous period', 'bookly-responsive-appointment-booking-tool' ),
                'previousYear' => __( 'previous year', 'bookly-responsive-appointment-booking-tool' ),
                'staff' => __( 'Staff', 'bookly-responsive-appointment-booking-tool' ),
                'service' => __( 'Service', 'bookly-responsive-appointment-booking-tool' ),
            ),
        ) );

        $currencies = Lib\Utils\Price::getCurrencies();
        wp_localize_script( 'bookly-dashboard-chart.js', 'BooklyDashboardKpiL10n', array(
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'csrfToken' => Lib\Utils\Common::getCsrfToken(),
            'currency'  => $currencies[ Lib\Config::getCurrency() ]['symbol'],
            'range'      => $init_range,
            'basedOn'    => $init_based_on,
            'comparedTo' => $init_compared_to,
            'staff'      => $init_staff,
            'services'   => $init_services,
            'l10n'      => array(
                'revenue'         => __( 'Revenue', 'bookly-responsive-appointment-booking-tool' ),
                'appointments'    => __( 'Appointments', 'bookly-responsive-appointment-booking-tool' ),
                // Sale kinds — trend-chart series and the chips on the Sales card. Only
                // shown when the matching add-on is active, but always localized.
                'sales'           => __( 'Sales', 'bookly-responsive-appointment-booking-tool' ),
                'tickets'         => __( 'Tickets', 'bookly-responsive-appointment-booking-tool' ),
                'packages'        => __( 'Packages', 'bookly-responsive-appointment-booking-tool' ),
                'giftCards'       => __( 'Gift cards', 'bookly-responsive-appointment-booking-tool' ),
                'newCustomers'    => __( 'New customers', 'bookly-responsive-appointment-booking-tool' ),
                'approved'        => __( 'approved', 'bookly-responsive-appointment-booking-tool' ),
                'pending'         => __( 'pending', 'bookly-responsive-appointment-booking-tool' ),
                'cancelled'       => __( 'cancelled', 'bookly-responsive-appointment-booking-tool' ),
                'rejected'        => __( 'rejected', 'bookly-responsive-appointment-booking-tool' ),
                'waitlisted'      => __( 'waitlisted', 'bookly-responsive-appointment-booking-tool' ),
                'returning'       => __( 'returning', 'bookly-responsive-appointment-booking-tool' ),
                'pendingApproval' => __( 'appointments pending approval', 'bookly-responsive-appointment-booking-tool' ),
                'awaitingAction'  => __( 'Awaiting your action', 'bookly-responsive-appointment-booking-tool' ),
                'lost'            => __( 'lost appointments', 'bookly-responsive-appointment-booking-tool' ),
                'lostSub'         => __( 'cancelled + rejected', 'bookly-responsive-appointment-booking-tool' ),
                'ofTotal'         => __( 'of total', 'bookly-responsive-appointment-booking-tool' ),
                'was'             => __( 'was', 'bookly-responsive-appointment-booking-tool' ),
                'vs'              => __( 'vs', 'bookly-responsive-appointment-booking-tool' ),
                'noData'          => __( 'No data', 'bookly-responsive-appointment-booking-tool' ),
            ),
        ) );

        self::renderTemplate( 'index' );
    }
}