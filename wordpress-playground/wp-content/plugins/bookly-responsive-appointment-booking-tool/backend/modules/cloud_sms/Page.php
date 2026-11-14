<?php
namespace Bookly\Backend\Modules\CloudSms;

use Bookly\Lib;
use Bookly\Backend\Modules\CloudProducts\Page as CloudProducts;
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
            Components\Cloud\LoginRequired\Page::render( __( 'SMS Notifications', 'bookly-responsive-appointment-booking-tool' ), self::pageSlug() );
        } else {
            self::enqueueStyles( array(
                'frontend' => array( 'css/intlTelInput.css' => array( 'bookly-backend-globals', ) ),
            ) );

            self::enqueueScripts( array(
                'frontend' => get_option( 'bookly_cst_phone_default_country' ) == 'disabled'
                    ? array()
                    : array( 'js/intlTelInput.min.js' => array( 'jquery' ) ),
                'bookly' => array( 'backend/components/cloud/account/resources/js/select-country.js' => array( 'bookly-backend-globals' ) ),
                'module' => array(
                    'js/notifications-list.js' => array( 'bookly-backend-globals', 'bookly-notification-dialog.js', ),
                    'js/sender-id-modal.js' => array( 'bookly-backend-globals' ),
                    'js/sms-campaigns.js' => array( 'bookly-backend-globals' ),
                    'js/sms.js' => array( 'bookly-notifications-list.js', 'bookly-select-country.js', 'bookly-sender-id-modal.js', 'bookly-sms-campaigns.js' ),
                ),
            ) );
            wp_localize_script( 'bookly-sms-campaigns.js', 'BooklyL10nSmsCampaigns', self::campaignsData() );

            // Prepare tables settings.
            $datatables = Lib\Utils\Tables::getSettings( array(
                Lib\Utils\Tables::SMS_NOTIFICATIONS,
                Lib\Utils\Tables::SMS_DETAILS,
                Lib\Utils\Tables::SMS_PRICES,
                Lib\Utils\Tables::SMS_SENDER,
                Lib\Utils\Tables::SMS_MAILING_CAMPAIGNS
            ) );

            $current_tab = self::hasParameter( 'tab' ) ? self::parameter( 'tab' ) : 'notifications';
            $campaign_preset = self::parameter( 'preset' );
            if ( ! is_string( $campaign_preset ) || ! array_key_exists( $campaign_preset, Lib\Mailing\Audience::presets() ) ) {
                $campaign_preset = null;
            }

            // Number of undelivered sms.
            $undelivered_count = Lib\Cloud\SMS::getUndeliveredSmsCount();

            wp_localize_script( 'bookly-sms.js', 'BooklyL10n',
                array(
                    'moment_format_date_time' => Lib\Utils\DateTime::convertFormat( 'date', Lib\Utils\DateTime::FORMAT_MOMENT_JS ) . ' ' . Lib\Utils\DateTime::convertFormat( 'time', Lib\Utils\DateTime::FORMAT_MOMENT_JS ),
                    'areYouSure' => __( 'Are you sure?', 'bookly-responsive-appointment-booking-tool' ),
                    'acceptable_characters' => __( 'Acceptable characters are', 'bookly-responsive-appointment-booking-tool' ) . ': a-z A-Z 0-9 . & @ - + _ ! % # [space] *',
                    'country' => $cloud->account->getCountry(),
                    'current_tab' => $current_tab,
                    'campaign_preset' => $campaign_preset,
                    'intlTelInput' => array(
                        'country' => get_option( 'bookly_cst_phone_default_country' ),
                        'enabled' => get_option( 'bookly_cst_phone_default_country' ) != 'disabled',
                    ),
                    'datePicker' => Lib\Utils\DateTime::datePickerOptions(),
                    'dateRange' => Lib\Utils\DateTime::dateRangeOptions( array( 'lastMonth' => __( 'Last month', 'bookly-responsive-appointment-booking-tool' ), ) ),
                    'sender_id' => array(
                        'sent' => __( 'Sender ID request has been sent.', 'bookly-responsive-appointment-booking-tool' ),
                        'set_default' => __( 'Sender ID has been reset to default.', 'bookly-responsive-appointment-booking-tool' ),
                        'select_country' => __( 'Select country', 'bookly-responsive-appointment-booking-tool' ),
                        'invalid' => __( 'Acceptable characters are', 'bookly-responsive-appointment-booking-tool' ) . ': a-z A-Z 0-9 . & @ - + _ ! % # [space] *',
                        'request' => __( 'Request Sender ID', 'bookly-responsive-appointment-booking-tool' ) . '…',
                        'empty_title' => __( 'No Sender ID requests yet', 'bookly-responsive-appointment-booking-tool' ),
                        'empty_text' => __( 'Request a Sender ID to show your business name as the SMS sender.', 'bookly-responsive-appointment-booking-tool' ),
                        'cancel' => __( 'Cancel request', 'bookly-responsive-appointment-booking-tool' ) . '…',
                        'cancel_sender_id' => __( 'Cancel', 'bookly-responsive-appointment-booking-tool' ) . '…',
                        'status_pending' => __( 'Pending', 'bookly-responsive-appointment-booking-tool' ),
                        'status_approved' => __( 'Approved', 'bookly-responsive-appointment-booking-tool' ),
                        'status_declined' => __( 'Declined', 'bookly-responsive-appointment-booking-tool' ),
                        'status_cancelled' => __( 'Cancelled', 'bookly-responsive-appointment-booking-tool' ),
                    ),
                    'sender_id_modal' => array(
                        'title' => __( 'Request Sender ID', 'bookly-responsive-appointment-booking-tool' ),
                        'sender_id_label' => __( 'Sender ID', 'bookly-responsive-appointment-booking-tool' ),
                        'sender_id_placeholder' => __( 'e.g.', 'bookly-responsive-appointment-booking-tool' ) . ' SpaCenter',
                        'sender_id_hint' => __( 'Can only contain letters or digits (up to 11 characters).', 'bookly-responsive-appointment-booking-tool' ) . ' ' . __( 'Acceptable characters are', 'bookly-responsive-appointment-booking-tool' ) . ': a-z A-Z 0-9 . & @ - + _ ! % # [space] *',
                        'country_label' => __( 'Destination country', 'bookly-responsive-appointment-booking-tool' ),
                        'country_placeholder' => __( 'Search and select country', 'bookly-responsive-appointment-booking-tool' ) . '…',
                        'country_search_placeholder' => __( 'Type country name', 'bookly-responsive-appointment-booking-tool' ) . '…',
                        'documents_legend' => __( 'Countries marked with this icon require extra steps.', 'bookly-responsive-appointment-booking-tool' ),
                        'price_hint' => __( 'Messages with a custom Sender ID usually cost 20–25% more than regular messages.', 'bookly-responsive-appointment-booking-tool' ),
                        'no_country_found' => __( 'No country found.', 'bookly-responsive-appointment-booking-tool' ),
                        'noreg_desc' => __( 'Once approved, your Sender ID will work in %1$s and %2$d other countries.', 'bookly-responsive-appointment-booking-tool' ),
                        'see_all_countries' => __( 'See all %d countries', 'bookly-responsive-appointment-booking-tool' ),
                        'hide_list' => __( 'Hide list', 'bookly-responsive-appointment-booking-tool' ),
                        'confirmation_code_label' => __( 'Confirmation code', 'bookly-responsive-appointment-booking-tool' ),
                        'cancel' => __( 'Cancel', 'bookly-responsive-appointment-booking-tool' ),
                        'request' => __( 'Request', 'bookly-responsive-appointment-booking-tool' ),
                        'error' => __( 'Request failed. Please try again.', 'bookly-responsive-appointment-booking-tool' ),
                    ),
                    'zeroRecords' => __( 'No records for selected period.', 'bookly-responsive-appointment-booking-tool' ),
                    'zeroRecordsAlt' => __( 'No matching records found', 'bookly-responsive-appointment-booking-tool' ),
                    'noResults' => __( 'No records.', 'bookly-responsive-appointment-booking-tool' ),
                    'emptyTable' => __( 'No data available in table', 'bookly-responsive-appointment-booking-tool' ),
                    'quick_search' => __( 'Quick search by name', 'bookly-responsive-appointment-booking-tool' ) . '…',
                    'processing' => __( 'Processing', 'bookly-responsive-appointment-booking-tool' ) . '…',
                    'state' => array( __( 'Disabled', 'bookly-responsive-appointment-booking-tool' ), __( 'Enabled', 'bookly-responsive-appointment-booking-tool' ) ),
                    'action' => array( __( 'enable', 'bookly-responsive-appointment-booking-tool' ), __( 'disable', 'bookly-responsive-appointment-booking-tool' ) ),
                    'edit' => __( 'Edit', 'bookly-responsive-appointment-booking-tool' ),
                    'run' => __( 'Start Now', 'bookly-responsive-appointment-booking-tool' ),
                    'manual' => __( 'Manual', 'bookly-responsive-appointment-booking-tool' ),
                    'settingsSaved' => __( 'Settings saved.', 'bookly-responsive-appointment-booking-tool' ),
                    'na' => __( 'N/A', 'bookly-responsive-appointment-booking-tool' ),
                    'new_notification' => __( 'New notification', 'bookly-responsive-appointment-booking-tool' ) . '…',
                    'new_campaign' => __( 'New campaign', 'bookly-responsive-appointment-booking-tool' ) . '…',
                    'empty_campaigns_title' => __( 'No campaigns yet', 'bookly-responsive-appointment-booking-tool' ),
                    'empty_campaigns_text' => __( 'Send an SMS to a group of your customers: win back people who stopped coming, thank your best clients, announce news.', 'bookly-responsive-appointment-booking-tool' ),
                    'duplicate' => __( 'Duplicate', 'bookly-responsive-appointment-booking-tool' ) . '…',
                    'stop' => __( 'Stop', 'bookly-responsive-appointment-booking-tool' ),
                    'cron_notice' => \Bookly\Backend\Components\Notices\Cron\Notice::getText(),
                    'delete' => __( 'Delete', 'bookly-responsive-appointment-booking-tool' ) . '…',
                    'enable' => __( 'Enable', 'bookly-responsive-appointment-booking-tool' ),
                    'disable' => __( 'Disable', 'bookly-responsive-appointment-booking-tool' ),
                    'rowsPerPage' => __( 'Rows per page', 'bookly-responsive-appointment-booking-tool' ),
                    'filters' => array(
                        'date' => __( 'Date', 'bookly-responsive-appointment-booking-tool' ),
                        'status' => __( 'Status', 'bookly-responsive-appointment-booking-tool' ),
                    ),
                    'resend' => __( 'Resend', 'bookly-responsive-appointment-booking-tool' ),
                    'gateway' => 'sms',
                    'default' => __( 'Default', 'bookly-responsive-appointment-booking-tool' ),
                    'datatables' => $datatables,
                )
            );
            $sms = $cloud->getProduct( Lib\Cloud\Account::PRODUCT_SMS_NOTIFICATIONS );
            self::renderTemplate( 'index', compact( 'sms', 'datatables', 'undelivered_count' ) );
        }
    }

    /**
     * Show 'SMS Notifications' submenu with counter inside Bookly main menu.
     */
    /**
     * Data of the campaign dialogs: audiences, what the custom filter chooses from, texts.
     *
     * @return array
     */
    protected static function campaignsData()
    {
        $range = array(
            array( -365, __( 'year', 'bookly-responsive-appointment-booking-tool' ) . ' ' . __( 'ago', 'bookly-responsive-appointment-booking-tool' ) ),
            array( -122, sprintf( _n( '%d month', '%d months', 4, 'bookly-responsive-appointment-booking-tool' ), 4 ) . ' ' . __( 'ago', 'bookly-responsive-appointment-booking-tool' ) ),
            array( -92, sprintf( _n( '%d month', '%d months', 3, 'bookly-responsive-appointment-booking-tool' ), 3 ) . ' ' . __( 'ago', 'bookly-responsive-appointment-booking-tool' ) ),
            array( -61, sprintf( _n( '%d month', '%d months', 2, 'bookly-responsive-appointment-booking-tool' ), 2 ) . ' ' . __( 'ago', 'bookly-responsive-appointment-booking-tool' ) ),
        );
        foreach ( array_merge( array( -28, -21 ), range( -14, -1 ) ) as $days ) {
            $range[] = array( $days, Lib\Utils\DateTime::secondsToInterval( abs( $days ) * DAY_IN_SECONDS ) . ' ' . __( 'ago', 'bookly-responsive-appointment-booking-tool' ) );
        }
        $range[] = array( 0, __( 'Any', 'bookly-responsive-appointment-booking-tool' ) );
        foreach ( array_merge( range( 1, 14 ), array( 21, 28 ) ) as $days ) {
            $range[] = array( $days, __( 'in', 'bookly-responsive-appointment-booking-tool' ) . ' ' . Lib\Utils\DateTime::secondsToInterval( $days * DAY_IN_SECONDS ) );
        }
        $range[] = array( 61, __( 'in', 'bookly-responsive-appointment-booking-tool' ) . ' ' . sprintf( _n( '%d month', '%d months', 2, 'bookly-responsive-appointment-booking-tool' ), 2 ) );
        $range[] = array( 91, __( 'in', 'bookly-responsive-appointment-booking-tool' ) . ' ' . sprintf( _n( '%d month', '%d months', 3, 'bookly-responsive-appointment-booking-tool' ), 3 ) );
        $range[] = array( 122, __( 'in', 'bookly-responsive-appointment-booking-tool' ) . ' ' . sprintf( _n( '%d month', '%d months', 4, 'bookly-responsive-appointment-booking-tool' ), 4 ) );
        $range[] = array( 365, __( 'in', 'bookly-responsive-appointment-booking-tool' ) . ' ' . __( 'year', 'bookly-responsive-appointment-booking-tool' ) );

        $staff = Lib\Config::proActive()
            ? Lib\Proxy\Pro::getStaffDataForDropDown()
            : array( array( 'name' => '', 'items' => Lib\Entities\Staff::query()->select( 'id, full_name' )->whereNot( 'visibility', 'archive' )->sortBy( 'position, id' )->fetchArray() ) );

        return array(
            'presets' => Lib\Mailing\Audience::presets(),
            'datePicker' => Lib\Utils\DateTime::datePickerOptions(),
            'timeFormat' => Lib\Utils\DateTime::convertFormat( 'time', Lib\Utils\DateTime::FORMAT_MOMENT_JS ),
            'services' => Lib\Utils\Common::getServiceDataForDropDown( 's.type = "simple"' ),
            'staff' => $staff,
            'range' => $range,
            'company' => array(
                'company_name' => get_option( 'bookly_co_name' ),
                'company_phone' => get_option( 'bookly_co_phone' ),
                'company_website' => get_option( 'bookly_co_website' ),
                'company_address' => get_option( 'bookly_co_address' ),
            ),
            // Every code of a mailing can be empty for a recipient, so each can be a condition.
            // The groups only try out the grouped list: seven codes read as well without them.
            'code_editor' => Lib\Utils\CodesCatalog::forEditor( array(
                'client_first_name' => array( 'description' => __( 'Client first name', 'bookly-responsive-appointment-booking-tool' ), 'if' => true, 'group' => 'customer' ),
                'client_last_name' => array( 'description' => __( 'Client last name', 'bookly-responsive-appointment-booking-tool' ), 'if' => true, 'group' => 'customer' ),
                'client_name' => array( 'description' => __( 'Client name', 'bookly-responsive-appointment-booking-tool' ), 'if' => true, 'group' => 'customer' ),
                'company_name' => array( 'description' => __( 'Company name', 'bookly-responsive-appointment-booking-tool' ), 'if' => true, 'group' => 'company' ),
                'company_phone' => array( 'description' => __( 'Company phone', 'bookly-responsive-appointment-booking-tool' ), 'if' => true, 'group' => 'company' ),
                'company_website' => array( 'description' => __( 'Company website', 'bookly-responsive-appointment-booking-tool' ), 'if' => true, 'group' => 'company' ),
                'company_address' => array( 'description' => __( 'Company address', 'bookly-responsive-appointment-booking-tool' ), 'if' => true, 'group' => 'company' ),
            ) ),
            'l10n' => array(
                'presets' => array(
                    'lapsed' => array(
                        'title' => __( 'Haven\'t visited in a while', 'bookly-responsive-appointment-booking-tool' ),
                        'lead' => __( 'Bring past customers back for another appointment.', 'bookly-responsive-appointment-booking-tool' ),
                        'phrase' => __( 'Visited at least {min_visits} times, last visit {from} to {to} days ago', 'bookly-responsive-appointment-booking-tool' ),
                        'text' => __( 'Hi {client_first_name}, we miss you at {company_name}! Book your next visit: {company_website}', 'bookly-responsive-appointment-booking-tool' ),
                    ),
                    'once' => array(
                        'title' => __( 'Came once and didn\'t return', 'bookly-responsive-appointment-booking-tool' ),
                        'lead' => __( 'Encourage first-time customers to come back.', 'bookly-responsive-appointment-booking-tool' ),
                        'phrase' => __( 'Exactly one visit, {from} to {to} days ago', 'bookly-responsive-appointment-booking-tool' ),
                        'text' => __( 'Hi {client_first_name}, thanks for visiting {company_name}. We would love to see you again: {company_website}', 'bookly-responsive-appointment-booking-tool' ),
                    ),
                    'cancelled' => array(
                        'title' => __( 'Cancelled and didn\'t rebook', 'bookly-responsive-appointment-booking-tool' ),
                        'lead' => __( 'Help customers find a new time that works for them.', 'bookly-responsive-appointment-booking-tool' ),
                        'phrase' => __( 'Cancelled {from} to {to} days ago, no new booking or visit since', 'bookly-responsive-appointment-booking-tool' ),
                        'text' => __( 'Hi {client_first_name}, you cancelled your visit to {company_name}. Let us find a new time: {company_website}', 'bookly-responsive-appointment-booking-tool' ),
                    ),
                    'regular' => array(
                        'title' => __( 'Regular gone quiet', 'bookly-responsive-appointment-booking-tool' ),
                        'lead' => __( 'Find regular customers who haven\'t visited as often as usual.', 'bookly-responsive-appointment-booking-tool' ),
                        'phrase' => __( 'At least {min_visits} visits, away longer than {intervals} of their usual intervals, between {from} and {to} days', 'bookly-responsive-appointment-booking-tool' ),
                        'text' => __( 'Hi {client_first_name}, it has been a while since your last visit to {company_name}. Your usual time is waiting: {company_website}', 'bookly-responsive-appointment-booking-tool' ),
                    ),
                    'top' => array(
                        'title' => __( 'Top spenders', 'bookly-responsive-appointment-booking-tool' ),
                        'lead' => __( 'Reward your highest-spending customers.', 'bookly-responsive-appointment-booking-tool' ),
                        'phrase' => __( 'Top {percent}% by payments over the last {months} months, visited within {recent} days', 'bookly-responsive-appointment-booking-tool' ),
                        'text' => __( 'Hi {client_first_name}, thank you for being with {company_name}. As one of our best clients, here is a treat on your next visit.', 'bookly-responsive-appointment-booking-tool' ),
                    ),
                    'birthday' => array(
                        'title' => __( 'Birthday coming up', 'bookly-responsive-appointment-booking-tool' ),
                        'lead' => __( 'A greeting with a reason to book.', 'bookly-responsive-appointment-booking-tool' ),
                        'phrase' => __( 'Birthday within the next {days} days', 'bookly-responsive-appointment-booking-tool' ),
                        'text' => __( 'Happy birthday, {client_first_name}! Treat yourself at {company_name}: {company_website}', 'bookly-responsive-appointment-booking-tool' ),
                    ),
                    'booked' => array(
                        'title' => __( 'Booked for a period', 'bookly-responsive-appointment-booking-tool' ),
                        'lead' => __( 'Reach customers with bookings during a specific period.', 'bookly-responsive-appointment-booking-tool' ),
                        'phrase' => __( 'Have a booking within {dates} with {staff_id}', 'bookly-responsive-appointment-booking-tool' ),
                        'text' => __( 'Hi {client_first_name}, a note about your upcoming visit to {company_name}: {company_website}', 'bookly-responsive-appointment-booking-tool' ),
                    ),
                    'all' => array(
                        'title' => __( 'All customers', 'bookly-responsive-appointment-booking-tool' ),
                        'lead' => __( 'Share news, holiday hours, or information about new services.', 'bookly-responsive-appointment-booking-tool' ),
                        'phrase' => __( 'Everyone in your customer list who has a phone number', 'bookly-responsive-appointment-booking-tool' ),
                        'text' => '',
                    ),
                    'custom' => array(
                        'title' => __( 'Your own list', 'bookly-responsive-appointment-booking-tool' ),
                        'lead' => __( 'Filter customers and add phone numbers manually. Your list will be saved for future campaigns.', 'bookly-responsive-appointment-booking-tool' ),
                    ),
                ),
                'new_campaign' => __( 'New campaign', 'bookly-responsive-appointment-booking-tool' ),
                'edit_campaign' => __( 'Edit campaign', 'bookly-responsive-appointment-booking-tool' ),
                'who' => __( 'Who should get this message?', 'bookly-responsive-appointment-booking-tool' ),
                'recipients' => __( 'recipients', 'bookly-responsive-appointment-booking-tool' ),
                'recipients_title' => __( 'Recipients', 'bookly-responsive-appointment-booking-tool' ),
                'continue' => __( 'Continue', 'bookly-responsive-appointment-booking-tool' ),
                'name' => __( 'Name', 'bookly-responsive-appointment-booking-tool' ),
                'name_hint' => __( 'Only you see it.', 'bookly-responsive-appointment-booking-tool' ),
                'audience' => __( 'Audience', 'bookly-responsive-appointment-booking-tool' ),
                'reset_defaults' => __( 'Reset to defaults', 'bookly-responsive-appointment-booking-tool' ),
                'show_recipients' => __( 'Show recipients', 'bookly-responsive-appointment-booking-tool' ),
                'hide_recipients' => __( 'Hide recipients', 'bookly-responsive-appointment-booking-tool' ),
                'and_more' => __( 'and %d more', 'bookly-responsive-appointment-booking-tool' ),
                'remove' => __( 'Remove', 'bookly-responsive-appointment-booking-tool' ),
                'add_recipients' => __( 'Add recipients', 'bookly-responsive-appointment-booking-tool' ) . '…',
                'add_customer' => __( 'Customer', 'bookly-responsive-appointment-booking-tool' ),
                'add_customer_help' => __( 'Selecting a customer adds them to the list immediately.', 'bookly-responsive-appointment-booking-tool' ),
                'add_phones' => __( 'Phone numbers, one per line', 'bookly-responsive-appointment-booking-tool' ),
                'add' => __( 'Add', 'bookly-responsive-appointment-booking-tool' ),
                'added' => __( 'Added', 'bookly-responsive-appointment-booking-tool' ),
                'phones_rejected' => __( 'These numbers are too short to be valid phone numbers. Correct them and click Add again.', 'bookly-responsive-appointment-booking-tool' ),
                'customer_select' => array(
                    'placeholder' => __( 'Search for customer', 'bookly-responsive-appointment-booking-tool' ),
                    'noResults' => __( 'No results found', 'bookly-responsive-appointment-booking-tool' ),
                    'searching' => __( 'Searching', 'bookly-responsive-appointment-booking-tool' ),
                    'more_results' => __( 'Refine your search to see more', 'bookly-responsive-appointment-booking-tool' ),
                ),
                'any_staff' => __( 'any staff', 'bookly-responsive-appointment-booking-tool' ),
                'added_by_hand' => __( 'added manually', 'bookly-responsive-appointment-booking-tool' ),
                'by_hand' => __( 'Added manually', 'bookly-responsive-appointment-booking-tool' ),
                'sum_of_payments' => __( 'Total paid at least', 'bookly-responsive-appointment-booking-tool' ),
                'count_of_appointments' => __( 'Visits at least', 'bookly-responsive-appointment-booking-tool' ),
                'services' => __( 'Services', 'bookly-responsive-appointment-booking-tool' ),
                'staff' => __( 'Staff', 'bookly-responsive-appointment-booking-tool' ),
                'all_services' => __( 'All services', 'bookly-responsive-appointment-booking-tool' ),
                'all_staff' => __( 'All staff', 'bookly-responsive-appointment-booking-tool' ),
                'no_service_selected' => __( 'No service selected', 'bookly-responsive-appointment-booking-tool' ),
                'no_staff_selected' => __( 'No staff selected', 'bookly-responsive-appointment-booking-tool' ),
                'custom_service' => __( 'Custom', 'bookly-responsive-appointment-booking-tool' ),
                'last_appointment' => __( 'Last appointment', 'bookly-responsive-appointment-booking-tool' ),
                'pasted' => __( 'Additional phone numbers, one per line', 'bookly-responsive-appointment-booking-tool' ),
                /* translators: %s: comma-separated list of numbers */
                'pasted_rejected' => __( 'Too short to be phone numbers, will not be added: %s', 'bookly-responsive-appointment-booking-tool' ),
                'message' => __( 'Message', 'bookly-responsive-appointment-booking-tool' ),
                'message_placeholder' => __( 'Write your message', 'bookly-responsive-appointment-booking-tool' ),
                'code_editor' => Lib\Utils\Codes::editorL10n(),
                'preview' => __( 'Preview', 'bookly-responsive-appointment-booking-tool' ),
                'characters' => __( '%1$d characters · %2$d SMS', 'bookly-responsive-appointment-booking-tool' ),
                'too_long' => __( 'Longer than one SMS (%1$d characters): each recipient gets %2$d SMS.', 'bookly-responsive-appointment-booking-tool' ),
                'too_long_unicode' => __( 'Longer than one SMS (%1$d characters with special characters): each recipient gets %2$d SMS.', 'bookly-responsive-appointment-booking-tool' ),
                'sending' => __( 'Sending', 'bookly-responsive-appointment-booking-tool' ),
                'send_now' => __( 'Send now', 'bookly-responsive-appointment-booking-tool' ),
                'send_at' => __( 'Send at', 'bookly-responsive-appointment-booking-tool' ),
                'date_time' => __( 'Date and time', 'bookly-responsive-appointment-booking-tool' ),
                'cron_notice' => \Bookly\Backend\Components\Notices\Cron\Notice::getText(),
                'datetime' => array(
                    'placeholder' => __( 'Select date', 'bookly-responsive-appointment-booking-tool' ) . '…',
                    'time' => __( 'Time', 'bookly-responsive-appointment-booking-tool' ),
                    'apply' => __( 'Apply', 'bookly-responsive-appointment-booking-tool' ),
                    'cancel' => __( 'Cancel', 'bookly-responsive-appointment-booking-tool' ),
                ),
                'date_range' => array(
                    'apply' => __( 'Apply', 'bookly-responsive-appointment-booking-tool' ),
                    'cancel' => __( 'Cancel', 'bookly-responsive-appointment-booking-tool' ),
                    'jumpToToday' => __( 'Jump to today', 'bookly-responsive-appointment-booking-tool' ),
                    'quickRange' => __( 'Quick range', 'bookly-responsive-appointment-booking-tool' ),
                    'custom' => __( 'Custom', 'bookly-responsive-appointment-booking-tool' ),
                ),
                'send_later' => __( 'Save without sending', 'bookly-responsive-appointment-booking-tool' ),
                'schedule' => __( 'Schedule', 'bookly-responsive-appointment-booking-tool' ),
                'save' => __( 'Save campaign', 'bookly-responsive-appointment-booking-tool' ),
                'cancel' => __( 'Cancel', 'bookly-responsive-appointment-booking-tool' ),
                'close' => __( 'Close', 'bookly-responsive-appointment-booking-tool' ),
                'sent' => __( 'Sent', 'bookly-responsive-appointment-booking-tool' ),
                'failed' => __( 'Failed', 'bookly-responsive-appointment-booking-tool' ),
                'waiting' => __( 'Waiting', 'bookly-responsive-appointment-booking-tool' ),
                'states' => array(
                    'ready' => __( 'Ready to send', 'bookly-responsive-appointment-booking-tool' ),
                    'scheduled' => __( 'Scheduled', 'bookly-responsive-appointment-booking-tool' ),
                    'sending' => __( 'Sending', 'bookly-responsive-appointment-booking-tool' ),
                    'completed' => __( 'Completed', 'bookly-responsive-appointment-booking-tool' ),
                    'stopped' => __( 'Stopped', 'bookly-responsive-appointment-booking-tool' ),
                ),
                'more' => __( 'More', 'bookly-responsive-appointment-booking-tool' ),
                'edit' => __( 'Edit', 'bookly-responsive-appointment-booking-tool' ) . '…',
                'duplicate' => __( 'Duplicate', 'bookly-responsive-appointment-booking-tool' ) . '…',
                'delete' => __( 'Delete', 'bookly-responsive-appointment-booking-tool' ),
                'stop' => __( 'Stop', 'bookly-responsive-appointment-booking-tool' ),
                /* translators: %d: number of recipients */
                'send_confirm' => __( 'Send this campaign to %d recipients now?', 'bookly-responsive-appointment-booking-tool' ),
                'sending_started' => __( 'Sending started.', 'bookly-responsive-appointment-booking-tool' ),
                'delete_confirm' => __( 'Delete this campaign?', 'bookly-responsive-appointment-booking-tool' ),
                'stop_confirm' => __( 'Stop this campaign? Messages not sent yet will not be sent.', 'bookly-responsive-appointment-booking-tool' ),
                'started_at' => __( 'Started', 'bookly-responsive-appointment-booking-tool' ),
                /* translators: %s: date and time */
                'scheduled_for' => __( 'Scheduled for %s', 'bookly-responsive-appointment-booking-tool' ),
                'not_scheduled' => __( 'Not scheduled. Send it when you are ready.', 'bookly-responsive-appointment-booking-tool' ),
                'request_failed' => __( 'Request failed. Please try again.', 'bookly-responsive-appointment-booking-tool' ),
                'saved' => __( 'Campaign saved.', 'bookly-responsive-appointment-booking-tool' ),
                'copy' => __( '%s (copy)', 'bookly-responsive-appointment-booking-tool' ),
            ),
        );
    }

    public static function addBooklyMenuItem()
    {
        $sms = __( 'SMS Notifications', 'bookly-responsive-appointment-booking-tool' );

        $cloud = Lib\Cloud\API::getInstance();

        $promotion = $cloud->general->getPromotionForNotice();
        if ( $promotion ) {
            $title = sprintf( '%s <span class="update-plugins"><span class="update-count">$</span></span>', $sms );
        } else {
            $count = get_option( 'bookly_cloud_badge_consider_sms' ) ? Lib\Cloud\SMS::getUndeliveredSmsCount() : 0;
            $title = $count ? sprintf( '%s <span class="update-plugins"><span class="update-count">%d</span></span>', $sms, $count ) : $sms;
        }

        $page = $cloud->getToken() && $cloud->account->productActive( Lib\Cloud\Account::PRODUCT_SMS_NOTIFICATIONS ) ? self::pageSlug() : CloudProducts::pageSlug();

        add_submenu_page(
            'bookly-menu',
            $sms,
            '<span id="bookly-js-sms-menu-redirect">' . $title . '</span><script>document.getElementById("bookly-js-sms-menu-redirect").parentNode.href+="=' . $page . '";</script>',
            Lib\Utils\Common::getRequiredCapability(),
            '',
            function() { Page::render(); }
        );
    }

    /**
     * Show 'SMS Notifications' submenu with counter inside Bookly Cloud main menu.
     *
     * @param array $product
     */
    public static function addBooklyCloudMenuItem( $product )
    {
        $sms = $product['texts']['title'];

        $count = get_option( 'bookly_cloud_badge_consider_sms' ) ? Lib\Cloud\SMS::getUndeliveredSmsCount() : 0;
        $title = $count ? sprintf( '%s <span class="update-plugins"><span class="update-count">%d</span></span>', $sms, $count ) : $sms;

        add_submenu_page(
            'bookly-cloud-menu',
            $sms,
            $title,
            Lib\Utils\Common::getRequiredCapability(),
            self::pageSlug(),
            function() { Page::render(); }
        );
    }
}