<?php
namespace Bookly\Backend\Components\Notices\Cron;

use Bookly\Lib;

class Notice extends Lib\Base\Component
{
    /**
     * Render configure cron notice.
     *
     * @param string $class Extra CSS classes for the notice wrapper. Defaults to bookly:mb-0
     *   (no bottom margin — fits card contexts where the card padding provides the gap). Form
     *   contexts where the notice sits between fields should pass a bottom margin, e.g. bookly:mb-4.
     */
    public static function render( $class = 'bookly:mb-0' )
    {
        $text = self::getText();
        if ( $text !== null ) {
            return self::renderTemplate( 'notice', compact( 'class', 'text' ) );
        }
    }

    /**
     * Configure cron notice text (HTML), or null when Cloud Cron is active.
     * Datatables show it through their own notice option.
     *
     * @return string|null
     */
    public static function getText()
    {
        if ( Lib\Cloud\API::getInstance()->account->productActive( 'cron' ) ) {
            return null;
        }

        return sprintf( __( 'To allow scheduled actions, please activate <a href="%s" target="_blank">Bookly Cloud Cron</a> or follow <a href="%s" target="_blank">the instructions</a> about cron setup', 'bookly-responsive-appointment-booking-tool' ),
            Lib\Utils\Common::escAdminUrl( \Bookly\Backend\Modules\CloudProducts\Page::pageSlug(), array( 'product' => Lib\Cloud\Account::PRODUCT_CRON ) ),
            'https://support.booking-wp-plugin.com/hc/en-us/articles/360015017400-How-can-I-configure-CRON-to-send-the-Bookly-reminders' );
    }
}
