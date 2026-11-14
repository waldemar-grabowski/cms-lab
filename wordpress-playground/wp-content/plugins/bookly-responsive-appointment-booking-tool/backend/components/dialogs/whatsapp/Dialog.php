<?php
namespace Bookly\Backend\Components\Dialogs\Whatsapp;

use Bookly\Backend\Modules\Notifications\Lib\Codes;
use Bookly\Lib\Config;
use Bookly\Lib\Entities\Notification;
use Bookly\Backend\Components\Dialogs\Sms\Dialog as SmsDialog;

class Dialog extends SmsDialog
{
    /**
     * Render WhatsApp notification dialog.
     */
    public static function render()
    {
        self::enqueueStyles( array(
            'backend' => array( 'css/fontawesome-all.min.css' => array( 'bookly-backend-globals' ), ),
        ) );

        self::enqueueScripts( array(
            'bookly' => array( 'backend/components/dialogs/sms/resources/js/notification-dialog.js' => array( 'bookly-backend-globals' ) ),
        ) );

        $codes = new Codes( 'sms' );
        $codes_list = array();
        foreach ( Notification::getTypes() as $notification_type ) {
            $codes_list[ $notification_type ] = $codes->getCodes( $notification_type );
        }

        wp_localize_script( 'bookly-notification-dialog.js', 'BooklyNotificationDialogL10n', array(
            'recurringActive' => (int) Config::recurringAppointmentsActive(),
            'defaultNotification' => self::getDefaultNotification(),
            'codes' => $codes_list,
            'gateway' => 'whatsapp',
            'title' => array(
                'container' => __( 'Message', 'bookly-responsive-appointment-booking-tool' ),
                'new' => __( 'New WhatsApp notification', 'bookly-responsive-appointment-booking-tool' ),
                'edit' => __( 'Edit WhatsApp notification', 'bookly-responsive-appointment-booking-tool' ),
                'create' => __( 'Create', 'bookly-responsive-appointment-booking-tool' ),
                'save' => __( 'Save', 'bookly-responsive-appointment-booking-tool' ),
            ),
            'statuses' => array(
                'APPROVED' => __( 'Approved', 'bookly-responsive-appointment-booking-tool' ),
                'IN_APPEAL' => __( 'In appeal', 'bookly-responsive-appointment-booking-tool' ),
                'PENDING' => __( 'Pending', 'bookly-responsive-appointment-booking-tool' ),
                'REJECTED' => __( 'Rejected', 'bookly-responsive-appointment-booking-tool' ),
                'PENDING_DELETION' => __( 'Pending deletion', 'bookly-responsive-appointment-booking-tool' ),
                'DELETED' => __( 'Deleted', 'bookly-responsive-appointment-booking-tool' ),
                'DISABLED' => __( 'Disabled', 'bookly-responsive-appointment-booking-tool' ),
                'PAUSED' => __( 'Paused', 'bookly-responsive-appointment-booking-tool' ),
                'LIMIT_EXCEEDED' => __( 'Limit exceeded', 'bookly-responsive-appointment-booking-tool' ),
            ),
        ) );

        SmsDialog::renderTemplate( 'dialog', array( 'self' => __CLASS__, 'gateway' => 'whatsapp' ) );
    }
}