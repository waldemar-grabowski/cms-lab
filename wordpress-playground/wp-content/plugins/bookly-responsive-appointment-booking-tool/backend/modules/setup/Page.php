<?php
namespace Bookly\Backend\Modules\Setup;

use Bookly\Lib;
use Bookly\Lib\Utils\DateTime;
use Bookly\Backend\Modules\Calendar\Page as CalendarPage;

class Page extends Lib\Base\Component
{
    /**
     * Render page.
     */
    public static function render()
    {
        $variant = self::resolveVariant();
        Lib\UsageStats::track( 'setup.opened', array( 'variant' => $variant ) );

        $durations = array();
        for ( $j = 15; $j <= 60; $j += 15 ) {
            $durations[] = array( 'title' => DateTime::secondsToInterval( $j * 60 ), 'value' => $j * 60 );
        }
        for ( $j = 60 * 2; $j <= 60 * 12; $j += 60 ) {
            $durations[] = array( 'title' => DateTime::secondsToInterval( $j * 60 ), 'value' => $j * 60 );
        }
        for ( $j = 60 * 24; $j <= 60 * 24 * 7; $j += 60 * 24 ) {
            $durations[] = array( 'title' => DateTime::secondsToInterval( $j * 60 ), 'value' => $j * 60 );
        }

        if ( $variant === 'v2' ) {
            self::renderV2( $durations );

            return;
        }

        $tel_input_enabled = get_option( 'bookly_cst_phone_default_country' ) != 'disabled';

        self::enqueueStyles( array(
            'frontend' => $tel_input_enabled
                ? array( 'css/intlTelInput.css' )
                : array(),
            'backend' => array( 'css/fontawesome-all.min.css' => array( 'bookly-backend-globals' ) ),
        ) );

        self::enqueueScripts( array(
            'frontend' => $tel_input_enabled
                ? array( 'js/intlTelInput.min.js' => array( 'jquery' ) )
                : array(),
            'bookly' => array( 'backend/components/cloud/account/resources/js/select-country.js' => array( 'bookly-backend-globals' ) ),
            'module' => array( 'js/setup.js' => array( 'bookly-backend-globals' ) ),
        ) );

        $timeslot_options = array();
        foreach ( Lib\Config::getTimeSlotLengthOptions() as $duration ) {
            $timeslot_options[] = array( $duration, Lib\Utils\DateTime::secondsToInterval( $duration * MINUTE_IN_SECONDS ) );
        }

        wp_localize_script( 'bookly-setup.js', 'BooklyL10nSetupForm', Proxy\Pro::prepareOptions( array(
            'step' => get_option( 'bookly_setup_step', 1 ),
            'intlTelInput' => array(
                'enabled' => $tel_input_enabled,
                'country' => get_option( 'bookly_cst_phone_default_country' ),
            ),
            'finish_url' => add_query_arg( array( 'page' => CalendarPage::pageSlug() ), admin_url( 'admin.php' ) ),
            'durations' => $durations,
            'timeslot_options' => $timeslot_options,
            'timeslot_length' => (int) get_option( 'bookly_gen_time_slot_length', 15 ),
            'currencies' => Lib\Utils\Price::getCurrencies(),
            'currency' => Lib\Config::getCurrency(),
            'moment_format_date' => DateTime::convertFormat( 'date', DateTime::FORMAT_MOMENT_JS ),
            'moment_format_time' => DateTime::convertFormat( 'time', DateTime::FORMAT_MOMENT_JS ),
            'color' => get_option( 'bookly_app_color', '#f4662f' ),
            'cloud_logged_in' => Lib\Cloud\API::getInstance()->account->loadProfile() ? esc_html( Lib\Cloud\API::getInstance()->account->getUserName() ) : false,
            'l10n' => array(
                'staff_name' => __( 'Full name', 'bookly-responsive-appointment-booking-tool' ),
                'staff_email' => __( 'Email', 'bookly-responsive-appointment-booking-tool' ),
                'staff_phone' => __( 'Phone', 'bookly-responsive-appointment-booking-tool' ),
                'service_title' => __( 'Title', 'bookly-responsive-appointment-booking-tool' ),
                'service_duration' => __( 'Duration', 'bookly-responsive-appointment-booking-tool' ),
                'new_service' => __( 'New service', 'bookly-responsive-appointment-booking-tool' ) . '…',
                'required' => __( 'Required', 'bookly-responsive-appointment-booking-tool' ),
                'continue' => __( 'Continue', 'bookly-responsive-appointment-booking-tool' ),
                'finish' => __( 'Finish', 'bookly-responsive-appointment-booking-tool' ),
                'skip' => __( 'Skip', 'bookly-responsive-appointment-booking-tool' ),
                'back' => __( 'Back', 'bookly-responsive-appointment-booking-tool' ),
                'cancel' => __( 'Cancel', 'bookly-responsive-appointment-booking-tool' ),
                'delete' => __( 'Delete', 'bookly-responsive-appointment-booking-tool' ) . '…',
                'sms_text' => __( 'Reduce no-shows, keep your staff and customers informed, and send timely reminders - use SMS Notifications. Send a free test message!', 'bookly-responsive-appointment-booking-tool' ),
                'sms_success_text' => sprintf( '%s<br/>%s', __( 'Your message has been sent successfully. You can manage your messages in the \'SMS Notifications\' section.', 'bookly-responsive-appointment-booking-tool' ), __( 'Give it a try!', 'bookly-responsive-appointment-booking-tool' ) ),
                'send_sms' => __( 'Send SMS', 'bookly-responsive-appointment-booking-tool' ),
                'welcome_title' => __( 'Welcome to Bookly!', 'bookly-responsive-appointment-booking-tool' ),
                'welcome_text' => sprintf( '%s<br/><br/>%s<br/><br/>%s',
                    __( 'As the ultimate appointment booking plugin for online scheduling, Bookly is designed to help you effortlessly manage your booking calendar, services, and client base.', 'bookly-responsive-appointment-booking-tool' ),
                    __( 'This introduction will guide you through the essential configuration steps to get you started quickly.', 'bookly-responsive-appointment-booking-tool' ),
                    sprintf( __( 'You can optionally skip this wizard and refer to %s or watch our %s to learn the basics and get the most out of Bookly.', 'bookly-responsive-appointment-booking-tool' ), sprintf( '<a href="%s" target="_blank">%s</a>', 'https://hub.bookly.pro/go/bookly-help-center', __( 'Bookly Help Center', 'bookly-responsive-appointment-booking-tool' ) ), sprintf( '<a href="%s" target="_blank">%s</a>', 'https://hub.bookly.pro/go/bookly-youtube', __( 'Video Tutorials', 'bookly-responsive-appointment-booking-tool' ) ) )
                ),
                'business_hours' => __( 'Set your company business hours. This schedule will serve as a template for all new staff members.', 'bookly-responsive-appointment-booking-tool' ),
                'to' => __( 'to', 'bookly-responsive-appointment-booking-tool' ),
                'time_interval' => __( 'Select a time interval to be used as a step when creating all time slots in the system.', 'bookly-responsive-appointment-booking-tool' ),
                'currency' => __( 'Select the currency for the prices of your services.', 'bookly-responsive-appointment-booking-tool' ),
                'staff_text' => __( 'In Bookly, \'staff\' refers to any employee or resource that provides services to your clients, such as a consultant, therapist, or any other service provider. Adding staff members allows you to manage their schedules, assign services to them, and track their appointments.', 'bookly-responsive-appointment-booking-tool' ),
                'services_text' => __( 'In Bookly, a \'service\' refers to the various offerings or activities your business provides to clients, such as consultations, treatments, classes, or other services. By adding services, you can manage their duration, cost, and assign them to specific employees.', 'bookly-responsive-appointment-booking-tool' ),
                'cloud_text' => sprintf( '%s<br/><br/>%s<br/><br/>%s<br/><br/>%s',
                    __( 'Bookly Cloud is an integral part of the Bookly booking system, offering a range of additional products and features for efficient appointment management and automation.', 'bookly-responsive-appointment-booking-tool' ),
                    __( 'Features like SMS Notifications, Zapier integration, Stripe and Square Payments, Gift Cards, Voice and WhatsApp Notifications are designed to enhance your operations and boost your online business.', 'bookly-responsive-appointment-booking-tool' ),
                    sprintf( __( 'Discover the full range of <a href="%s" target="_blank">powerful tools here</a>.', 'bookly-responsive-appointment-booking-tool' ), 'https://hub.bookly.pro/go/bookly-cloud-overview' ),
                    __( 'Sign up today and enjoy a welcome bonus!', 'bookly-responsive-appointment-booking-tool' )
                ),
                'create_account' => __( 'Create an account', 'bookly-responsive-appointment-booking-tool' ),
                'login' => __( 'Already registered', 'bookly-responsive-appointment-booking-tool' ),
                'email' => __( 'Email', 'bookly-responsive-appointment-booking-tool' ),
                'password' => __( 'Password', 'bookly-responsive-appointment-booking-tool' ),
                'confirm_password' => __( 'Confirm password', 'bookly-responsive-appointment-booking-tool' ),
                'country' => __( 'Country', 'bookly-responsive-appointment-booking-tool' ),
                'cloud_tos' => sprintf( __( 'I accept <a href="%1$s" target="_blank">Service Terms</a> and <a href="%2$s" target="_blank">Privacy Policy</a>', 'bookly-responsive-appointment-booking-tool' ), 'https://www.booking-wp-plugin.com/terms/', 'https://www.booking-wp-plugin.com/privacy/' ),
                'forgot_password' => __( 'Forgot password', 'bookly-responsive-appointment-booking-tool' ),
                'forgot_actions' => array(
                    __( 'Send recovery code', 'bookly-responsive-appointment-booking-tool' ),
                    __( 'Verify recovery code', 'bookly-responsive-appointment-booking-tool' ),
                    __( 'Set new password', 'bookly-responsive-appointment-booking-tool' ),
                ),
                'recovery_code' => __( 'Recovery code', 'bookly-responsive-appointment-booking-tool' ),
                'new_password' => __( 'New password', 'bookly-responsive-appointment-booking-tool' ),
                'logged_in' => __( 'You are currently logged into Bookly Cloud with the account', 'bookly-responsive-appointment-booking-tool' ),
                'steps' => array(
                    __( 'Greetings', 'bookly-responsive-appointment-booking-tool' ),
                    __( 'General', 'bookly-responsive-appointment-booking-tool' ),
                    __( 'Staff', 'bookly-responsive-appointment-booking-tool' ),
                    __( 'Service', 'bookly-responsive-appointment-booking-tool' ),
                    __( 'Cloud', 'bookly-responsive-appointment-booking-tool' ),
                    __( 'SMS', 'bookly-responsive-appointment-booking-tool' ),
                    __( 'Done', 'bookly-responsive-appointment-booking-tool' ),
                ),
                'off' => __( 'OFF', 'bookly-responsive-appointment-booking-tool' ),
                'done' => array(
                    'string_1' => __( 'The initial setup is complete, and you can now continue using Bookly in the admin panel.', 'bookly-responsive-appointment-booking-tool' ),
                    'string_2' => __( 'You can create staff members and services, add appointments to the calendar, and manage them directly from the backend.', 'bookly-responsive-appointment-booking-tool' ),
                    'string_3' => __( 'To start receiving appointments via the front-end booking form, follow these steps:', 'bookly-responsive-appointment-booking-tool' ),
                    'string_4' => __( 'Create a new page in WordPress.', 'bookly-responsive-appointment-booking-tool' ),
                    'string_5' => sprintf( __( 'Insert the following shortcode: %s', 'bookly-responsive-appointment-booking-tool' ), '<input type="text" class="form-control d-inline" value="[bookly-form]" readonly style="max-width: 124px;">' ),
                    'string_6' => __( 'Save the page and visit it to see your booking form in action.', 'bookly-responsive-appointment-booking-tool' ),
                    'string_7' => sprintf( __( 'Have questions? Visit our %s or reach out to our %s.', 'bookly-responsive-appointment-booking-tool' ), sprintf( '<a href="https://support.booking-wp-plugin.com/hc/en-us/articles/212800185">%s</a>', __( 'Help center', 'bookly-responsive-appointment-booking-tool' ) ), sprintf( '<a href="https://hub.bookly.pro/go/bookly-request-support">%s</a>', __( 'Support Team', 'bookly-responsive-appointment-booking-tool' ) ) ),
                ),
            ),
        ) ) );

        self::renderTemplate( 'index', compact( 'variant' ) );
    }

