<?php
namespace Bookly\Backend\Components\Cloud\Account;

use Bookly\Lib;
use Bookly\Backend\Modules;
use Bookly\Backend\Components\Cloud\Recharge\Amounts;

class Panel extends Lib\Base\Component
{
    /** @var bool */
    protected static $login_required = false;

    /**
     * Make the block open the sign-in dialog as soon as it appears.
     */
    public static function requireLogin()
    {
        self::$login_required = true;
    }

    /**
     * Render the account block.
     */
    public static function render()
    {
        $cloud = Lib\Cloud\API::getInstance();
        $logged_in = (bool) $cloud->account->loadProfile();

        self::enqueueStyles( array(
            // Country flags
            'frontend' => array( 'css/intlTelInput.css' => array( 'bookly-backend-globals' ) ),
        ) );

        self::enqueueScripts( array(
            'module' => array(
                'js/cloud-account.js' => array( 'bookly-backend-globals' ),
                'js/cloud-panel.js' => array( 'bookly-cloud-account.js' ),
            ),
        ) );

        $promotions = get_option( 'bookly_cloud_promotions', array() );
        $promo = isset ( $promotions['registration']['texts'] )
            ? $promotions['registration']['texts']
            : array();

        // Sign-in by code can be switched off from outside, it is on unless told otherwise
        $setup = get_option( 'bookly_setup_wizard' );
        $code_signin = ! ( is_array( $setup ) && isset ( $setup['config']['code_signin'] ) ) || $setup['config']['code_signin'];

        $options = array(
            'loggedIn' => $logged_in,
            'loginRequired' => self::$login_required,
            'codeSignin' => (bool) $code_signin,
            'promo' => array(
                'form' => empty ( $promo['form'] ) ? null : Lib\Utils\Common::stripScripts( $promo['form'] ),
                'button' => empty ( $promo['button'] ) ? null : Lib\Utils\Common::stripScripts( $promo['button'] ),
            ),
            'urls' => array(
                'products' => Lib\Utils\Common::escAdminUrl( Modules\CloudProducts\Page::pageSlug() ),
                'profile' => Lib\Utils\Common::escAdminUrl( Modules\CloudSettings\Page::pageSlug() ),
                'supportInfo' => 'https://hub.bookly.pro/go/bookly-support',
            ),
            'l10n' => self::getL10n(),
        );
        if ( $logged_in ) {
            $options['account'] = self::getAccountData( $cloud );
            $options['recharge'] = self::getRechargeData( $cloud );
        }

        wp_localize_script( 'bookly-cloud-panel.js', 'BooklyL10nCloudAccount', $options );

        echo '<div id="bookly-cloud-account"></div>';
    }

    /**
     * State of the account shown by the block.
     *
     * @param Lib\Cloud\API $cloud
     * @return array
     */
    protected static function getAccountData( Lib\Cloud\API $cloud )
    {
        $account = $cloud->account;
        $balance = $account->getBalance();
        $auto_recharge = $account->autoRechargeEnabled();

        $support_days = $account->getCloudSupportDays();
        if ( $support_days < 0 ) {
            $support_status = 'expired';
            $support_label = __( 'Expired', 'bookly-responsive-appointment-booking-tool' );
        } else {
            $support_status = $support_days <= 3 ? 'expiring' : 'active';
            $support_label = sprintf( _n( '%d day', '%d days', $support_days, 'bookly-responsive-appointment-booking-tool' ), $support_days );
        }

        return array(
            'email' => $account->getUserName(),
            'emailConfirmed' => (bool) $account->getEmailConfirmed(),
            'balance' => number_format( $balance, 2 ),
            'lowBalance' => $balance <= Lib\Cloud\Account::AUTO_RECHARGE_THRESHOLD,
            // The service keeps no date or sum of the last top-up, so the line under
            // the balance says what the balance is for
            'balanceNote' => __( 'Pays for SMS, WhatsApp and Voice', 'bookly-responsive-appointment-booking-tool' ),
            'country' => (string) $account->getCountry(),
            'autoRecharge' => array(
                'enabled' => (bool) $auto_recharge,
                'amount' => $account->getAutoRechargeAmount(),
                'bonus' => $account->getAutoRechargeBonus(),
                // What it is charged to: the card itself when the service tells it, the gateway otherwise
                'method' => $auto_recharge ? (string) $account->getAutoRechargeTitle() : '',
                'endDate' => $auto_recharge && $account->getAutoRechargeEndAt()
                    ? sprintf( __( 'until %s', 'bookly-responsive-appointment-booking-tool' ), Lib\Utils\DateTime::formatDate( $account->getAutoRechargeEndAt() ) )
                    : '',
                // What switching it on would do, shown while it is off
                'offNote' => sprintf( __( 'Tops up the balance when it drops below %s', 'bookly-responsive-appointment-booking-tool' ), '$' . Lib\Cloud\Account::AUTO_RECHARGE_THRESHOLD ),
            ),
            'support' => array(
                'status' => $support_status,
                'label' => $support_label,
                // The date alone: the tile it goes to already says what the date is of
                'endDate' => $account->getCloudSupportEndAt() === null
                    ? ''
                    : Lib\Utils\DateTime::formatDate( $account->getCloudSupportEndAt() ),
                // How support is obtained, shown while there is no date to show
                'note' => __( 'Extended with every top-up', 'bookly-responsive-appointment-booking-tool' ),
            ),
        );
    }

