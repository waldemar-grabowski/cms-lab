<?php
namespace Bookly\Backend\Components\Notices\Limitation;

use Bookly\Lib;

class Ajax extends Lib\Base\Ajax
{
    public static function requiredBooklyPro()
    {
        wp_send_json_success( array(
            'image' => plugins_url( 'backend/components/notices/limitation/resources/images/bookly-pro-required.png', Lib\Plugin::getMainFile() ),
            'caption' => __( 'This is a Pro version feature', 'bookly-responsive-appointment-booking-tool' ),
            'body' => __( 'To get access to more features, lifetime free updates and 24/7 support, upgrade to the Pro version of Bookly.', 'bookly-responsive-appointment-booking-tool' ),
            'features' => array(
                __( 'Compatibility with Bookly add-ons', 'bookly-responsive-appointment-booking-tool' ),
                __( 'Unlimited staff members', 'bookly-responsive-appointment-booking-tool' ),
                __( 'Unlimited services', 'bookly-responsive-appointment-booking-tool' ),
                __( 'Modern booking forms', 'bookly-responsive-appointment-booking-tool' ),
                __( 'Online meetings', 'bookly-responsive-appointment-booking-tool' ) . ' (Zoom, Google Meet, Jitsi, BigBlueButton)',
                __( 'WooCommerce compatibility', 'bookly-responsive-appointment-booking-tool' ),
                __( 'Google Calendar integration', 'bookly-responsive-appointment-booking-tool' ),
                __( 'Advanced service and staff management', 'bookly-responsive-appointment-booking-tool' ),
            ),
            'upgrade' => __( 'Upgrade', 'bookly-responsive-appointment-booking-tool' ),
            'close' => __( 'Close', 'bookly-responsive-appointment-booking-tool' ),
        ) );
    }
}