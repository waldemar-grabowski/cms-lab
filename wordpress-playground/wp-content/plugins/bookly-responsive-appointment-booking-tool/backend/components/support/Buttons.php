<?php
namespace Bookly\Backend\Components\Support;

use Bookly\Lib;
use Bookly\Backend\Modules;
use Bookly\Backend\Components\Notices;
use Bookly\Backend\Components\Support\Lib\Urls;

class Buttons extends Lib\Base\Component
{
    /**
     * Whether the support engagement notices (Contact Us, Feedback) are pending for the current user.
     * Drives the legacy popovers and the PageHeader Help/menu notification dots.
     *
     * @return array [ 'contact_us' => bool, 'feedback' => bool ]
     */
    public static function getNotices()
    {
        $days_in_use = (int) ( ( time() - Lib\Plugin::getInstallationTime() ) / DAY_IN_SECONDS );

        return array(
            'contact_us' => $days_in_use < 7 &&
                ! get_user_meta( get_current_user_id(), Lib\Plugin::getPrefix() . 'dismiss_contact_us_notice', true ) &&
                ! Notices\Statistic\Notice::needShowCollectStatNotice(),
            'feedback' => $days_in_use >= 7 &&
                ! get_user_meta( get_current_user_id(), Lib\Plugin::getPrefix() . 'dismiss_feedback_notice', true ) &&
                ! get_user_meta( get_current_user_id(), Lib\Plugin::getPrefix() . 'contact_us_btn_clicked', true ),
        );
    }

    /**
     * Render support buttons.
     *
     * @param string $page_slug
     */
    public static function render( $page_slug )
    {
        self::enqueueStyles( array(
            'backend' => array( 'css/fontawesome-all.min.css' => array( 'bookly-backend-globals' ) ),
        ) );

        self::enqueueScripts( array(
            'module' => array( 'js/support.js' => array( 'bookly-backend-globals', ), ),
        ) );

        wp_localize_script( 'bookly-support.js', 'BooklySupportL10n', array(
            'featuresRequestUrl' => Lib\Utils\Common::prepareUrlReferrers( Urls::FEATURES_REQUEST_PAGE, 'notification_bar' ),
            'capabilities' => sprintf( __( 'Please note that your user doesn\'t have the %s capability. All potentially unsecure HTML in Appearance, Email Notifications, and other Bookly sections will be sanitized during the saving of any changes', 'bookly-responsive-appointment-booking-tool' ), '`unfiltered_html`' ),
            'contact_with_admin' => __( 'Please contact your website administrator for additional information', 'bookly-responsive-appointment-booking-tool' ),
        ) );

        $notices = self::getNotices();
        $show_contact_us_notice = $notices['contact_us'];
        $show_feedback_notice = $notices['feedback'];

        $current_user = wp_get_current_user();

        $demo_links = array();

        if ( ! Lib\Config::proActive() ) {
            // Empty key for page bookly-settings
            $demo_links = array( '' => 'https://www.booking-wp-plugin.com/demo/full/wp-admin/admin.php?page=bookly-settings' );
            foreach ( array( 'calendar', 'appointments', 'staff', 'services', 'customers', 'notifications', 'payments', 'appearance' ) as $slug ) {
                $demo_links[ 'bookly-' . $slug ] = 'https://www.booking-wp-plugin.com/demo/full/wp-admin/admin.php?page=bookly-' . $slug;
            }
        }

        static::renderTemplate( 'buttons', compact(
            'current_user',
            'demo_links',
            'page_slug',
            'show_contact_us_notice',
            'show_feedback_notice'
        ) );
    }

    /**
     * Demo URL for the given admin page, or null when the demo button should
     * not be shown (Pro is active, or the page has no demo counterpart).
     * Shared by the Svelte PageHeader (button) and modals.php (info modal).
     *
     * @param string $page_slug
     * @return string|null
     */
    public static function getDemoUrl( $page_slug )
    {
        if ( Lib\Config::proActive() ) {
            return null;
        }

        $demo_links = array(
            'bookly-settings' => 'https://www.booking-wp-plugin.com/demo/full/wp-admin/admin.php?page=bookly-settings',
        );
        foreach ( array( 'calendar', 'appointments', 'staff', 'services', 'customers', 'notifications', 'payments', 'appearance' ) as $slug ) {
            $demo_links[ 'bookly-' . $slug ] = 'https://www.booking-wp-plugin.com/demo/full/wp-admin/admin.php?page=bookly-' . $slug;
        }

        return isset( $demo_links[ $page_slug ] ) ? $demo_links[ $page_slug ] : null;
    }

    /**
     * Render only the support modals (Contact us, Feature requests) without
     * the trigger buttons. Used by the new Svelte PageHeader, which renders
     * its own button bar and only needs the modal HTML in the DOM for the
     * existing support.js handlers to bind to.
     *
     * @param string $page_slug
     */
    public static function renderModals( $page_slug )
    {
        self::enqueueStyles( array(
            'backend' => array( 'css/fontawesome-all.min.css' => array( 'bookly-backend-globals' ) ),
        ) );

        self::enqueueScripts( array(
            'module' => array( 'js/support.js' => array( 'bookly-backend-globals' ) ),
        ) );

        wp_localize_script( 'bookly-support.js', 'BooklySupportL10n', array(
            'featuresRequestUrl' => Lib\Utils\Common::prepareUrlReferrers( Urls::FEATURES_REQUEST_PAGE, 'notification_bar' ),
            'capabilities' => sprintf( __( 'Please note that your user doesn\'t have the %s capability. All potentially unsecure HTML in Appearance, Email Notifications, and other Bookly sections will be sanitized during the saving of any changes', 'bookly-responsive-appointment-booking-tool' ), '`unfiltered_html`' ),
            'contact_with_admin' => __( 'Please contact your website administrator for additional information', 'bookly-responsive-appointment-booking-tool' ),
        ) );

        $current_user = wp_get_current_user();

        static::renderTemplate( 'modals', compact( 'current_user', 'page_slug' ) );
    }
}