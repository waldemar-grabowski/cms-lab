<?php
namespace Bookly\Backend\Components\Notices\PromoBanner;

use Bookly\Lib;
use Bookly\Lib\Mailing\Audience;
use Bookly\Backend\Modules\CloudProducts\Page as CloudProducts;
use Bookly\Backend\Modules\CloudSms\Page as CloudSms;

/**
 * Promo banners inside a page card. A page names its banners in order of priority
 * and shows only the first one whose conditions are met and which the user has not hidden.
 */
class Banner extends Lib\Base\Component
{
    const SMS_SENDER_ID = 'sms_sender_id';
    const EMAIL_SMS_REMINDERS = 'email_sms_reminders';
    const SMS_CAMPAIGNS = 'sms_campaigns';

    /** Fewest emails a day, on average over the counted period, for the SMS banner on the email page. */
    const EMAILS_PER_DAY = 1;

    /** @var int[] Days a banner stays hidden after each dismissal; after the last one it is hidden for good. */
    protected static $dismiss_days = array( 3, 7, 30 );

    /** @var string[] Audiences the campaigns banner speaks about, in order of priority. */
    protected static $campaign_presets = array(
        Audience::PRESET_LAPSED,
        Audience::PRESET_REGULAR,
        Audience::PRESET_CANCELLED,
        Audience::PRESET_ONCE,
        Audience::PRESET_BIRTHDAY,
        Audience::PRESET_ALL,
    );

    /**
     * Render the first banner of the list that is due.
     *
     * @param string[] $ids
     */
    public static function render( array $ids )
    {
        foreach ( $ids as $id ) {
            if ( self::isDismissed( $id ) ) {
                continue;
            }
            $data = self::data( $id );
            if ( $data !== null ) {
                self::enqueueScripts( array(
                    'module' => array( 'js/promo-banner.js' => array( 'bookly-backend-globals' ) ),
                ) );
                self::renderTemplate( $id, array( 'banner' => $data ) );
                Lib\UsageStats::track( 'promo_banner.shown', array(
                    'banner' => $id,
                    'page' => isset( $_GET['page'] ) ? self::usagePage( $_GET['page'] ) : '',
                    'preset' => isset( $data['preset'] ) ? $data['preset'] : '',
                ) );

                return;
            }
        }
    }

    /**
     * @return string[]
     */
    public static function ids()
    {
        return array( self::SMS_SENDER_ID, self::EMAIL_SMS_REMINDERS, self::SMS_CAMPAIGNS );
    }

    /**
     * Whether the current user has hidden the banner.
     *
     * @param string $id
     * @return bool
     */
    public static function isDismissed( $id )
    {
        $dismissed = get_user_meta( get_current_user_id(), 'bookly_dismiss_promo_banners', true ) ?: array();

        return isset( $dismissed[ $id ] ) && ( $dismissed[ $id ]['until'] === null || $dismissed[ $id ]['until'] > time() );
    }

    /**
     * Page name for usage statistics: the admin page slug without the plugin prefix.
     *
     * @param string $slug
     * @return string
     */
    public static function usagePage( $slug )
    {
        return preg_replace( '/^bookly-/', '', sanitize_key( $slug ) );
    }

    /**
     * Hide the banner for the current user, each time for longer.
     *
     * @param string $id
     * @return int How many times the user has hidden the banner.
     */
    public static function dismiss( $id )
    {
        $dismissed = get_user_meta( get_current_user_id(), 'bookly_dismiss_promo_banners', true ) ?: array();
        $count = isset( $dismissed[ $id ] ) ? $dismissed[ $id ]['count'] + 1 : 1;
        $dismissed[ $id ] = array(
            'count' => $count,
            'until' => isset( self::$dismiss_days[ $count - 1 ] ) ? time() + self::$dismiss_days[ $count - 1 ] * DAY_IN_SECONDS : null,
        );
        update_user_meta( get_current_user_id(), 'bookly_dismiss_promo_banners', $dismissed );

        return $count;
    }

    /**
     * Data of the banner when its conditions are met, otherwise null.
     *
     * @param string $id
     * @return array|null
     */
    protected static function data( $id )
    {
        switch ( $id ) {
            case self::SMS_SENDER_ID:
                return self::senderId();
            case self::EMAIL_SMS_REMINDERS:
                return self::emailSmsReminders();
            case self::SMS_CAMPAIGNS:
                return self::smsCampaigns();
        }

        return null;
    }

    /**
     * SMS product is active, messages go from a shared number and there is no pending Sender ID request.
     *
     * @return array|null
     */
    protected static function senderId()
    {
        $cloud = Lib\Cloud\API::getInstance();
        $sms = $cloud->getProduct( Lib\Cloud\Account::PRODUCT_SMS_NOTIFICATIONS );
        if ( ! $cloud->account->productActive( Lib\Cloud\Account::PRODUCT_SMS_NOTIFICATIONS ) || $sms->getSenderIdApprovalDate() ) {
            return null;
        }
        $requests = $sms->getSenderIdsList();

        return $requests['pending'] === null ? array() : null;
    }

