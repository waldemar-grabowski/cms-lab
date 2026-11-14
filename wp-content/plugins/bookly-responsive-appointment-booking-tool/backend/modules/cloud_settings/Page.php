<?php
namespace Bookly\Backend\Modules\CloudSettings;

use Bookly\Lib;
use Bookly\Backend\Components;

class Page extends Lib\Base\Component
{
    /**
     * Render page.
     */
    public static function render()
    {
        $cloud = Lib\Cloud\API::getInstance();
        if ( ! $cloud->account->loadProfile() ) {
            Components\Cloud\LoginRequired\Page::render( __( 'Bookly Cloud Account', 'bookly-responsive-appointment-booking-tool' ), self::pageSlug() );
        } else {
            self::enqueueStyles( array(
                // Country flags
                'frontend' => array( 'css/intlTelInput.css' => array( 'bookly-backend-globals' ) ),
            ) );

            self::enqueueScripts( array(
                'module' => array(
                    'js/cloud-settings.js' => array( 'bookly-backend-globals' ),
                    'js/cloud-settings-page.js' => array( 'bookly-cloud-settings.js' ),
                ),
            ) );

            $account = $cloud->account;
            $auto_recharge = $account->autoRechargeEnabled();
            $limit = $account->getAutoRechargeLimit();

            wp_localize_script( 'bookly-cloud-settings-page.js', 'BooklyL10nCloudSettings', array(
                'email' => $account->getUserName(),
                'country' => (string) $account->getCountry(),
                'autoRecharge' => array(
                    'enabled' => (bool) $auto_recharge,
                    'amount' => $account->getAutoRechargeAmount(),
                    'method' => $auto_recharge ? (string) $account->getAutoRechargeTitle() : '',
                    'limit' => array(
                        'enabled' => (bool) $limit['enabled'],
                        'maxCount' => (int) $limit['max_count'],
                        'interval' => (int) $limit['interval'],
                    ),
                ),
                'intervals' => self::getIntervals( $limit['interval'] ),
                'notifications' => array(
                    'lowBalance' => (bool) get_option( 'bookly_cloud_notify_low_balance' ),
                    'weeklySummary' => (bool) $account->getNotifySummary(),
                    // The undelivered-SMS counter on the menu; its home is the SMS page once that is redone
                    'smsBadge' => (bool) get_option( 'bookly_cloud_badge_consider_sms' ),
                ),
                'l10n' => self::getL10n(),
            ) );

            self::renderTemplate( 'index' );
        }
    }

    /**
     * Intervals of the Auto-Recharge limit, in hours, with the one in use among them.
     *
     * @param int $current
     * @return array
     */
    public static function getIntervals( $current )
    {
        $hours_list = array( 1, 2, 3, 6, 12, 24, 48, 168 );
        if ( $current > 0 && ! in_array( $current, $hours_list ) ) {
            $hours_list[] = $current;
            sort( $hours_list );
        }
        $intervals = array();
        foreach ( $hours_list as $hours ) {
            $intervals[] = array(
                'value' => $hours,
                'label' => $hours % 24 === 0
                    ? sprintf( _n( '%d day', '%d days', $hours / 24, 'bookly-responsive-appointment-booking-tool' ), $hours / 24 )
                    : sprintf( _n( '%d hour', '%d hours', $hours, 'bookly-responsive-appointment-booking-tool' ), $hours ),
            );
        }

        return $intervals;
    }

    /**
     * Texts of the invoice details dialog, shared with the Billing page.
     *
     * @return array
     */
    public static function getInvoiceL10n()
    {
        return array(
            'invoice_title' => __( 'Invoice details', 'bookly-responsive-appointment-booking-tool' ),
            'invoice_help' => __( 'Printed on invoices for PayPal payments. Card payment invoices are issued by the payment provider.', 'bookly-responsive-appointment-booking-tool' ),
            'company_name' => __( 'Company name', 'bookly-responsive-appointment-booking-tool' ),
            'company_address' => __( 'Company address', 'bookly-responsive-appointment-booking-tool' ),
            'company_address_l2' => __( 'Company address line 2', 'bookly-responsive-appointment-booking-tool' ),
            'company_code' => __( 'Company number', 'bookly-responsive-appointment-booking-tool' ),
            'company_vat' => __( 'VAT / Tax number', 'bookly-responsive-appointment-booking-tool' ),
            'company_add_text' => __( 'Additional text to include in invoice', 'bookly-responsive-appointment-booking-tool' ),
            'send_invoice' => __( 'Send invoice', 'bookly-responsive-appointment-booking-tool' ),
            'send_invoice_to' => __( 'The invoice will be sent to %s', 'bookly-responsive-appointment-booking-tool' ),
            'send_copy' => __( 'Send a copy of the invoice to additional email addresses', 'bookly-responsive-appointment-booking-tool' ),
            'send_copy_help' => __( 'Enter one or more email addresses separated by commas.', 'bookly-responsive-appointment-booking-tool' ),
            'required' => __( 'Required', 'bookly-responsive-appointment-booking-tool' ),
            'save' => __( 'Save', 'bookly-responsive-appointment-booking-tool' ),
            'close' => __( 'Close', 'bookly-responsive-appointment-booking-tool' ),
            'settings_saved' => __( 'Settings saved.', 'bookly-responsive-appointment-booking-tool' ),
            'request_failed' => __( 'Request failed. Please try again later.', 'bookly-responsive-appointment-booking-tool' ),
        );
    }