    /**
     * Billing data for the Done screen top-up block (logged-in accounts only):
     * balance, auto-recharge state and amounts with bonuses, first-top-up promo amount.
     *
     * @return array|false
     */
    public static function getCloudBilling()
    {
        $account = Lib\Cloud\API::getInstance()->account;
        if ( ! $account->loadProfile() ) {
            return false;
        }
        $recharge = $account->getRechargeData();
        $promotions = get_option( 'bookly_cloud_promotions' );

        return array(
            'balance' => (float) $account->getBalance(),
            // Already on: the wizard has nothing left to offer, it only sets Auto-Recharge up
            'auto_recharge_enabled' => (bool) $account->autoRechargeEnabled(),
            'amounts' => isset( $recharge['amounts']['auto'] ) ? array_values( $recharge['amounts']['auto'] ) : array(),
            'first_recharge_bonus' => is_array( $promotions ) && isset( $promotions['first_recharge'] ) ? (float) $promotions['first_recharge']['amount'] : 0,
            // Tiered first top-up bonuses: "recharge id" => "bonus" map; empty = flat bonus only
            'first_recharge_tiers' => is_array( $promotions ) && isset( $promotions['first_recharge']['recharges'] ) ? $promotions['first_recharge']['recharges'] : array(),
        );
    }

