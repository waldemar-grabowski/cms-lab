<?php
namespace Bookly\Backend\Components\UsageStats;

use Bookly\Lib;

class Ajax extends Lib\Base\Ajax
{
    /**
     * Record a usage event from the browser.
     */
    public static function trackUsage()
    {
        $props = self::parameter( 'props', array() );
        Lib\UsageStats::track( self::parameter( 'name' ), is_array( $props ) ? $props : array(), true );

        wp_send_json_success();
    }
}
