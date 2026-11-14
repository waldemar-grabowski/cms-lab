<?php
namespace Bookly\Backend\Modules\CloudWhatsapp;

use Bookly\Lib;

class Ajax extends Lib\Base\Ajax
{
    /**
     * Save settings
     *
     * @return void
     */
    public static function cloudWhatsappSaveSettings()
    {
        $cloud = Lib\Cloud\API::getInstance();
        $cloud->getProduct( Lib\Cloud\Account::PRODUCT_WHATSAPP )->setSettings( self::parameter( 'access_token' ), self::parameter( 'phone_id' ), self::parameter( 'business_account_id' ) )
            ? wp_send_json_success()
            : wp_send_json_error( array( 'message' => current( $cloud->getErrors() ) ) );
    }

    /**
     * Get messages list
     *
     * @return void
     */
    public static function getMessagesList()
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

        $data = Lib\Cloud\API::getInstance()->getProduct( Lib\Cloud\Account::PRODUCT_WHATSAPP )->getMessagesList( $start, $length, compact( 'start_date', 'end_date' ) );
        $data['draw'] = (int) self::parameter( 'draw' );

        Lib\Utils\Tables::updateSettings( Lib\Utils\Tables::WHATSAPP_DETAILS, null, null, $filter );

        wp_send_json( $data );
    }
}