    /**
     * Registration promotion (welcome bonus) from the Cloud info cache.
     *
     * @return array|false
     */
    protected static function getRegistrationPromo()
    {
        $promotions = get_option( 'bookly_cloud_promotions' );

        return is_array( $promotions ) && isset( $promotions['registration'] ) ? $promotions['registration'] : false;
    }

    /**
     * Shipped wizard variants, in chronological order.
     * The last one is the fallback/default when no variant is assigned
     * (no Cloud config received yet, unknown id from a newer config, etc.).
     *
     * @return array
     */
    public static function getVariants()
    {
        return array( 'legacy', 'v2' );
    }

    /**
     * Resolve which wizard variant to show and make the choice sticky.
     *
     * @return string
     */
    public static function resolveVariant()
    {
        $variants = self::getVariants();

        $sticky = get_option( 'bookly_setup_wizard' );
        if ( is_array( $sticky ) && isset( $sticky['variant'] ) && in_array( $sticky['variant'], $variants, true ) ) {
            return $sticky['variant'];
        }

        $variant = end( $variants );
        $config = array();
        $rollout = get_option( 'bookly_setup_wizard_config' );
        if ( is_array( $rollout ) ) {
            if ( isset( $rollout['config'] ) && is_array( $rollout['config'] ) ) {
                $config = $rollout['config'];
            }
            if ( isset( $rollout['variants'] ) && is_array( $rollout['variants'] ) ) {
                foreach ( $rollout['variants'] as $id ) {
                    if ( in_array( $id, $variants, true ) ) {
                        $variant = $id;
                        break;
                    }
                }
            }
        }

        update_option( 'bookly_setup_wizard', array(
            'variant' => $variant,
            'config' => $config,
            'assigned_at' => current_time( 'mysql' ),
        ) );

        return $variant;
    }