    /**
     * @return array
     */
    protected static function getL10n()
    {
        return array_merge( self::getInvoiceL10n(), array(
            'account' => __( 'Account', 'bookly-responsive-appointment-booking-tool' ),
            'account_help' => __( 'Invoices and notifications are sent to the email address you use to sign in.', 'bookly-responsive-appointment-booking-tool' ),
            'auto_recharge' => __( 'Auto-Recharge', 'bookly-responsive-appointment-booking-tool' ),
            'auto_recharge_off' => __( 'Disabled', 'bookly-responsive-appointment-booking-tool' ),
            'auto_recharge_off_help' => sprintf( __( 'Tops up the balance automatically when it drops below %s.', 'bookly-responsive-appointment-booking-tool' ), '$' . Lib\Cloud\Account::AUTO_RECHARGE_THRESHOLD ),
            'change' => __( 'Change', 'bookly-responsive-appointment-booking-tool' ),
            'change_password' => __( 'Change password', 'bookly-responsive-appointment-booking-tool' ),
            'country' => __( 'Country', 'bookly-responsive-appointment-booking-tool' ),
            'country_help' => __( 'The country your business operates from.', 'bookly-responsive-appointment-booking-tool' ),
            'email' => __( 'Email', 'bookly-responsive-appointment-booking-tool' ),
            'enable' => __( 'Enable', 'bookly-responsive-appointment-booking-tool' ),
            'limit_help' => __( 'Sets the maximum number of Auto-Recharges that pay-as-you-go products can initiate during the specified time period. Once the limit is reached, further Auto-Recharges will be skipped until the period ends.', 'bookly-responsive-appointment-booking-tool' ),
            'limit_interval' => __( 'Auto-Recharges per', 'bookly-responsive-appointment-booking-tool' ),
            'limit_max_count' => __( 'Perform no more than', 'bookly-responsive-appointment-booking-tool' ),
            'limit_switch' => __( 'Limit the number of Auto-Recharges', 'bookly-responsive-appointment-booking-tool' ),
            'low_balance' => __( 'Send email notification to administrators at low balance', 'bookly-responsive-appointment-booking-tool' ),
            'new_password' => __( 'New password', 'bookly-responsive-appointment-booking-tool' ),
            'no_results' => __( 'No records.', 'bookly-responsive-appointment-booking-tool' ),
            'notifications' => __( 'Notifications', 'bookly-responsive-appointment-booking-tool' ),
            'notifications_help' => __( 'Emails about the account, sent to the account email.', 'bookly-responsive-appointment-booking-tool' ),
            'old_password' => __( 'Old password', 'bookly-responsive-appointment-booking-tool' ),
            'password_changed' => __( 'Password changed.', 'bookly-responsive-appointment-booking-tool' ),
            'passwords_not_match' => __( 'Passwords don\'t match', 'bookly-responsive-appointment-booking-tool' ),
            'payment_method' => __( 'Payment method', 'bookly-responsive-appointment-booking-tool' ),
            'repeat_new_password' => __( 'Repeat new password', 'bookly-responsive-appointment-booking-tool' ),
            'search' => __( 'Search', 'bookly-responsive-appointment-booking-tool' ),
            'select_country' => __( 'Select country', 'bookly-responsive-appointment-booking-tool' ),
            'sms_badge' => __( 'Show SMS notification icon', 'bookly-responsive-appointment-booking-tool' ),
            'weekly_summary' => __( 'Send weekly summary', 'bookly-responsive-appointment-booking-tool' ),
        ) );
    }
}