    /**
     * Email reminders are active and emails were sent recently, while SMS reminders are not working
     * (no SMS product or all SMS notifications are disabled).
     *
     * @return array|null
     */
    protected static function emailSmsReminders()
    {
        $email_reminders = Lib\Entities\Notification::query()
            ->where( 'gateway', 'email' )
            ->where( 'type', Lib\Entities\Notification::TYPE_APPOINTMENT_REMINDER )
            ->where( 'active', 1 )
            ->count();
        if ( ! $email_reminders ) {
            return null;
        }
        $cloud = Lib\Cloud\API::getInstance();
        if ( $cloud->account->productActive( Lib\Cloud\Account::PRODUCT_SMS_NOTIFICATIONS )
            && Lib\Entities\Notification::query()->where( 'gateway', 'sms' )->where( 'active', 1 )->count()
        ) {
            return null;
        }
        // With Pro every sent email is in the email log. The log is kept for 7, 30, 90 or 365 days,
        // so the period counted is 30 days, or 7 when the log is kept shorter; the banner text names
        // each period in full. Without Pro only reminders are known.
        $days = (int) get_option( 'bookly_email_logs_expire', 30 ) >= 30 ? 30 : 7;
        $sent = Lib\Proxy\Pro::getEmailLogsCount( date_create( current_time( 'mysql' ) )->modify( '-' . $days . ' days' )->format( 'Y-m-d H:i:s' ) );
        $reminders = $sent === null;
        if ( $reminders ) {
            $days = 30;
            $sent = Lib\Entities\SentNotification::query( 'sn' )
                ->leftJoin( 'Notification', 'n', 'n.id = sn.notification_id' )
                ->where( 'n.gateway', 'email' )
                ->where( 'n.type', Lib\Entities\Notification::TYPE_APPOINTMENT_REMINDER )
                ->whereGte( 'sn.created_at', date_create( current_time( 'mysql' ) )->modify( '-30 days' )->format( 'Y-m-d H:i:s' ) )
                ->count();
        }
        // A few emails make a poor case for SMS: the banner waits for steady sending.
        if ( $sent < $days * self::EMAILS_PER_DAY ) {
            return null;
        }

        $sms_product_url = self::smsProductUrl();

        return array(
            'sent' => $sent,
            'days' => $days,
            'reminders' => $reminders,
            // Logged in to Cloud: the test SMS form on the SMS page, otherwise the SMS product page.
            'test_url' => $cloud->getToken()
                ? add_query_arg( array( 'page' => CloudSms::pageSlug(), 'tab' => 'notifications' ), admin_url( 'admin.php' ) )
                : $sms_product_url,
            'info_url' => $cloud->getToken() ? $sms_product_url : null,
        );
    }

    /**
     * More than 10 customers with a phone and no SMS campaign yet. The banner speaks about
     * the first audience of $campaign_presets that has recipients.
     *
     * @return array|null
     */
    protected static function smsCampaigns()
    {
        if ( Lib\Entities\MailingCampaign::query()->count() ) {
            return null;
        }
        if ( Lib\Entities\Customer::query()->whereNot( 'phone', '' )->count() <= 10 ) {
            return null;
        }
        // Counting the audiences runs heavy queries over appointments, a day-old number is good enough.
        $counts = get_transient( 'bookly_promo_audience_counts' );
        if ( $counts === false ) {
            $counts = Audience::presetCounts();
            set_transient( 'bookly_promo_audience_counts', $counts, DAY_IN_SECONDS );
        }
        $preset = null;
        foreach ( self::$campaign_presets as $key ) {
            if ( ! empty( $counts[ $key ] ) ) {
                $preset = $key;
                break;
            }
        }
        if ( $preset === null ) {
            return null;
        }

        $cloud = Lib\Cloud\API::getInstance();
        $ready = $cloud->getToken() && $cloud->account->productActive( Lib\Cloud\Account::PRODUCT_SMS_NOTIFICATIONS );

        return array(
            'preset' => $preset,
            'count' => $counts[ $preset ],
            // Logged in to Cloud with SMS active: the new campaign on the SMS page, otherwise the SMS product page.
            'url' => $ready
                ? add_query_arg( array( 'page' => CloudSms::pageSlug(), 'tab' => 'campaigns', 'preset' => $preset ), admin_url( 'admin.php' ) )
                : self::smsProductUrl(),
            'dialog' => $ready,
        );
    }

    /**
     * @return string
     */
    protected static function smsProductUrl()
    {
        return add_query_arg( array( 'page' => CloudProducts::pageSlug(), 'product' => Lib\Cloud\Account::PRODUCT_SMS_NOTIFICATIONS ), admin_url( 'admin.php' ) );
    }
}
