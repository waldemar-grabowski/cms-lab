<?php
namespace Bookly\Backend\Modules\CloudBilling;

use Bookly\Lib;

class Ajax extends Lib\Base\Ajax
{
    /**
     * Get purchases list.
     */
    public static function getPurchasesList()
    {
        $filter = self::parameter( 'filter' );
        $range  = $filter['range'];

        if ( $range === 'any' ) {
            $start_date = null;
            $end_date   = null;
        } else {
            $dates = explode( ' - ', $range, 2 );
            $start_date = Lib\Utils\DateTime::applyTimeZoneOffset( $dates[0], 0 );
            $end_date   = Lib\Utils\DateTime::applyTimeZoneOffset( date( 'Y-m-d', strtotime( '+1 day', strtotime( $dates[1] ) ) ), 0 );
        }

        $length = self::parameter( 'length' );
        $start  = self::parameter( 'start' );

        $data = Lib\Cloud\API::getInstance()->account->getPurchasesList( $start, $length, compact( 'start_date', 'end_date' ) );
        $data['draw'] = (int) self::parameter( 'draw' );

        wp_send_json( $data );
    }
}
