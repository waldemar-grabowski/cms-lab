<?php
namespace Bookly\Backend\Components\Notices\PromoBanner;

use Bookly\Lib;

class Ajax extends Lib\Base\Ajax
{
    /**
     * Dismiss promo banner.
     */
    public static function dismissPromoBanner()
    {
        $id = self::parameter( 'id' );
        if ( in_array( $id, Banner::ids(), true ) ) {
            Lib\UsageStats::track( 'promo_banner.dismiss', array(
                'banner' => $id,
                'page' => Banner::usagePage( self::parameter( 'page', '' ) ),
                'times' => Banner::dismiss( $id ),
            ) );
        }

        wp_send_json_success();
    }
}
