<?php
namespace Bookly\Backend\Modules\CloudVoice;

use Bookly\Lib;

class Ajax extends Lib\Base\Ajax
{
    /**
     * Save settings
     *
     * @return void
     */
    public static function cloudVoiceSaveSettings()
    {
        $cloud = Lib\Cloud\API::getInstance();
        $cloud->getProduct( Lib\Cloud\Account::PRODUCT_VOICE )->setSettings( self::parameter( 'language' ) )
            ? wp_send_json_success()
            : wp_send_json_error( array( 'message' => current( $cloud->getErrors() ) ) );
    }

    /**
     * Make a test call
     *
     * @return void
     */
    public static function makeTestCall()
    {
        $cloud = Lib\Cloud\API::getInstance();
        $phone_number = self::parameter( 'phone_number' );
        $cloud->getProduct( Lib\Cloud\Account::PRODUCT_VOICE )->call( $phone_number, 'Hello, this is a test call from Bookly', 'Hello, this is a test call from Bookly' )
            ? wp_send_json_success( array( 'message' => sprintf( __( 'Calling %s', 'bookly-responsive-appointment-booking-tool' ), $phone_number ) . ' …' ) )
            : wp_send_json_error( array( 'message' => current( $cloud->getErrors() ) ?: __( 'Failed', 'bookly-responsive-appointment-booking-tool' ) ) );
    }

    /**
     * Get calls list
     *
     * @return void
     */
    public static function getCallsList()
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

        $data = Lib\Cloud\API::getInstance()->getProduct( Lib\Cloud\Account::PRODUCT_VOICE )->getCallsList( $start, $length, compact( 'start_date', 'end_date' ) );
        $data['draw'] = (int) self::parameter( 'draw' );

        Lib\Utils\Tables::updateSettings( Lib\Utils\Tables::VOICE_DETAILS, null, null, $filter );

        wp_send_json( $data );
    }

    /**
     * Get voice price-list.
     */
    public static function getVoicePriceList()
    {
        $result = Lib\Cloud\API::getInstance()->getProduct( Lib\Cloud\Account::PRODUCT_VOICE )->getPriceList();
        $list   = isset( $result['list'] ) ? $result['list'] : array();

        wp_send_json( array(
            'draw'            => (int) self::parameter( 'draw' ),
            'recordsTotal'    => count( $list ),
            'recordsFiltered' => count( $list ),
            'data'            => $list,
        ) );
    }
}