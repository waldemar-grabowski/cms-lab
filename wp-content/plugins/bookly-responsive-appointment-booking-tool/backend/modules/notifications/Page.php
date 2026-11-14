<?php
namespace Bookly\Backend\Modules\Notifications;

use Bookly\Lib;

class Page extends Lib\Base\Component
{
    /**
     * Render page.
     */
    public static function render()
    {
        $tab = self::parameter( 'tab', 'notifications' );

        self::enqueueStyles( array(
            'alias' => array( 'bookly-backend-globals', ),
        ) );

        self::enqueueScripts( array(
            'module' => array( 'js/email-notifications.js' => array( 'bookly-backend-globals' ) ),
            'bookly' => array( 'backend/modules/cloud_sms/resources/js/notifications-list.js' => array( 'bookly-backend-globals' ), ),
        ) );

        Proxy\Shared::enqueueAssets();
        
        $datatables = Lib\Utils\Tables::getSettings( array( Lib\Utils\Tables::EMAIL_NOTIFICATIONS, Lib\Utils\Tables::EMAIL_LOGS ) );

        wp_localize_script( 'bookly-email-notifications.js', 'BooklyL10n', array(
            'sentSuccessfully' => __( 'Sent successfully.', 'bookly-responsive-appointment-booking-tool' ),
            'settingsSaved' => __( 'Settings saved.', 'bookly-responsive-appointment-booking-tool' ),
            'areYouSure' => __( 'Are you sure?', 'bookly-responsive-appointment-booking-tool' ),
            'cron_notice' => Lib\Config::proActive() ? \Bookly\Backend\Components\Notices\Cron\Notice::getText() : null,
            'noResults' => __( 'No records.', 'bookly-responsive-appointment-booking-tool' ),
            'processing' => __( 'Processing', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'emptyTable' => __( 'No data available in table', 'bookly-responsive-appointment-booking-tool' ),
            'zeroRecordsAlt' => __( 'No matching records found', 'bookly-responsive-appointment-booking-tool' ),
            'state' => array( __( 'Disabled', 'bookly-responsive-appointment-booking-tool' ), __( 'Enabled', 'bookly-responsive-appointment-booking-tool' ) ),
            'action' => array( __( 'enable', 'bookly-responsive-appointment-booking-tool' ), __( 'disable', 'bookly-responsive-appointment-booking-tool' ) ),
            'edit' => __( 'Edit', 'bookly-responsive-appointment-booking-tool' ),
            'gateway' => 'email',
            'tab' => $tab,
            'new_notification' => __( 'New notification', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'delete' => __( 'Delete', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'enable' => __( 'Enable', 'bookly-responsive-appointment-booking-tool' ),
            'disable' => __( 'Disable', 'bookly-responsive-appointment-booking-tool' ),
            'rowsPerPage' => __( 'Rows per page', 'bookly-responsive-appointment-booking-tool' ),
            'quick_search' => __( 'Quick search by name', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'zeroRecords' => __( 'No matching records found', 'bookly-responsive-appointment-booking-tool' ),
            'datatables' => $datatables,
        ) );

        self::renderTemplate( 'index', compact( 'tab' ) );
    }
}