    /**
     * Catalogue of the recharge dialog.
     *
     * @param Lib\Cloud\API $cloud
     * @return array
     */
    protected static function getRechargeData( Lib\Cloud\API $cloud )
    {
        $recharge = $cloud->account->getRechargeData();

        // Served for the current account only while it is eligible (no top-ups yet)
        $promotions = get_option( 'bookly_cloud_promotions' );
        $first_recharge = is_array( $promotions ) && isset ( $promotions['first_recharge'] ) ? $promotions['first_recharge'] : null;

        // The catalogue is read defensively: it is served from outside, and what it
        // holds changes over time while this code stays as installed.
        $catalogue = isset ( $recharge['amounts'] ) && is_array( $recharge['amounts'] ) ? $recharge['amounts'] : array();
        $amounts = array();
        foreach ( array( Amounts::RECHARGE_TYPE_MANUAL, Amounts::RECHARGE_TYPE_AUTO ) as $type ) {
            $amounts[ $type ] = array();
            $items = isset ( $catalogue[ $type ] ) && is_array( $catalogue[ $type ] ) ? $catalogue[ $type ] : array();
            foreach ( $items as $item ) {
                if ( ! isset ( $item['id'], $item['amount'] ) ) {
                    continue;
                }
                // First top-up bonus replaces the regular one. A tiered promotion pays
                // only for the listed amounts, an untiered one is flat for any amount.
                $bonus = isset ( $item['bonus'] ) ? $item['bonus'] : null;
                $first_bonus = false;
                if ( $first_recharge ) {
                    if ( ! empty ( $first_recharge['recharges'] ) ) {
                        if ( isset ( $first_recharge['recharges'][ $item['id'] ] ) ) {
                            $bonus = $first_recharge['recharges'][ $item['id'] ];
                            $first_bonus = true;
                        }
                    } elseif ( isset ( $first_recharge['amount'] ) ) {
                        $bonus = $first_recharge['amount'];
                        $first_bonus = true;
                    }
                }
                $support_days = isset ( $item['extend_support'] ) ? (int) $item['extend_support'] : 0;
                $amounts[ $type ][] = array(
                    'id' => $item['id'],
                    'amount' => $item['amount'],
                    'bonus' => $bonus,
                    // The bonus is paid once, for the first top-up of the account
                    'firstBonus' => $first_bonus,
                    'tags' => isset ( $item['tags'] ) ? array_values( (array) $item['tags'] ) : array(),
                    'support' => $support_days > 0
                        ? sprintf( _n( '+ %d day of support', '+ %d days of support', $support_days, 'bookly-responsive-appointment-booking-tool' ), $support_days )
                        : '',
                );
            }
        }

        return array(
            'noCard' => isset ( $recharge['no_card'] ) ? array_values( (array) $recharge['no_card'] ) : array(),
            // Text of the first top-up promotion, as the promotion itself words it
            'promoInfo' => $first_recharge && ! empty ( $first_recharge['texts']['info'] )
                ? Lib\Utils\Common::stripScripts( $first_recharge['texts']['info'] )
                : null,
            'amounts' => $amounts,
        );
    }

