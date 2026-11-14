<?php
namespace Bookly\Backend\Components\Dashboard\Appointments;

use Bookly\Lib;

class Widget extends Lib\Base\Component
{
    public static function init()
    {
        $current_user = wp_get_current_user();

        if ( $current_user && $current_user->has_cap( Lib\Utils\Common::getRequiredCapability() ) ) {
            $class = __CLASS__;
            add_action( 'wp_dashboard_setup', function () use ( $class ) {
                wp_add_dashboard_widget( strtolower( str_replace( '\\', '-', $class ) ), 'Bookly - ' . __( 'Appointments', 'bookly-responsive-appointment-booking-tool' ), array( $class, 'renderWidget' ) );
            } );
        }
    }

    /**
     * Render widget on WordPress dashboard.
     */
    public static function renderWidget()
    {
        self::enqueueAssets( 'widget' );
        // External controller mounts the chart (no inline script) — mirrors how the
        // Bookly dashboard page's dashboard.js drives mounting. Page-side mounting is
        // handled by dashboard.js, so this controller is enqueued for the widget only.
        self::enqueueScripts( array(
            'module' => array( 'js/appointments-dashboard.js' => array( 'bookly-dashboard-chart.js' ) ),
        ) );
        self::renderTemplate( 'widget' );
    }

    /**
     * Render on Bookly/Dashboard page.
     */
    public static function renderChart()
    {
        self::enqueueAssets( 'page' );
        self::renderTemplate( 'block' );
    }

    /**
     * Enqueue assets
     *
     * @param string $variant 'page' | 'widget'
     */
    private static function enqueueAssets( $variant )
    {
        self::enqueueStyles( array(
            'backend'  => array( 'css/fontawesome-all.min.css' ),
        ) );

        // Self-contained ECharts trend chart (svelte + echarts/core bundled in).
        // Works both on the Bookly dashboard page and in the native WP dashboard widget.
        // Compiled into this component's own resources/js (component-local 'module' path).
        self::enqueueScripts( array(
            'module' => array(
                'js/dashboard-chart.js' => array( 'jquery' ),
            ),
        ) );

        $currencies = Lib\Utils\Price::getCurrencies();
        $based_on = get_option( 'bookly_dashboard_based_on_appointment' );

        // On the Bookly dashboard page the chart shares the saved filter bar state (so it
        // restores after F5, consistent with the KPI row). The native WP widget has its own
        // date <select> and no filter bar — it always starts from defaults.
        $range = sprintf( '%s - %s', date( 'Y-m-d', strtotime( '-7 days' ) ), date( 'Y-m-d' ) );
        $staff = array();
        $services = array();
        if ( $variant === 'page' ) {
            $saved = Lib\Utils\Tables::getSettings( 'dashboard' );
            $saved = isset( $saved['dashboard']['settings']['filter'] ) ? $saved['dashboard']['settings']['filter'] : array();
            if ( ! empty( $saved['range'] ) ) {
                $range = $saved['range'];
            }
            if ( isset( $saved['based_on'] ) ) {
                $based_on = $saved['based_on'];
            }
            $staff = isset( $saved['staff'] ) ? array_map( 'intval', (array) $saved['staff'] ) : array();
            $services = isset( $saved['services'] ) ? array_map( 'intval', (array) $saved['services'] ) : array();
        }

        wp_localize_script( 'bookly-dashboard-chart.js', 'BooklyDashboardChartL10n', array(
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'csrfToken' => Lib\Utils\Common::getCsrfToken(),
            'currency'  => $currencies[ Lib\Config::getCurrency() ]['symbol'],
            'variant'   => $variant,
            'range'     => $range,
            'basedOn'   => $based_on ?: 'created_at',
            'staff'     => $staff,
            'services'  => $services,
            'selectId'  => 'bookly-filter-date',
            'l10n'      => array(
                'appointments' => __( 'Appointments', 'bookly-responsive-appointment-booking-tool' ),
                'revenue'      => __( 'Revenue', 'bookly-responsive-appointment-booking-tool' ),
                'trend'        => __( 'Trend', 'bookly-responsive-appointment-booking-tool' ),
                'noData'       => __( 'No data', 'bookly-responsive-appointment-booking-tool' ),
            ),
        ) );
    }
}