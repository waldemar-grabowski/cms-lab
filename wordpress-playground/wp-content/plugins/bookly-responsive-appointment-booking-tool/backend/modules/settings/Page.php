<?php
namespace Bookly\Backend\Modules\Settings;

use Bookly\Lib;
use Bookly\Backend\Components\Schedule\Component as ScheduleComponent;
use Bookly\Lib\Entities\CustomerAppointment;

class Page extends Lib\Base\Ajax
{
    /**
     * Render page.
     */
    public static function render()
    {
        /** @var \WP_Locale $wp_locale */
        global $wp_locale;

        wp_enqueue_media();
        self::enqueueStyles( array(
            'wp' => array( 'wp-color-picker' ),
            'backend' => array( 'css/fontawesome-all.min.css' ),
            'alias' => array( 'bookly-backend-globals', ),
        ) );

        self::enqueueScripts( array(
            'wp' => array( 'wp-color-picker' ),
            'backend' => array(
                'js/sortable.min.js' => array( 'jquery' ),
                'js/jCal.js' => array( 'jquery' ),
                'js/range-tools.js' => array( 'bookly-backend-globals' ),
            ),
            'module' => array( 'js/settings.js' => array( 'bookly-intlTelInput.min.js', 'bookly-sortable.min.js', 'bookly-range-tools.js' ) ),
            'frontend' => array( 'js/intlTelInput.min.js' => array( 'jquery' ), ),
        ) );

        $current_tab = self::hasParameter( 'tab' ) ? self::parameter( 'tab' ) : 'general';
        $alert = array( 'success' => array(), 'error' => array() );

        // Save the settings.
        if ( ! empty ( $_POST ) && self::csrfTokenValid() ) {
            $alert = self::saveTab( self::parameter( 'tab' ), self::parameters() );
        }

        Proxy\Shared::enqueueAssets();

        wp_localize_script( 'bookly-settings.js', 'BooklyL10n', array(
            'alert' => $alert,
            'current_tab' => $current_tab,
            'default_country' => get_option( 'bookly_cst_phone_default_country' ),
            'holidays' => self::_getHolidays(),
            'loading_img' => plugins_url( 'bookly-responsive-appointment-booking-tool/backend/resources/images/loading.gif' ),
            'firstDay' => get_option( 'start_of_week' ),
            'days' => array_values( $wp_locale->weekday_abbrev ),
            'months' => array_values( $wp_locale->month ),
            'close' => __( 'Close', 'bookly-responsive-appointment-booking-tool' ),
            'repeat' => __( 'Repeat every year', 'bookly-responsive-appointment-booking-tool' ),
            'we_are_not_working' => __( 'We are not working on this day', 'bookly-responsive-appointment-booking-tool' ),
            'excluded_from_repeat' => __( 'This day is excluded from the repeating day off', 'bookly-responsive-appointment-booking-tool' ),
            'sample_price' => number_format_i18n( 10, 3 ),
            'are_you_sure' => __( 'Are you sure?', 'bookly-responsive-appointment-booking-tool' ),
            'datePicker' => Lib\Utils\DateTime::datePickerOptions(),
            'dateRange' => Lib\Utils\DateTime::dateRangeOptions( array( 'lastMonth' => __( 'Last month', 'bookly-responsive-appointment-booking-tool' ), ) ),
            'stripeCloudMetadata' => get_option( 'bookly_cloud_stripe_metadata', array() ),
            'zeroRecords' => __( 'No records for selected period.', 'bookly-responsive-appointment-booking-tool' ),
            'processing' => __( 'Processing', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'emptyTable' => __( 'No data available in table', 'bookly-responsive-appointment-booking-tool' ),
            'showMore' => __( 'Show more', 'bookly-responsive-appointment-booking-tool' ),
            'noResultsFound' => __( 'No results found', 'bookly-responsive-appointment-booking-tool' ),
        ) );
        $values = array();
        foreach ( Lib\Config::getTimeSlotLengthOptions() as $duration ) {
            $values['bookly_gen_time_slot_length'][] = array( $duration, Lib\Utils\DateTime::secondsToInterval( $duration * MINUTE_IN_SECONDS ) );
        }
        foreach (
            Lib\Proxy\CustomStatuses::prepareAllStatuses( array(
                Lib\Entities\CustomerAppointment::STATUS_APPROVED,
                Lib\Entities\CustomerAppointment::STATUS_PENDING,
            ) ) as $status
        ) {
            $values['statuses'][] = array( $status, Lib\Entities\CustomerAppointment::statusToString( $status ) );
        }
        $values['colors_status'] = Lib\Proxy\Shared::prepareColorsStatuses( array(
            CustomerAppointment::STATUS_PENDING => get_option( 'bookly_appointment_status_pending_color' ),
            CustomerAppointment::STATUS_APPROVED => get_option( 'bookly_appointment_status_approved_color' ),
            CustomerAppointment::STATUS_CANCELLED => get_option( 'bookly_appointment_status_cancelled_color' ),
            CustomerAppointment::STATUS_REJECTED => get_option( 'bookly_appointment_status_rejected_color' ),
        ) );
        if ( Lib\Config::proActive() ) {
            $values['colors_status']['mixed'] = get_option( 'bookly_appointment_status_mixed_color' );
        }

        $payments = self::_getPayments();
        $business_hours = self::_getBusinessHours();

        self::renderTemplate( 'index', compact( 'values', 'payments', 'business_hours' ) );
    }

    /**
     * Save one tab of the settings form: the options of the tab, then add-ons (Proxy\Shared::saveSettings).
     * Every field of the tab is written from $params, a field missing there is saved empty,
     * so $params must hold the whole tab as the form posts it.
     *
     * @param string $tab
     * @param array $params
     * @return array alert: array( 'success' => string[], 'error' => string[] )
     */
    public static function saveTab( $tab, array $params )
    {
        $alert = array( 'success' => array(), 'error' => array() );
        $request = new Lib\Utils\Collection( $params );
        // Log options before saving.
        try {
            foreach ( $params as $key => $value ) {
                if ( $key === 'purchase_code' ) {
                    foreach ( $value as $_key => $_value ) {
                        if ( strpos( $_key, 'bookly_' ) === 0 && ( $old_value = get_option( $_key, 'non-exists' ) ) !== $_value ) {
                            Lib\Utils\Log::put( Lib\Utils\Log::ACTION_DEBUG, $_key, null, htmlspecialchars( $old_value ) . ' -> ' . htmlspecialchars( $_value ), null, 'Update settings' );
                        }
                    }
                } else if ( strpos( $key, 'bookly_' ) === 0 && ( $old_value = get_option( $key, 'non-exists' ) ) !== $value ) {
                    Lib\Utils\Log::put( Lib\Utils\Log::ACTION_DEBUG, $key, null, htmlspecialchars( is_array( $old_value ) ? json_encode( $old_value ) : $old_value ) . ' -> ' . htmlspecialchars( is_array( $value ) ? json_encode( $value ) : $value ), null, 'Update settings' );
                }
            }
        } catch ( \Exception $e ) {
        }
        switch ( $tab ) {
            case 'calendar':  // Calendar form.
                update_option( 'bookly_cal_show_only_business_days', $request->get( 'bookly_cal_show_only_business_days' ) );
                update_option( 'bookly_cal_show_only_business_hours', $request->get( 'bookly_cal_show_only_business_hours' ) );
                update_option( 'bookly_cal_show_only_staff_with_appointments', $request->get( 'bookly_cal_show_only_staff_with_appointments' ) );
                update_option( 'bookly_cal_one_participant', $request->get( 'bookly_cal_one_participant' ) );
                update_option( 'bookly_cal_many_participants', $request->get( 'bookly_cal_many_participants' ) );
                update_option( 'bookly_cal_month_view_style', $request->get( 'bookly_cal_month_view_style' ) );
                update_option( 'bookly_cal_coloring_mode', $request->get( 'bookly_cal_coloring_mode' ) );
                update_option( 'bookly_cal_show_new_appointments_badge', $request->get( 'bookly_cal_show_new_appointments_badge' ) );
                update_option( 'bookly_cal_last_seen_appointment', $request->get( 'bookly_cal_last_seen_appointment' ) );
                update_option( 'bookly_cal_scrollable_calendar', $request->get( 'bookly_cal_scrollable_calendar' ) );
                update_option( 'bookly_legacy_calendar', $request->get( 'bookly_legacy_calendar' ) );
                foreach ( $request->get( 'status' ) as $status => $color ) {
                    if ( in_array( $status, array( CustomerAppointment::STATUS_PENDING, CustomerAppointment::STATUS_APPROVED, CustomerAppointment::STATUS_CANCELLED, CustomerAppointment::STATUS_REJECTED, 'mixed' ) ) ) {
                        update_option( sprintf( 'bookly_appointment_status_%s_color', $status ), $color );
                    }
                }
                $alert['success'][] = __( 'Settings saved.', 'bookly-responsive-appointment-booking-tool' );
                break;
            case 'payments':  // Payments form.
                update_option( 'bookly_pmt_order', $request->get( 'bookly_pmt_order' ) );
                update_option( 'bookly_pmt_currency', $request->get( 'bookly_pmt_currency' ) );
                update_option( 'bookly_pmt_price_format', $request->get( 'bookly_pmt_price_format' ) );
                update_option( 'bookly_pmt_local', $request->get( 'bookly_pmt_local' ) );
                if ( Lib\Cloud\API::getInstance()->account->productActive( Lib\Cloud\Account::PRODUCT_STRIPE ) ) {
                    update_option( 'bookly_cloud_stripe_enabled', $request->get( 'bookly_cloud_stripe_enabled' ) );
                    update_option( 'bookly_cloud_stripe_timeout', $request->get( 'bookly_cloud_stripe_timeout' ) );
                    update_option( 'bookly_cloud_stripe_increase', $request->get( 'bookly_cloud_stripe_increase' ) );
                    update_option( 'bookly_cloud_stripe_addition', $request->get( 'bookly_cloud_stripe_addition' ) );
                    update_option( 'bookly_cloud_stripe_custom_metadata', $request->get( 'bookly_cloud_stripe_custom_metadata' ) );
                    if ( $request->get( 'bookly_cloud_stripe_custom_metadata' ) ) {
                        $metadata = array();
                        $names = $request->get( 'bookly_cloud_stripe_meta_name', array() );
                        $values = $request->get( 'bookly_cloud_stripe_meta_value', array() );
                        foreach ( $names as $index => $name ) {
                            if ( $name != '' ) {
                                $metadata[] = array(
                                    'name' => $name,
                                    'value' => $values[ $index ],
                                );
                            }
                        }
                        update_option( 'bookly_cloud_stripe_metadata', $metadata );
                    }
                }
                $currencies = Lib\Utils\Price::getCurrencies();
                do_action( 'wpml_register_single_string', 'bookly', 'currency_' . $request->get( 'bookly_pmt_currency' ), $currencies[ $request->get( 'bookly_pmt_currency' ) ]['symbol'] );
                $alert['success'][] = __( 'Settings saved.', 'bookly-responsive-appointment-booking-tool' );
                break;
            case 'business_hours':  // Business hours form.
                foreach ( array( 'bookly_bh_monday_start', 'bookly_bh_monday_end', 'bookly_bh_tuesday_start', 'bookly_bh_tuesday_end', 'bookly_bh_wednesday_start', 'bookly_bh_wednesday_end', 'bookly_bh_thursday_start', 'bookly_bh_thursday_end', 'bookly_bh_friday_start', 'bookly_bh_friday_end', 'bookly_bh_saturday_start', 'bookly_bh_saturday_end', 'bookly_bh_sunday_start', 'bookly_bh_sunday_end', ) as $option_name ) {
                    update_option( $option_name, $request->get( $option_name ) );
                }
                $alert['success'][] = __( 'Settings saved.', 'bookly-responsive-appointment-booking-tool' );
                break;
            case 'general':  // General form.
                $bookly_gen_time_slot_length = $request->get( 'bookly_gen_time_slot_length' );
                if ( in_array( $bookly_gen_time_slot_length, Lib\Config::getTimeSlotLengthOptions() ) ) {
                    update_option( 'bookly_gen_time_slot_length', $bookly_gen_time_slot_length );
                }
                update_option( 'bookly_gen_delete_data_on_uninstall', $request->get( 'bookly_gen_delete_data_on_uninstall' ) );
                update_option( 'bookly_gen_service_duration_as_slot_length', (int) $request->get( 'bookly_gen_service_duration_as_slot_length' ) );
                update_option( 'bookly_gen_allow_staff_edit_profile', (int) $request->get( 'bookly_gen_allow_staff_edit_profile' ) );
                update_option( 'bookly_gen_link_assets_method', $request->get( 'bookly_gen_link_assets_method' ) );
                update_option( 'bookly_gen_max_days_for_booking', (int) $request->get( 'bookly_gen_max_days_for_booking' ) );
                update_option( 'bookly_gen_use_client_time_zone', (int) $request->get( 'bookly_gen_use_client_time_zone' ) );
                update_option( 'bookly_gen_collect_stats', $request->get( 'bookly_gen_collect_stats' ) );
                update_option( 'bookly_gen_show_powered_by', $request->get( 'bookly_gen_show_powered_by' ) );
                update_option( 'bookly_gen_prevent_caching', (int) $request->get( 'bookly_gen_prevent_caching' ) );
                update_option( 'bookly_gen_badge_consider_news', (int) $request->get( 'bookly_gen_badge_consider_news' ) );
                update_option( 'bookly_email_gateway', $request->get( 'bookly_email_gateway' ) );
                update_option( 'bookly_smtp_host', $request->get( 'bookly_smtp_host' ) );
                update_option( 'bookly_smtp_port', $request->get( 'bookly_smtp_port' ) );
                update_option( 'bookly_smtp_user', $request->get( 'bookly_smtp_user' ) );
                update_option( 'bookly_smtp_password', $request->get( 'bookly_smtp_password' ) );
                update_option( 'bookly_smtp_secure', $request->get( 'bookly_smtp_secure' ) );
                $alert['success'][] = __( 'Settings saved.', 'bookly-responsive-appointment-booking-tool' );
                break;
            case 'url': // URL settings form.
                update_option( 'bookly_url_approve_page_url', $request->get( 'bookly_url_approve_page_url' ) );
                update_option( 'bookly_url_approve_denied_page_url', $request->get( 'bookly_url_approve_denied_page_url' ) );
                update_option( 'bookly_url_cancel_page_url', $request->get( 'bookly_url_cancel_page_url' ) );
                update_option( 'bookly_url_cancel_denied_page_url', $request->get( 'bookly_url_cancel_denied_page_url' ) );
                update_option( 'bookly_url_reject_denied_page_url', $request->get( 'bookly_url_reject_denied_page_url' ) );
                update_option( 'bookly_url_reject_page_url', $request->get( 'bookly_url_reject_page_url' ) );
                $alert['success'][] = __( 'Settings saved.', 'bookly-responsive-appointment-booking-tool' );
                break;
            case 'customers':  // Customers form.
                update_option( 'bookly_cst_allow_duplicates', $request->get( 'bookly_cst_allow_duplicates' ) );
                update_option( 'bookly_cst_default_country_code', $request->get( 'bookly_cst_default_country_code' ) );
                update_option( 'bookly_cst_phone_default_country', $request->get( 'bookly_cst_phone_default_country' ) );
                update_option( 'bookly_cst_remember_in_cookie', $request->get( 'bookly_cst_remember_in_cookie' ) );
                update_option( 'bookly_cst_show_update_details_dialog', $request->get( 'bookly_cst_show_update_details_dialog' ) );
                update_option( 'bookly_cst_verify_customer_details', $request->get( 'bookly_cst_verify_customer_details' ) );
                // Update email required option if creating WordPress account for customers
                $bookly_cst_required_details = get_option( 'bookly_cst_required_details', array() );
                if ( $request->get( 'bookly_cst_create_account' ) && ! in_array( 'email', $bookly_cst_required_details ) ) {
                    $bookly_cst_required_details[] = 'email';
                    update_option( 'bookly_cst_required_details', $bookly_cst_required_details );
                }
                $alert['success'][] = __( 'Settings saved.', 'bookly-responsive-appointment-booking-tool' );
                break;
            case 'appointments':
                update_option( 'bookly_l10n_ics_customer_template', $request->get( 'bookly_l10n_ics_customer_template' ) );
                do_action( 'wpml_register_single_string', 'bookly', 'bookly_l10n_ics_customer_template', $request->get( 'bookly_l10n_ics_customer_template' ) );
                update_option( 'bookly_ics_staff_template', $request->get( 'bookly_ics_staff_template' ) );
                update_option( 'bookly_appointment_default_status', $request->get( 'bookly_appointment_default_status' ) );
                update_option( 'bookly_successful_payment_appointment_status', $request->get( 'bookly_successful_payment_appointment_status' ) );
                update_option( 'bookly_appointment_end_date_method', $request->get( 'bookly_appointment_end_date_method' ) );
                update_option( 'bookly_appointments_create_with_wizard', $request->get( 'bookly_appointments_create_with_wizard' ) );
                $alert['success'][] = __( 'Settings saved.', 'bookly-responsive-appointment-booking-tool' );
                break;
            case 'company':  // Company form.
                update_option( 'bookly_co_address', $request->get( 'bookly_co_address' ) );
                update_option( 'bookly_co_logo_attachment_id', $request->get( 'bookly_co_logo_attachment_id' ) );
                update_option( 'bookly_co_name', $request->get( 'bookly_co_name' ) );
                update_option( 'bookly_co_phone', $request->get( 'bookly_co_phone' ) );
                update_option( 'bookly_co_website', $request->get( 'bookly_co_website' ) );
                update_option( 'bookly_co_industry', $request->get( 'bookly_co_industry' ) );
                update_option( 'bookly_co_size', $request->get( 'bookly_co_size' ) );
                update_option( 'bookly_co_email', $request->get( 'bookly_co_email' ) );
                $alert['success'][] = __( 'Settings saved.', 'bookly-responsive-appointment-booking-tool' );
                break;
        }

        // Let Add-ons save their settings.
        return Proxy\Shared::saveSettings( $alert, $tab, $params );
    }

    /**
     * Get holidays.
     *
     * @return array
     */
    protected static function _getHolidays()
    {
        $collection = Lib\Entities\Holiday::query()->where( 'staff_id', null )->fetchArray();
        $holidays = array();
        if ( count( $collection ) ) {
            foreach ( $collection as $holiday ) {
                $holidays[ $holiday['id'] ] = array(
                    'm' => (int) date( 'm', strtotime( $holiday['date'] ) ),
                    'd' => (int) date( 'd', strtotime( $holiday['date'] ) ),
                );
                // If not repeated holiday, add the year
                if ( $holiday['repeat_event'] != Lib\Entities\Holiday::TYPE_YEARLY ) {
                    $holidays[ $holiday['id'] ]['y'] = (int) date( 'Y', strtotime( $holiday['date'] ) );
                }
                // The day is excluded from a repeated holiday
                if ( $holiday['repeat_event'] == Lib\Entities\Holiday::TYPE_EXCEPTION ) {
                    $holidays[ $holiday['id'] ]['exception'] = true;
                }
            }
        }

        return $holidays;
    }

    /**
     * @return ScheduleComponent
     */
    protected static function _getBusinessHours()
    {
        $business_hours = new ScheduleComponent( 'bookly_bh_{index}_start', 'bookly_bh_{index}_end', false );
        $week_days = array(
            1 => 'sunday',
            'monday',
            'tuesday',
            'wednesday',
            'thursday',
            'friday',
            'saturday',
        );
        $start_of_week = (int) get_option( 'start_of_week' );
        for ( $i = 1; $i <= 7; $i++ ) {
            $index = ( $start_of_week + $i ) < 8 ? $start_of_week + $i : $start_of_week + $i - 7;
            $day = $week_days[ $index ];
            $business_hours->addHours( $day, $index, get_option( 'bookly_bh_' . $day . '_start' ), get_option( 'bookly_bh_' . $day . '_end' ) );
        }

        return $business_hours;
    }

    /**
     * @return array
     */
    protected static function _getPayments()
    {
        $payments = array();
        $local = Lib\Entities\Payment::TYPE_LOCAL;
        $payment_data = array(
            $local => self::renderTemplate( '_payment_local', array( 'type' => $local ), false ),
        );
        if ( Lib\Cloud\API::getInstance()->account->productActive( Lib\Cloud\Account::PRODUCT_STRIPE ) ) {
            $stripe = Lib\Entities\Payment::TYPE_CLOUD_STRIPE;
            $payment_data[ $stripe ] = self::renderTemplate( '_cloud_stripe_settings', array( 'type' => $stripe ), false );
        }

        $payment_data = Proxy\Shared::preparePaymentGatewaySettings( $payment_data );

        $order = Lib\Config::getGatewaysPreference();
        foreach ( $order as $payment_system ) {
            if ( array_key_exists( $payment_system, $payment_data ) ) {
                $payments[] = $payment_data[ $payment_system ];
            }
        }
        foreach ( $payment_data as $slug => $data ) {
            if ( ! $order || ! in_array( $slug, $order ) ) {
                $payments[] = $data;
            }
        }

        return $payments;
    }
}