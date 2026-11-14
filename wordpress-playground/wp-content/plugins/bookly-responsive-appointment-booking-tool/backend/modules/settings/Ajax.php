<?php
namespace Bookly\Backend\Modules\Settings;

use Bookly\Lib;

class Ajax extends Page
{
    /**
     * Ajax request for Holidays calendar
     */
    public static function settingsHoliday()
    {
        $interval = self::parameter( 'range', array() );
        $range = new Lib\Slots\Range( Lib\Slots\DatePoint::fromStr( $interval[0] ), Lib\Slots\DatePoint::fromStr( $interval[1] )->modify( 1 ) );
        $days = array();
        foreach ( $range->split( DAY_IN_SECONDS ) as $r ) {
            $days[] = $r->start()->value()->format( 'Y-m-d' );
        }

        $repeat = self::parameter( 'repeat' ) == 'true';
        if ( self::parameter( 'holiday' ) == 'true' ) {
            Lib\Utils\Holidays::setCompanyDaysOff( $days, $repeat );
        } else {
            Lib\Utils\Holidays::setCompanyWorkingDays( $days, $repeat );
        }

        wp_send_json_success( self::_getHolidays() );
    }


    public static function sendSmtpTest()
    {
        ob_start();
        $status = Lib\Utils\Mail::sendSmtp(
            self::parameter( 'to' ),
            'Test subject',
            'Test message',
            array(
                'is_html' => Lib\Config::sendEmailAsHtml(),
                'from' => array(
                    'email' => get_option( 'bookly_email_sender' ),
                    'name' => get_option( 'bookly_email_sender_name' ),
                ),
            ),
            array(),
            self::parameter( 'host' ),
            self::parameter( 'port' ),
            self::parameter( 'user' ),
            self::parameter( 'password' ),
            self::parameter( 'secure' ),
            4
        );
        $result = ob_get_clean();

        wp_send_json_success( array( 'result' => $result, 'status' => $status ) );
    }
}