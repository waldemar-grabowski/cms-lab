<?php
namespace Bookly\Backend\Components\Dialogs\Staff\Edit;

use Bookly\Lib;

class Dialog extends Lib\Base\Component
{
    /**
     * Render create service dialog.
     */
    public static function render()
    {
        /** @var \WP_Locale $wp_locale */
        global $wp_locale, $wpdb;

        wp_enqueue_media();

        self::enqueueStyles( array(
            'wp' => array( 'wp-color-picker' ),
            'frontend' => get_option( 'bookly_cst_phone_default_country' ) == 'disabled'
                ? array()
                : array( 'css/intlTelInput.css' )
        ,
            'backend' => array( 'css/fontawesome-all.min.css' => array( 'bookly-backend-globals' ), ),
        ) );

        self::enqueueScripts( array(
            'frontend' => get_option( 'bookly_cst_phone_default_country' ) === 'disabled'
                ? array()
                : array(
                    'js/intlTelInput.min.js' => array( 'bookly-backend-globals' )
                ),
            'backend' => array(
                'js/jCal.js' => array( 'jquery' ),
                'js/nav-scrollable.js' => array( 'bookly-backend-globals' ),
                'js/range-tools.js' => array( 'bookly-backend-globals' ),
            ),
            'module' => array(
                'js/staff-details.js' => array( 'bookly-range-tools.js', 'wp-color-picker' ),
                'js/staff-services.js' => array( 'bookly-staff-details.js' ),
                'js/staff-schedule.js' => array( 'bookly-staff-services.js' ),
                'js/staff-days-off.js' => array( 'bookly-staff-schedule.js' ),
                'js/staff-edit-dialog.js' => array( 'bookly-staff-days-off.js' ),
            ),
        ) );

        Proxy\Pro::enqueueAssets();

        $query = Lib\Entities\Staff::query( 's' )
            ->select( 's.id, s.full_name' )
            ->tableJoin( $wpdb->users, 'wpu', 'wpu.ID = s.wp_user_id' );

        if ( ! Lib\Utils\Common::isCurrentUserAdmin() ) {
            $query->where( 's.wp_user_id', get_current_user_id() );
        }

        wp_localize_script( 'bookly-staff-edit-dialog.js', 'BooklyStaffEditDialogL10n', array(
            'intlTelInput' => array(
                'country' => get_option( 'bookly_cst_phone_default_country' ),
                'enabled' => get_option( 'bookly_cst_phone_default_country' ) != 'disabled',
            ),
            'holidays' => array(
                'close' => esc_attr__( 'Close', 'bookly-responsive-appointment-booking-tool' ),
                'days' => array_values( $wp_locale->weekday_abbrev ),
                'excluded_from_repeat' => esc_attr__( 'This day is excluded from the repeating day off', 'bookly-responsive-appointment-booking-tool' ),
                'firstDay' => (int) get_option( 'start_of_week' ),
                'loading_img' => plugins_url( 'bookly-responsive-appointment-booking-tool/backend/resources/images/loading.gif' ),
                'months' => array_values( $wp_locale->month ),
                'repeat' => esc_attr__( 'Repeat every year', 'bookly-responsive-appointment-booking-tool' ),
                'special_days_error' => esc_attr__( 'The date has already passed.', 'bookly-responsive-appointment-booking-tool' ),
                'we_are_not_working' => esc_attr__( 'We are not working on this day', 'bookly-responsive-appointment-booking-tool' ),
            ),
            'services' => array(
                'capacity_error' => esc_attr__( 'Min capacity should not be greater than max capacity.', 'bookly-responsive-appointment-booking-tool' ),
                'hideTip' => get_user_meta( get_current_user_id(), 'bookly_packages_hide_staff_services_tip', true ),
            ),
            'appointmentsUrl' => Lib\Utils\Common::escAdminUrl( \Bookly\Backend\Modules\Appointments\Ajax::pageSlug() ),
            'areYouSure' => esc_attr__( 'Are you sure?', 'bookly-responsive-appointment-booking-tool' ),
            'createStaff' => esc_attr__( 'New staff member', 'bookly-responsive-appointment-booking-tool' ),
            'currentTab' => self::parameter( 'tab', 'details' ),
            'editStaff' => esc_attr__( 'Edit staff member', 'bookly-responsive-appointment-booking-tool' ),
            'save' => esc_attr__( 'Save', 'bookly-responsive-appointment-booking-tool' ),
            'create' => esc_attr__( 'Create', 'bookly-responsive-appointment-booking-tool' ),
            'proRequired' => (int) ! Lib\Config::proActive(),
            'settingsSaved' => esc_attr__( 'Settings saved.', 'bookly-responsive-appointment-booking-tool' ),
            'staff' => $query->sortBy( 'position' )->fetchArray(),
        ) );

        self::renderTemplate( 'dialog' );

        Proxy\Pro::renderArchivingComponents();
    }
}