    /**
     * @return array
     */
    protected static function getL10n()
    {
        $threshold = '$' . Lib\Cloud\Account::AUTO_RECHARGE_THRESHOLD;
        // The address is set in between by the dialog, as text and not as markup
        $code_sent = explode( '%email%', __( 'We emailed a code to %email%. Enter it to sign in.', 'bookly-responsive-appointment-booking-tool' ), 2 );

        return array(
            // Block
            'account_details' => __( 'Account details', 'bookly-responsive-appointment-booking-tool' ),
            'auto_recharge' => __( 'Auto-Recharge', 'bookly-responsive-appointment-booking-tool' ),
            'balance' => __( 'Balance', 'bookly-responsive-appointment-booking-tool' ),
            'change' => __( 'Change', 'bookly-responsive-appointment-booking-tool' ),
            'confirm_email' => __( 'Confirm email', 'bookly-responsive-appointment-booking-tool' ),
            'disabled' => __( 'Disabled', 'bookly-responsive-appointment-booking-tool' ),
            'enable' => __( 'Enable', 'bookly-responsive-appointment-booking-tool' ),
            'extend' => __( 'Extend', 'bookly-responsive-appointment-booking-tool' ),
            'guest_subtitle' => __( 'One account for SMS, WhatsApp, and other Bookly Cloud services.', 'bookly-responsive-appointment-booking-tool' ),
            'guest_title' => __( 'Bookly Cloud account', 'bookly-responsive-appointment-booking-tool' ),
            'how_it_works' => __( 'How it works', 'bookly-responsive-appointment-booking-tool' ),
            'log_in' => __( 'Log in', 'bookly-responsive-appointment-booking-tool' ),
            'log_out' => __( 'Log out', 'bookly-responsive-appointment-booking-tool' ),
            'profile' => __( 'Settings', 'bookly-responsive-appointment-booking-tool' ),
            'recharge' => __( 'Recharge', 'bookly-responsive-appointment-booking-tool' ),
            'register' => __( 'Register', 'bookly-responsive-appointment-booking-tool' ),
            'support' => __( 'Support', 'bookly-responsive-appointment-booking-tool' ),
            // Common
            'apply' => __( 'Apply', 'bookly-responsive-appointment-booking-tool' ),
            'cancel' => __( 'Cancel', 'bookly-responsive-appointment-booking-tool' ),
            'close' => __( 'Close', 'bookly-responsive-appointment-booking-tool' ),
            'later' => __( 'I\'ll do it later', 'bookly-responsive-appointment-booking-tool' ),
            'next' => __( 'Next', 'bookly-responsive-appointment-booking-tool' ),
            'no_results' => __( 'No records.', 'bookly-responsive-appointment-booking-tool' ),
            'request_failed' => __( 'Request failed. Please try again later.', 'bookly-responsive-appointment-booking-tool' ),
            'search' => __( 'Search', 'bookly-responsive-appointment-booking-tool' ),
            'settings_saved' => __( 'Settings saved.', 'bookly-responsive-appointment-booking-tool' ),
            // Sign in
            'accept_tos' => sprintf( __( 'I accept <a href="%1$s" target="_blank">Service Terms</a> and <a href="%2$s" target="_blank">Privacy Policy</a>', 'bookly-responsive-appointment-booking-tool' ), 'https://www.booking-wp-plugin.com/terms/', 'https://www.booking-wp-plugin.com/privacy/' ),
            'code_incomplete' => __( 'Enter the code from the email.', 'bookly-responsive-appointment-booking-tool' ),
            'code_link' => __( 'Use a sign-in code instead', 'bookly-responsive-appointment-booking-tool' ),
            'code_note' => __( 'We\'ll send a code to your email to sign you in.', 'bookly-responsive-appointment-booking-tool' ),
            'code_sent_after' => isset ( $code_sent[1] ) ? $code_sent[1] : '',
            'code_sent_before' => $code_sent[0],
            'country' => __( 'Country', 'bookly-responsive-appointment-booking-tool' ),
            'email' => __( 'Email', 'bookly-responsive-appointment-booking-tool' ),
            'enter_code' => __( 'Enter code from email', 'bookly-responsive-appointment-booking-tool' ),
            'forgot_password' => __( 'Forgot password', 'bookly-responsive-appointment-booking-tool' ),
            'forgot_password_link' => __( 'Forgot password?', 'bookly-responsive-appointment-booking-tool' ),
            'new_password' => __( 'New password', 'bookly-responsive-appointment-booking-tool' ),
            'password' => __( 'Password', 'bookly-responsive-appointment-booking-tool' ),
            'password_link' => __( 'Use a password instead', 'bookly-responsive-appointment-booking-tool' ),
            'passwords_not_match' => __( 'Passwords don\'t match', 'bookly-responsive-appointment-booking-tool' ),
            'register_note' => __( 'We\'ll set everything up and email your password.', 'bookly-responsive-appointment-booking-tool' ),
            'repeat_new_password' => __( 'Repeat new password', 'bookly-responsive-appointment-booking-tool' ),
            'resend_code' => __( 'Resend code', 'bookly-responsive-appointment-booking-tool' ),
            'send_code' => __( 'Send code', 'bookly-responsive-appointment-booking-tool' ),
            'sign_in_code' => __( 'Sign-in code', 'bookly-responsive-appointment-booking-tool' ),
            'select_country' => __( 'Select country', 'bookly-responsive-appointment-booking-tool' ),
            // Email confirmation
            'check_spam' => __( 'Check your spam folder.', 'bookly-responsive-appointment-booking-tool' ),
            'code_resent' => __( 'An email containing the confirmation code has been sent to your email address.', 'bookly-responsive-appointment-booking-tool' ),
            'confirm' => __( 'Confirm', 'bookly-responsive-appointment-booking-tool' ),
            'confirm_email_sent' => __( 'An email containing the confirmation code has been sent to your email address.', 'bookly-responsive-appointment-booking-tool' ) . ' ' . __( 'To complete registration, please enter the confirmation code below.', 'bookly-responsive-appointment-booking-tool' ),
            'confirmation_code' => __( 'Confirmation code', 'bookly-responsive-appointment-booking-tool' ),
            'no_email' => __( 'Didn\'t receive the email?', 'bookly-responsive-appointment-booking-tool' ),
            'resend_email' => __( 'Resend the email', 'bookly-responsive-appointment-booking-tool' ),
            // Country
            'set_country' => __( 'Set country', 'bookly-responsive-appointment-booking-tool' ),
            'setup_country' => __( 'Set up your country', 'bookly-responsive-appointment-booking-tool' ),
            'setup_country_info' => __( 'Please take a minute to set up your country. This will help us provide you with appropriate payment methods when replenishing your account.', 'bookly-responsive-appointment-booking-tool' ),
            // Recharge
            'add_promo' => __( 'Add Promo Code', 'bookly-responsive-appointment-booking-tool' ),
            'amount' => __( 'Amount', 'bookly-responsive-appointment-booking-tool' ),
            'auto_recharge_cancelled' => __( 'Auto-Recharge has been cancelled', 'bookly-responsive-appointment-booking-tool' ),
            'auto_hint' => sprintf( __( 'Your account will be topped up with the selected amount <b>now</b> if your balance is less than %1$s, and <b>automatically later</b> when the balance falls below %1$s.', 'bookly-responsive-appointment-booking-tool' ), $threshold ),
            'auto_recharge_enabled' => __( 'Auto-Recharge has been enabled', 'bookly-responsive-appointment-booking-tool' ),
            'auto_recharge_renewed' => __( 'Auto-Recharge has been renewed', 'bookly-responsive-appointment-booking-tool' ),
            'back' => __( 'Back', 'bookly-responsive-appointment-booking-tool' ),
            'best_offer' => __( 'best offer', 'bookly-responsive-appointment-booking-tool' ),
            'bonus' => __( 'bonus', 'bookly-responsive-appointment-booking-tool' ),
            // The amount is known only once it is chosen, its placeholder is left for the dialog
            'consent' => sprintf( __( 'I authorize Bookly to automatically charge my payment method %1$s when my balance drops below %2$s.', 'bookly-responsive-appointment-booking-tool' ), '%s', $threshold ),
            'consent_required' => __( 'Please confirm the authorization to continue.', 'bookly-responsive-appointment-booking-tool' ),
            'credit_card' => __( 'Credit card', 'bookly-responsive-appointment-booking-tool' ),
            'current' => __( 'Current', 'bookly-responsive-appointment-booking-tool' ),
            'disable' => __( 'Disable', 'bookly-responsive-appointment-booking-tool' ),
            'disable_auto_recharge' => __( 'Disable Auto-Recharge', 'bookly-responsive-appointment-booking-tool' ),
            'disable_auto_recharge_confirm' => __( 'Are you sure you want to disable Auto-Recharge?', 'bookly-responsive-appointment-booking-tool' ),
            'first_bonus' => __( 'first top-up bonus', 'bookly-responsive-appointment-booking-tool' ),
            'promo_code' => __( 'promo code', 'bookly-responsive-appointment-booking-tool' ),
            'to_balance' => __( 'Added to your balance', 'bookly-responsive-appointment-booking-tool' ),
            'enter_promo' =>__( 'Enter promo code', 'bookly-responsive-appointment-booking-tool' ),
            'manual_hint' => __( 'Please select an amount and recharge your account', 'bookly-responsive-appointment-booking-tool' ),
            'one_time_payment' => __( 'One-time payment', 'bookly-responsive-appointment-booking-tool' ),
            'payment_accepted' => __( 'Your payment has been accepted for processing', 'bookly-responsive-appointment-booking-tool' ),
            'payment_cancelled' => __( 'Your payment has been cancelled', 'bookly-responsive-appointment-booking-tool' ),
            'payment_method' => __( 'Payment method', 'bookly-responsive-appointment-booking-tool' ),
            'paypal_hint' => __( 'Pay with your PayPal account', 'bookly-responsive-appointment-booking-tool' ),
            'promo_applied' => __( 'Promo code applied!', 'bookly-responsive-appointment-booking-tool' ),
            'promo_invalid' => __( 'This promo code is not valid', 'bookly-responsive-appointment-booking-tool' ),
            'recharge_title' => __( 'Account recharge', 'bookly-responsive-appointment-booking-tool' ),
            'remove' => __( 'Remove', 'bookly-responsive-appointment-booking-tool' ),
            'thank_you' => __( 'Thank you', 'bookly-responsive-appointment-booking-tool' ),
            'users_choice' => __( 'users\' choice', 'bookly-responsive-appointment-booking-tool' ),
        );
    }
}
