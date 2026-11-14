<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Lib;
use Bookly\Backend\Modules\CloudProducts\Page as CloudProductsPage;
use Bookly\Backend\Modules\CloudSettings\Page as CloudSettingsPage;

/**
 * The Bookly Cloud account as the Account, Billing and Products pages show it — the shape
 * get_cloud_account returns and set_cloud_account / set_cloud_invoice_details take.
 */
abstract class CloudAccount
{
    /** Keys of the invoice details, in the order the dialog sends them. */
    const INVOICE_KEYS = 'company_name,company_address,company_address_l2,company_code,company_vat,company_add_text,send,send_copy,cc';

    /** @var string|null */
    protected static $request;

    /**
     * Mark of this request: Cloud's answer is cached for the request, so an undo in the same
     * request (a later item refused) cannot read back what apply() wrote and trusts the snapshot.
     *
     * @return string
     */
    public static function request()
    {
        if ( self::$request === null ) {
            self::$request = uniqid( '', true );
        }

        return self::$request;
    }

    /**
     * @param array $snapshot
     * @return bool whether apply() of the snapshot ran in this request
     */
    public static function sameRequest( array $snapshot )
    {
        return isset( $snapshot['request'] ) && $snapshot['request'] === self::request();
    }

    /**
     * @return Lib\Cloud\API
     */
    public static function api()
    {
        return Lib\Cloud\API::getInstance();
    }

    /**
     * Read the profile from Cloud again (the pages do it on every load).
     *
     * @return bool whether the site is signed in
     */
    public static function load()
    {
        $api = self::api();

        return $api->getToken() ? $api->account->loadProfile() : false;
    }

    /**
     * Current invoice details: all nine keys, send and send_copy as bool.
     *
     * @return array
     */
    public static function invoice()
    {
        $data    = self::api()->account->getInvoiceData();
        $invoice = array();
        foreach ( explode( ',', self::INVOICE_KEYS ) as $key ) {
            $value = isset( $data[ $key ] ) ? $data[ $key ] : '';
            $invoice[ $key ] = in_array( $key, array( 'send', 'send_copy' ), true ) ? (bool) (int) $value : (string) $value;
        }

        return $invoice;
    }

    /**
     * Current cap on Auto-Recharges as the page shows it.
     *
     * @return array array( 'enabled' => bool, 'max_count' => int, 'interval' => int )
     */
    public static function limit()
    {
        return self::api()->account->getAutoRechargeLimit();
    }

    /**
     * Hours the cap can take: the fixed list plus the value in use.
     *
     * @return int[]
     */
    public static function intervals()
    {
        $limit = self::limit();
        $hours = array();
        foreach ( CloudSettingsPage::getIntervals( $limit['enabled'] ? $limit['interval'] : 0 ) as $item ) {
            $hours[] = (int) $item['value'];
        }

        return $hours;
    }

    /**
     * The whole account for the model.
     *
     * @return array
     */
    public static function data()
    {
        if ( ! self::load() ) {
            return array( 'signed_in' => false );
        }
        $api     = self::api();
        $account = $api->account;
        $data    = array(
            'signed_in'       => true,
            'email'           => $account->getUserName(),
            'email_confirmed' => (bool) $account->getEmailConfirmed(),
            'balance'         => (float) $account->getBalance(),
            'country'         => (string) $account->getCountry(),
        );
        if ( $account->autoRechargeEnabled() ) {
            $data['auto_recharge'] = array(
                'enabled' => true,
                'amount'  => (float) $account->getAutoRechargeAmount(),
                'method'  => $account->getAutoRechargeTitle(),
                'end_at'  => $account->getAutoRechargeEndAt() ?: null,
            );
            $data['auto_recharge_limit'] = self::limit();
            $data['intervals']           = self::intervals();
        }
        $data['support_end_at'] = $account->getCloudSupportEndAt();
        $data['weekly_summary'] = (bool) $account->getNotifySummary();
        $data['invoice']        = self::invoice();

        $subscriptions = $account->getSubscriptions();
        $products      = array();
        foreach ( Lib\Utils\Common::sortCloudProducts( (array) $api->general->getProducts() ) as $product ) {
            if ( ! isset( $product['id'], $product['texts'] ) ) {
                continue;
            }
            $id           = $product['id'];
            $subscription = null;
            foreach ( isset( $product['prices'] ) ? (array) $product['prices'] : array() as $price ) {
                foreach ( (array) $subscriptions as $item ) {
                    if ( isset( $price['id'], $item['product_price_id'] ) && (string) $item['product_price_id'] === (string) $price['id'] ) {
                        $subscription = array(
                            'next_billing_date' => $item['next_billing_date'],
                            'cancel_on_renewal' => ! empty( $item['cancel_on_renewal'] ),
                            'usage'             => isset( $item['usage'] ) && is_array( $item['usage'] ) ? CloudProductsPage::usageText( $id, $item['usage'] ) : null,
                        );
                        break 2;
                    }
                }
            }
            $products[] = array(
                'id'              => $id,
                'title'           => wp_strip_all_tags( $product['texts']['title'] ),
                'price_text'      => isset( $product['texts']['price'] ) ? wp_strip_all_tags( $product['texts']['price'] ) : '',
                'active'          => $account->productActive( $id ),
                'pro_missing'     => ! empty( $product['pro_required'] ) && ! Lib\Config::proActive(),
                'update_required' => isset( $product['version'] ) && version_compare( $product['version'], Lib\Plugin::getVersion(), '>' ) ? (string) $product['version'] : null,
                'subscription'    => $subscription,
            );
        }
        $data['products'] = $products;

        return $data;
    }

    /**
     * Cap as the card shows it.
     *
     * @param array $limit
     * @return string
     */
    public static function limitText( array $limit )
    {
        if ( empty( $limit['enabled'] ) ) {
            return __( 'No limit', 'bookly-responsive-appointment-booking-tool' );
        }
        $hours  = (int) $limit['interval'];
        $period = $hours % 24 === 0
            ? sprintf( _n( '%d day', '%d days', $hours / 24, 'bookly-responsive-appointment-booking-tool' ), $hours / 24 )
            : sprintf( _n( '%d hour', '%d hours', $hours, 'bookly-responsive-appointment-booking-tool' ), $hours );

        return sprintf( __( 'No more than %1$d per %2$s', 'bookly-responsive-appointment-booking-tool' ), (int) $limit['max_count'], $period );
    }

    /**
     * On / off as the card shows it.
     *
     * @param bool $value
     * @return string
     */
    public static function onOff( $value )
    {
        return $value ? __( 'Enabled', 'bookly-responsive-appointment-booking-tool' ) : __( 'Disabled', 'bookly-responsive-appointment-booking-tool' );
    }
}
