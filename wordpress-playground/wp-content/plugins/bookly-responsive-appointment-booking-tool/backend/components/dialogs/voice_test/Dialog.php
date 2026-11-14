<?php
namespace Bookly\Backend\Components\Dialogs\VoiceTest;

use Bookly\Lib;

class Dialog extends Lib\Base\Component
{
    /**
     * Render voice test notification dialog.
     */
    public static function render()
    {
        self::enqueueStyles( array(
            'backend' => array( 'css/fontawesome-all.min.css' => array( 'bookly-backend-globals' ), ),
        ) );

        self::enqueueScripts( array(
            'module' => array( 'js/testing-voice.js' => array( 'bookly-backend-globals' ) ),
        ) );

        wp_localize_script( 'bookly-testing-voice.js', 'BooklyL10nTestingVoiceDialog', array(
            'l10n' => array(
                'admin_phone' => get_option( 'bookly_sms_administrator_phone' ),
                'title' => __( 'Test voice notifications', 'bookly-responsive-appointment-booking-tool' ),
                'to_phone' => __( 'To phone', 'bookly-responsive-appointment-booking-tool' ),
                'notification' => __( 'Notification', 'bookly-responsive-appointment-booking-tool' ),
                'call' => __( 'Call', 'bookly-responsive-appointment-booking-tool' ),
                'close' => __( 'Close', 'bookly-responsive-appointment-booking-tool' ),
            ),
        ) );
    }
}