    /**
     * Wizard feature config snapshotted at variant assignment (empty array before it).
     *
     * @return array
     */
    protected static function getWizardConfig()
    {
        $sticky = get_option( 'bookly_setup_wizard' );

        return is_array( $sticky ) && isset( $sticky['config'] ) && is_array( $sticky['config'] ) ? $sticky['config'] : array();
    }

    /**
     * Render setup wizard v2.
     *
     * @param array $durations
     */
    protected static function renderV2( $durations )
    {
        $variant = 'v2';

        // Short onboarding list: rare durations are configured later on the Services page.
        $durations = array();
        foreach ( array( 900, 1800, 2700, 3600, 5400, 7200 ) as $seconds ) {
            $durations[] = array( 'title' => DateTime::secondsToInterval( $seconds ), 'value' => $seconds );
        }

        self::enqueueScripts( array(
            // intlTelInput is used as the country/dial-code data source (booklyIntlTelInput.getCountryData())
            'frontend' => array( 'js/intlTelInput.min.js' => array() ),
            'module' => array( 'js/setup-v2.js' => array( 'bookly-backend-globals', 'bookly-intlTelInput.min.js' ) ),
        ) );

        // Instant phone country default from the site locale (refined client-side via geo lookup)
        $locale_country = preg_match( '/_([A-Z]{2})$/', get_locale(), $matches ) ? strtolower( $matches[1] ) : '';

        // Pre-paint: dark background + zeroed chrome before the bundle's injected CSS
        // arrives, so there is no light flash while JS loads. Printed in-body on
        // purpose — the page callback runs after <head> is closed.
        // Immersive onboarding: no admin notices of any kind over the wizard —
        // relocated/plain div.notice (incl. third-party), legacy .wrap alerts,
        // WP core update nag. The wizard container itself carries none of these classes.
        echo '<style>body{background:#0a0b14}#wpcontent{padding-left:0}#wpbody-content{padding-bottom:0}#wpfooter{display:none}'
            . '#wpbody-content div.notice,#wpbody-content div.updated,#wpbody-content div.error,#wpbody-content .update-nag,#wpbody-content>.wrap,#wpcontent>.wrap{display:none!important}'
            . '</style>';

        wp_localize_script( 'bookly-setup-v2.js', 'BooklyL10nSetupV2Form', array(
            'finish_url' => add_query_arg( array( 'page' => CalendarPage::pageSlug() ), admin_url( 'admin.php' ) ),
            'durations' => $durations,
            // Free plan limits (null = unlimited). Services: mirrors Services\Ajax::createService()
            // gate (max 5, legacy-lite exempt). Staff: UI-level limit, same as the old wizard.
            'limits' => array(
                'services' => Lib\Config::proActive() || get_option( 'bookly_updated_from_legacy_version' ) == 'lite' ? null : 5,
                'staff' => Lib\Config::proActive() ? null : 1,
            ),
            'upgrade_url' => 'https://hub.bookly.pro/go/bookly-addon-pro',
            'locale_country' => $locale_country,
            'cloud_logged_in' => Lib\Cloud\API::getInstance()->account->loadProfile() ? esc_html( Lib\Cloud\API::getInstance()->account->getUserName() ) : false,
            // Welcome bonus banner: registration promotion from the Cloud info cache
            'promo_registration' => self::getRegistrationPromo(),
            'l10n' => array(
                'back' => __( 'Back', 'bookly-responsive-appointment-booking-tool' ),
                'continue' => __( 'Continue', 'bookly-responsive-appointment-booking-tool' ),
                'save_error' => __( 'Something went wrong. Please try again.', 'bookly-responsive-appointment-booking-tool' ),
                'skip_for_now' => __( 'Skip for now', 'bookly-responsive-appointment-booking-tool' ),
                'add_another' => __( 'Add another', 'bookly-responsive-appointment-booking-tool' ),
                'remove' => __( 'Remove', 'bookly-responsive-appointment-booking-tool' ),
                'welcome' => array(
                    'title' => __( 'Welcome to Bookly', 'bookly-responsive-appointment-booking-tool' ),
                    'hint' => __( 'A quick, basic setup — you can fine-tune everything later.', 'bookly-responsive-appointment-booking-tool' ),
                    'cta' => __( 'Get started', 'bookly-responsive-appointment-booking-tool' ),
                ),
                'company' => array(
                    'eyebrow' => __( 'Your company', 'bookly-responsive-appointment-booking-tool' ),
                    'title' => __( 'What\'s your company called?', 'bookly-responsive-appointment-booking-tool' ),
                    'placeholder' => __( 'Company name', 'bookly-responsive-appointment-booking-tool' ),
                    'no_company' => __( 'I don\'t have a company', 'bookly-responsive-appointment-booking-tool' ),
                ),
                'services' => array(
                    'eyebrow' => __( 'Your services', 'bookly-responsive-appointment-booking-tool' ),
                    'title' => __( 'What do you offer?', 'bookly-responsive-appointment-booking-tool' ),
                    'placeholder' => __( 'Service name', 'bookly-responsive-appointment-booking-tool' ),
                    'duration' => __( 'Duration', 'bookly-responsive-appointment-booking-tool' ),
                    'upsell' => __( 'Need more services? Upgrade to Bookly Pro', 'bookly-responsive-appointment-booking-tool' ),
                ),
                'staff' => array(
                    'eyebrow' => __( 'Your team', 'bookly-responsive-appointment-booking-tool' ),
                    'title' => __( 'Who provides the service?', 'bookly-responsive-appointment-booking-tool' ),
                    'placeholder' => __( 'Full name', 'bookly-responsive-appointment-booking-tool' ),
                    'upsell' => __( 'Need more staff members? Upgrade to Bookly Pro', 'bookly-responsive-appointment-booking-tool' ),
                    'contact_prompt' => __( 'Add their contact details so Bookly can notify them about new bookings.', 'bookly-responsive-appointment-booking-tool' ),
                    'email_placeholder' => __( 'Email', 'bookly-responsive-appointment-booking-tool' ),
                    'phone_placeholder' => __( 'Phone number', 'bookly-responsive-appointment-booking-tool' ),
                    'cc_label' => __( 'Country code', 'bookly-responsive-appointment-booking-tool' ),
                    'cc_search' => __( 'Search', 'bookly-responsive-appointment-booking-tool' ) . '…',
                ),
                'reminders' => array(
                    'eyebrow' => 'Bookly Cloud',
                    'title' => __( 'Send booking confirmations and reminders', 'bookly-responsive-appointment-booking-tool' ),
                    'lead' => __( 'Cut no-shows and make every client feel looked after — while your staff stay in the loop, too.', 'bookly-responsive-appointment-booking-tool' ),
                    'channel_sms' => __( 'Text messages', 'bookly-responsive-appointment-booking-tool' ),
                    'channel_whatsapp' => __( 'WhatsApp notifications', 'bookly-responsive-appointment-booking-tool' ),
                    'channel_voice' => __( 'Voice notifications', 'bookly-responsive-appointment-booking-tool' ),
                    'gate_lead' => sprintf( __( 'All you need is to connect a %s account — sign in, or create one in seconds.', 'bookly-responsive-appointment-booking-tool' ), '<b>Bookly Cloud</b>' ),
                    'signin' => __( 'Sign in', 'bookly-responsive-appointment-booking-tool' ),
                    'create' => __( 'Create account', 'bookly-responsive-appointment-booking-tool' ),
                    'note_signin' => __( 'Bought a Bookly product? You already have an account — the password\'s in your purchase email.', 'bookly-responsive-appointment-booking-tool' ),
                    'note_create' => __( 'No account yet? Enter your email — we\'ll set everything up and email your password.', 'bookly-responsive-appointment-booking-tool' ),
                    'email' => __( 'Email', 'bookly-responsive-appointment-booking-tool' ),
                    'password' => __( 'Password', 'bookly-responsive-appointment-booking-tool' ),
                    'confirm_password' => __( 'Confirm password', 'bookly-responsive-appointment-booking-tool' ),
                    'tos' => sprintf( __( 'I accept <a href="%1$s" target="_blank">Service Terms</a> and <a href="%2$s" target="_blank">Privacy Policy</a>', 'bookly-responsive-appointment-booking-tool' ), 'https://www.booking-wp-plugin.com/terms/', 'https://www.booking-wp-plugin.com/privacy/' ),
                    'btn_create' => __( 'Create free account', 'bookly-responsive-appointment-booking-tool' ),
                    'signedin' => __( 'You\'re in! Your Bookly Cloud account is connected.', 'bookly-responsive-appointment-booking-tool' ),
                    'bonus_title' => __( 'Welcome bonus', 'bookly-responsive-appointment-booking-tool' ),
                    'bonus_text' => __( 'Connect now to claim %amount% bonus as a new customer.', 'bookly-responsive-appointment-booking-tool' ),
                    'code_link' => __( 'Can\'t find your password? Use a sign-in code instead', 'bookly-responsive-appointment-booking-tool' ),
                    'password_link' => __( 'Use a password instead', 'bookly-responsive-appointment-booking-tool' ),
                    'code_note' => __( 'We\'ll send a code to your email to sign you in.', 'bookly-responsive-appointment-booking-tool' ),
                    'code_sent_note' => __( 'We emailed a code to %email%. Enter it to sign in.', 'bookly-responsive-appointment-booking-tool' ),
                    'send_code' => __( 'Send code', 'bookly-responsive-appointment-booking-tool' ),
                    'resend_code' => __( 'Resend code', 'bookly-responsive-appointment-booking-tool' ),
                    'code_incomplete' => __( 'Enter the code from the email.', 'bookly-responsive-appointment-booking-tool' ),
                    'error_generic' => __( 'Something went wrong. Please try again.', 'bookly-responsive-appointment-booking-tool' ),
                ),
                // Телефонное превью: шаблоны с %company%/%staff%/%service%/%client% + сэмплы-фолбэки,
                // когда на предыдущих шагах ничего не введено.
                'preview' => array(
                    'sample_company' => __( 'Bright Smile Studio', 'bookly-responsive-appointment-booking-tool' ),
                    'sample_staff' => __( 'Jane Doe', 'bookly-responsive-appointment-booking-tool' ),
                    'sample_service' => __( 'Teeth Whitening', 'bookly-responsive-appointment-booking-tool' ),
                    'sample_client' => __( 'Nick', 'bookly-responsive-appointment-booking-tool' ),
                    'client_confirm' => __( 'Hi %client%! 👋 Your %service% appointment with %staff% at %company% is confirmed for Jun 24 at 2:00 PM.', 'bookly-responsive-appointment-booking-tool' ),
                    'client_reminder' => __( 'Reminder: your %service% with %staff% at %company% is today at 2:00 PM. See you soon! 💁', 'bookly-responsive-appointment-booking-tool' ),
                    'client_reminder_stamp' => __( 'Today 9:00 AM', 'bookly-responsive-appointment-booking-tool' ),
                    'sms_label' => __( 'Text Message', 'bookly-responsive-appointment-booking-tool' ),
                    'stamp_now' => __( 'Today 2:02 PM', 'bookly-responsive-appointment-booking-tool' ),
                    'stamp_past' => __( 'Last week', 'bookly-responsive-appointment-booking-tool' ),
                    'staff_confirm' => __( 'Hi %staff%! 🔔 New booking: %service% with %client%, Jun 24 at 2:00 PM. Open Bookly to view.', 'bookly-responsive-appointment-booking-tool' ),
                    'staff_reminder' => __( 'Reminder: %service% with %client% is today at 2:00 PM. Open Bookly for details.', 'bookly-responsive-appointment-booking-tool' ),
                    'staff_reminder_stamp' => __( 'Today 8:30 AM', 'bookly-responsive-appointment-booking-tool' ),
                ),
                'done' => array(
                    'title' => __( 'You\'re all set', 'bookly-responsive-appointment-booking-tool' ),
                    'hint' => __( 'Copy this shortcode and paste it on any page or post. That\'s where your customers will book.', 'bookly-responsive-appointment-booking-tool' ),
                    'shortcode' => '[bookly-form]',
                    'copy' => __( 'Copy', 'bookly-responsive-appointment-booking-tool' ),
                    'video_eyebrow' => __( 'Want a deeper dive?', 'bookly-responsive-appointment-booking-tool' ),
                    'video_label' => __( 'Full setup guide · 3 min', 'bookly-responsive-appointment-booking-tool' ),
                    'go_calendar' => __( 'Go to calendar', 'bookly-responsive-appointment-booking-tool' ),
                    'sample_note' => __( 'We\'ve added sample data so you can look around — everything is marked “(sample)” and easy to remove.', 'bookly-responsive-appointment-booking-tool' ),
                    'balance_label' => __( 'Your Bookly Cloud balance', 'bookly-responsive-appointment-booking-tool' ),
                    'bonus_credited' => __( 'Welcome bonus credited', 'bookly-responsive-appointment-booking-tool' ),
                    'topup_pitch' => __( 'Bookly Cloud products run on your balance.', 'bookly-responsive-appointment-booking-tool' ),
                    'topup_pitch_first' => __( 'Top up now and win a bonus on your first top-up.', 'bookly-responsive-appointment-booking-tool' ),
                    'topup_pitch_nobonus' => __( 'Top up your balance to start using them right away.', 'bookly-responsive-appointment-booking-tool' ),
                    'topup_pitch_more' => __( 'The more you add, the more SMS you can send and products you can try.', 'bookly-responsive-appointment-booking-tool' ),
                    'becomes' => __( 'Your balance becomes %total% — %amount% top-up + %bonus% bonus.', 'bookly-responsive-appointment-booking-tool' ),
                    'topup_btn' => __( 'Top up %amount%', 'bookly-responsive-appointment-booking-tool' ),
                    'consent' => __( 'I authorize Bookly to automatically charge my payment method %amount% when my balance drops below %threshold%.', 'bookly-responsive-appointment-booking-tool' ),
                    'bonus_tag' => __( '+%amount% bonus', 'bookly-responsive-appointment-booking-tool' ),
                    'pay_error' => __( 'Card payment has failed. Please try again later.', 'bookly-responsive-appointment-booking-tool' ),
                    'auto_enabled' => __( 'Auto-Recharge has been enabled', 'bookly-responsive-appointment-booking-tool' ),
                    'payment_cancelled' => __( 'Your payment has been cancelled', 'bookly-responsive-appointment-booking-tool' ),
                    'explore_title' => __( 'Explore everything Bookly Cloud offers to grow your business', 'bookly-responsive-appointment-booking-tool' ),
                    'features' => array(
                        __( 'SMS notifications', 'bookly-responsive-appointment-booking-tool' ),
                        __( 'WhatsApp notifications', 'bookly-responsive-appointment-booking-tool' ),
                        __( 'Voice notifications', 'bookly-responsive-appointment-booking-tool' ),
                        __( 'Stripe payments', 'bookly-responsive-appointment-booking-tool' ),
                        'Zapier',
                        __( 'Cloud Cron', 'bookly-responsive-appointment-booking-tool' ),
                        __( 'Staff Cabinet Mobile App', 'bookly-responsive-appointment-booking-tool' ),
                    ),
                ),
            ),
            'video_id' => isset( self::getWizardConfig()['video_id'] ) ? self::getWizardConfig()['video_id'] : 'NDQYd6X7MZA',
            'code_signin' => ! isset( self::getWizardConfig()['code_signin'] ) || self::getWizardConfig()['code_signin'],
            'cloud_billing' => self::getCloudBilling(),
            'auto_recharge_threshold' => Lib\Cloud\Account::AUTO_RECHARGE_THRESHOLD,
        ) );

        self::renderTemplate( 'index', compact( 'variant' ) );
    }
}