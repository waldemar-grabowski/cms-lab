<?php
namespace Bookly\Backend\Components\Dialogs\TableSettings;

use Bookly\Lib;

class Ajax extends Lib\Base\Ajax
{
    /** @var array */
    protected static $tables = array(
        Lib\Utils\Tables::ANALYTICS,
        Lib\Utils\Tables::APPOINTMENTS,
        Lib\Utils\Tables::CLOUD_MOBILE_STAFF_CABINET,
        Lib\Utils\Tables::CLOUD_PURCHASES,
        Lib\Utils\Tables::COUPONS,
        Lib\Utils\Tables::CUSTOMERS,
        Lib\Utils\Tables::CUSTOMER_GROUPS,
        Lib\Utils\Tables::CUSTOM_STATUSES,
        Lib\Utils\Tables::DISCOUNTS,
        Lib\Utils\Tables::EMAIL_LOGS,
        Lib\Utils\Tables::EMAIL_NOTIFICATIONS,
        Lib\Utils\Tables::EVENTS,
        Lib\Utils\Tables::GIFT_CARDS,
        Lib\Utils\Tables::GIFT_CARD_TYPES,
        Lib\Utils\Tables::LOCATIONS,
        Lib\Utils\Tables::PACKAGES,
        Lib\Utils\Tables::PAYMENTS,
        Lib\Utils\Tables::SERVICES,
        Lib\Utils\Tables::SMS_DETAILS,
        Lib\Utils\Tables::SMS_MAILING_CAMPAIGNS,
        Lib\Utils\Tables::SMS_NOTIFICATIONS,
        Lib\Utils\Tables::SMS_PRICES,
        Lib\Utils\Tables::SMS_SENDER,
        Lib\Utils\Tables::STAFF_MEMBERS,
        Lib\Utils\Tables::TAXES,
        Lib\Utils\Tables::VOICE_DETAILS,
        Lib\Utils\Tables::VOICE_NOTIFICATIONS,
        Lib\Utils\Tables::VOICE_PRICES,
        Lib\Utils\Tables::WHATSAPP_DETAILS,
        Lib\Utils\Tables::WHATSAPP_NOTIFICATIONS,
    );

    /**
     * @inheritDoc
     */
    protected static function permissions()
    {
        return array( '_default' => array( 'staff', 'supervisor' ) );
    }

    /**
     * Update table settings.
     */
    public static function updateTableSettings()
    {
        $table = self::parameter( 'table' );

        if ( ! in_array( $table, self::$tables ) ) {
            wp_send_json_success();
        }

        $meta = get_user_meta( get_current_user_id(), 'bookly_' . $table . '_table_settings', true ) ?: array();

        // Each field is updated only when explicitly sent — lets callers persist a partial
        // payload (e.g. only "filter") without clobbering the other keys to defaults.
        if ( self::hasParameter( 'page_length' ) ) {
            $meta['page_length'] = self::parameter( 'page_length' );
        }
        if ( self::hasParameter( 'columns' ) ) {
            $meta['columns'] = self::parameter( 'columns', array() );
            array_walk( $meta['columns'], function( &$show ) { $show = (bool) $show; } );
        }
        if ( self::hasParameter( 'appearance' ) ) {
            $appearance = self::parameter( 'appearance', array() );
            $meta['appearance'] = array(
                'responsive_table' => ! empty( $appearance['responsive_table'] ),
            );
        }
        if ( self::hasParameter( 'filter_json' ) ) {
            // Sent as JSON string from Form.svelte to survive jQuery's empty-array drop;
            // decode here back to an associative array for user_meta storage.
            $decoded = json_decode( (string) self::parameter( 'filter_json' ), true );
            $meta['filter'] = is_array( $decoded ) ? $decoded : array();
        } elseif ( self::hasParameter( 'filter' ) ) {
            $meta['filter'] = self::parameter( 'filter', array() );
        }

        update_user_meta( get_current_user_id(), 'bookly_' . $table . '_table_settings', $meta );

        wp_send_json_success();
    }

    /**
     * Update table sorting.
     */
    public static function updateTableOrder()
    {
        $table = self::parameter( 'table' );

        $meta = get_user_meta( get_current_user_id(), 'bookly_' . $table . '_table_settings', true ) ?: array();
        if ( in_array( $table, self::$tables ) ) {
            $meta['order'] = self::parameter( 'order', array() );
            update_user_meta( get_current_user_id(), 'bookly_' . $table . '_table_settings', $meta );
        }

        wp_send_json_success();
    }
}