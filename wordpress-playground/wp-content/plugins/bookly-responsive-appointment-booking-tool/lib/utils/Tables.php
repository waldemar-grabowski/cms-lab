<?php
namespace Bookly\Lib\Utils;

use Bookly\Lib;

abstract class Tables
{
    const ANALYTICS                     = 'analytics';
    const APPOINTMENTS                  = 'appointments';
    const CLOUD_MOBILE_STAFF_CABINET    = 'cloud_mobile_staff_cabinet';
    const CLOUD_PURCHASES               = 'cloud_purchases';
    const COUPONS                       = 'coupons';
    const CUSTOMERS                     = 'customers';
    const CUSTOMER_GROUPS               = 'customer_groups';
    const CUSTOM_STATUSES               = 'custom_statuses';
    const DISCOUNTS                     = 'discounts';
    const EMAIL_LOGS                    = 'email_logs';
    const EMAIL_NOTIFICATIONS           = 'email_notifications';
    const EVENTS                        = 'events';
    const GIFT_CARDS                    = 'gift_cards';
    const GIFT_CARD_TYPES               = 'gift_card_types';
    const LOCATIONS                     = 'locations';
    const PACKAGES                      = 'packages';
    const PAYMENTS                      = 'payments';
    const SERVICES                      = 'services';
    const SMS_DETAILS                   = 'sms_details';
    const SMS_MAILING_CAMPAIGNS         = 'sms_mailing_campaigns';
    const SMS_NOTIFICATIONS            = 'sms_notifications';
    const SMS_PRICES                    = 'sms_prices';
    const SMS_SENDER                    = 'sms_sender';
    const STAFF_MEMBERS                 = 'staff_members';
    const TAXES                         = 'taxes';
    const VOICE_DETAILS                 = 'voice_details';
    const VOICE_NOTIFICATIONS           = 'voice_notifications';
    const VOICE_PRICES                  = 'voice_prices';
    const WHATSAPP_DETAILS              = 'whatsapp_details';
    const WHATSAPP_NOTIFICATIONS        = 'whatsapp_notifications';
    const LOGS                          = 'logs';
    const CUSTOMER_CABINET_APPOINTMENTS = 'customer_cabinet_appointments';

    /**
     * Get columns for given table.
     *
     * @param string $table
     * @return array
     */
    public static function getColumns( $table )
    {
        $columns = array();
        switch ( $table ) {
            case self::ANALYTICS:
                $columns = array(
                    'staff'                   => esc_html__( 'Staff', 'bookly-responsive-appointment-booking-tool' ),
                    'service'                 => esc_html__( 'Service', 'bookly-responsive-appointment-booking-tool' ),
                    'appointments_total'      => esc_html__( 'Appointments', 'bookly-responsive-appointment-booking-tool' ),
                    'appointments_approved'   => esc_html__( 'Approved', 'bookly-responsive-appointment-booking-tool' ),
                    'appointments_pending'    => esc_html__( 'Pending', 'bookly-responsive-appointment-booking-tool' ),
                    'appointments_cancelled'  => esc_html__( 'Cancelled', 'bookly-responsive-appointment-booking-tool' ),
                    'appointments_rejected'   => esc_html__( 'Rejected', 'bookly-responsive-appointment-booking-tool' ),
                    'appointments_waitlisted' => esc_html__( 'Waitlisted', 'bookly-responsive-appointment-booking-tool' ),
                    'customers_total'         => esc_html__( 'Customers', 'bookly-responsive-appointment-booking-tool' ),
                    'revenue'                 => esc_html__( 'Revenue', 'bookly-responsive-appointment-booking-tool' ),
                );
                break;
            case self::APPOINTMENTS:
                $columns = array(
                    'id' => esc_html__( 'ID', 'bookly-responsive-appointment-booking-tool' ),
                    'no' => esc_html_x( 'No.', 'number', 'bookly-responsive-appointment-booking-tool' ),
                    'start_date' => esc_html__( 'Appointment date', 'bookly-responsive-appointment-booking-tool' ),
                    'staff_name' => esc_html__( 'Staff', 'bookly-responsive-appointment-booking-tool' ),
                    'customer_full_name' => esc_html__( 'Customer name', 'bookly-responsive-appointment-booking-tool' ),
                    'customer_phone' => esc_html__( 'Customer phone', 'bookly-responsive-appointment-booking-tool' ),
                    'customer_email' => esc_html__( 'Customer email', 'bookly-responsive-appointment-booking-tool' ),
                    'service_title' => esc_html__( 'Service', 'bookly-responsive-appointment-booking-tool' ),
                    'service_duration' => esc_html__( 'Duration', 'bookly-responsive-appointment-booking-tool' ),
                    'service_price' => esc_html__( 'Price', 'bookly-responsive-appointment-booking-tool' ),
                    'status' => esc_html__( 'Status', 'bookly-responsive-appointment-booking-tool' ),
                    'payment' => esc_html__( 'Payment', 'bookly-responsive-appointment-booking-tool' ),
                    'notes' => esc_html__( 'Notes', 'bookly-responsive-appointment-booking-tool' ),
                    'created_date' => esc_html__( 'Created', 'bookly-responsive-appointment-booking-tool' ),
                    'internal_note' => esc_html__( 'Internal note', 'bookly-responsive-appointment-booking-tool' ),
                );
                break;
            case self::CLOUD_PURCHASES:
                $columns = array(
                    'date' => esc_html__( 'Date', 'bookly-responsive-appointment-booking-tool' ),
                    'time' => esc_html__( 'Time', 'bookly-responsive-appointment-booking-tool' ),
                    'type' => esc_html__( 'Type', 'bookly-responsive-appointment-booking-tool' ),
                    'status' => esc_html__( 'Status', 'bookly-responsive-appointment-booking-tool' ),
                    'amount' => esc_html__( 'Amount', 'bookly-responsive-appointment-booking-tool' ),
                );
                break;
            case self::CUSTOMERS:
                $columns = array(
                    'id' => esc_html__( 'ID', 'bookly-responsive-appointment-booking-tool' ),
                    'image' => esc_html__( 'Image', 'bookly-responsive-appointment-booking-tool' ),
                    'full_name' => esc_html__( 'Full name', 'bookly-responsive-appointment-booking-tool' ),
                    'first_name' => esc_html__( 'First name', 'bookly-responsive-appointment-booking-tool' ),
                    'last_name' => esc_html__( 'Last name', 'bookly-responsive-appointment-booking-tool' ),
                    'wp_user' => esc_html__( 'User', 'bookly-responsive-appointment-booking-tool' ),
                    'phone' => esc_html__( 'Phone', 'bookly-responsive-appointment-booking-tool' ),
                    'email' => esc_html__( 'Email', 'bookly-responsive-appointment-booking-tool' ),
                    'notes' => esc_html__( 'Notes', 'bookly-responsive-appointment-booking-tool' ),
                    'last_appointment' => esc_html__( 'Last appointment', 'bookly-responsive-appointment-booking-tool' ),
                    'total_appointments' => esc_html__( 'Total appointments', 'bookly-responsive-appointment-booking-tool' ),
                    'payments' => esc_html__( 'Payments', 'bookly-responsive-appointment-booking-tool' ),
                    'birthday' => esc_html__( 'Birthday', 'bookly-responsive-appointment-booking-tool' ),
                );
                break;
            case self::EMAIL_NOTIFICATIONS:
            case self::SMS_NOTIFICATIONS:
            case self::VOICE_NOTIFICATIONS:
            case self::WHATSAPP_NOTIFICATIONS:
                $columns = array(
                    'id' => esc_html__( 'ID', 'bookly-responsive-appointment-booking-tool' ),
                    'type' => esc_html__( 'Type', 'bookly-responsive-appointment-booking-tool' ),
                    'name' => esc_html__( 'Name', 'bookly-responsive-appointment-booking-tool' ),
                    'active' => esc_html__( 'State', 'bookly-responsive-appointment-booking-tool' ),
                );
                break;
            case self::EMAIL_LOGS:
                $columns = array(
                    'id' => esc_html__( 'ID', 'bookly-responsive-appointment-booking-tool' ),
                    'to' => esc_html_x( 'To', 'email recipient', 'bookly-responsive-appointment-booking-tool' ),
                    'subject' => esc_html__( 'Subject', 'bookly-responsive-appointment-booking-tool' ),
                    'created_at' => esc_html__( 'Created', 'bookly-responsive-appointment-booking-tool' ),
                );
                break;
            case self::PAYMENTS:
                $columns = array(
                    'id' => esc_html_x( 'No.', 'number', 'bookly-responsive-appointment-booking-tool' ),
                    'created_at' => esc_html__( 'Date', 'bookly-responsive-appointment-booking-tool' ),
                    'type' => esc_html__( 'Type', 'bookly-responsive-appointment-booking-tool' ),
                    'customer' => esc_html__( 'Customer', 'bookly-responsive-appointment-booking-tool' ),
                    'provider' => esc_html__( 'Provider', 'bookly-responsive-appointment-booking-tool' ),
                    'service' => esc_html__( 'Service', 'bookly-responsive-appointment-booking-tool' ),
                    'start_date' => esc_html__( 'Appointment date', 'bookly-responsive-appointment-booking-tool' ),
                    'paid' => esc_html__( 'Amount', 'bookly-responsive-appointment-booking-tool' ),
                    'subtotal' => esc_html__( 'Subtotal', 'bookly-responsive-appointment-booking-tool' ),
                    'status' => esc_html__( 'Status', 'bookly-responsive-appointment-booking-tool' ),
                );
                break;
            case self::SERVICES:
                $columns = array(
                    'id' => esc_html__( 'ID', 'bookly-responsive-appointment-booking-tool' ),
                    'image' => esc_html__( 'Image', 'bookly-responsive-appointment-booking-tool' ),
                    'title' => esc_html__( 'Title', 'bookly-responsive-appointment-booking-tool' ),
                    'category_name' => esc_html__( 'Category', 'bookly-responsive-appointment-booking-tool' ),
                    'duration' => esc_html__( 'Duration', 'bookly-responsive-appointment-booking-tool' ),
                    'price' => esc_html__( 'Price', 'bookly-responsive-appointment-booking-tool' ),
                );
                break;
            case self::SMS_MAILING_CAMPAIGNS:
                $columns = array(
                    'id' => esc_html__( 'ID', 'bookly-responsive-appointment-booking-tool' ),
                    'name' => esc_html__( 'Name', 'bookly-responsive-appointment-booking-tool' ),
                    'audience' => esc_html__( 'Audience', 'bookly-responsive-appointment-booking-tool' ),
                    'recipients' => esc_html__( 'Recipients', 'bookly-responsive-appointment-booking-tool' ),
                    'sent' => esc_html__( 'Sent', 'bookly-responsive-appointment-booking-tool' ),
                    'failed' => esc_html__( 'Failed', 'bookly-responsive-appointment-booking-tool' ),
                    'send_at' => esc_html__( 'Start at', 'bookly-responsive-appointment-booking-tool' ),
                    'created_at' => esc_html__( 'Created', 'bookly-responsive-appointment-booking-tool' ),
                    'state' => esc_html__( 'State', 'bookly-responsive-appointment-booking-tool' ),
                );
                break;
            case self::SMS_DETAILS:
                $columns = array(
                    'date' => esc_html__( 'Date', 'bookly-responsive-appointment-booking-tool' ),
                    'time' => esc_html__( 'Time', 'bookly-responsive-appointment-booking-tool' ),
                    'message' => esc_html__( 'Text', 'bookly-responsive-appointment-booking-tool' ),
                    'phone' => esc_html__( 'Phone', 'bookly-responsive-appointment-booking-tool' ),
                    'sender_id' => esc_html__( 'Sender ID', 'bookly-responsive-appointment-booking-tool' ),
                    'charge' => esc_html__( 'Cost', 'bookly-responsive-appointment-booking-tool' ),
                    'status' => esc_html__( 'Status', 'bookly-responsive-appointment-booking-tool' ),
                    'info' => esc_html__( 'Info', 'bookly-responsive-appointment-booking-tool' ),
                );
                break;
            case self::VOICE_DETAILS:
                $columns = array(
                    'date' => esc_html__( 'Date', 'bookly-responsive-appointment-booking-tool' ),
                    'time' => esc_html__( 'Time', 'bookly-responsive-appointment-booking-tool' ),
                    'message' => esc_html__( 'Text', 'bookly-responsive-appointment-booking-tool' ),
                    'phone' => esc_html__( 'Phone', 'bookly-responsive-appointment-booking-tool' ),
                    'duration' => esc_html__( 'Duration', 'bookly-responsive-appointment-booking-tool' ),
                    'charge' => esc_html__( 'Cost', 'bookly-responsive-appointment-booking-tool' ),
                    'status' => esc_html__( 'Status', 'bookly-responsive-appointment-booking-tool' ),
                );
                break;
            case self::WHATSAPP_DETAILS:
                $columns = array(
                    'date' => esc_html__( 'Date', 'bookly-responsive-appointment-booking-tool' ),
                    'time' => esc_html__( 'Time', 'bookly-responsive-appointment-booking-tool' ),
                    'template' => esc_html__( 'Template', 'bookly-responsive-appointment-booking-tool' ),
                    'language' => esc_html__( 'Language', 'bookly-responsive-appointment-booking-tool' ),
                    'phone' => esc_html__( 'Phone', 'bookly-responsive-appointment-booking-tool' ),
                    'charge' => esc_html__( 'Cost', 'bookly-responsive-appointment-booking-tool' ),
                    'status' => esc_html__( 'Status', 'bookly-responsive-appointment-booking-tool' ),
                    'info' => esc_html__( 'Info', 'bookly-responsive-appointment-booking-tool' ),
                );
                break;
            case self::VOICE_PRICES:
                $columns = array(
                    'country_iso_code' => esc_html__( 'Flag', 'bookly-responsive-appointment-booking-tool' ),
                    'country_name' => esc_html__( 'Country', 'bookly-responsive-appointment-booking-tool' ),
                    'phone_code' => esc_html__( 'Code', 'bookly-responsive-appointment-booking-tool' ),
                    'call_price' => esc_html__( 'Price/Minute', 'bookly-responsive-appointment-booking-tool' ),
                );
                break;
            case self::SMS_PRICES:
                $columns = array(
                    'country_iso_code' => esc_html__( 'Flag', 'bookly-responsive-appointment-booking-tool' ),
                    'country_name' => esc_html__( 'Country', 'bookly-responsive-appointment-booking-tool' ),
                    'phone_code' => esc_html__( 'Code', 'bookly-responsive-appointment-booking-tool' ),
                    'price' => esc_html__( 'Regular price', 'bookly-responsive-appointment-booking-tool' ),
                    'price_alt' => esc_html__( 'Price with custom Sender ID', 'bookly-responsive-appointment-booking-tool' ),
                );
                break;
            case self::SMS_SENDER:
                $columns = array(
                    'date' => esc_html__( 'Date', 'bookly-responsive-appointment-booking-tool' ),
                    'name' => esc_html__( 'Requested ID', 'bookly-responsive-appointment-booking-tool' ),
                    'country' => esc_html__( 'Country', 'bookly-responsive-appointment-booking-tool' ),
                    'status' => esc_html__( 'Status', 'bookly-responsive-appointment-booking-tool' ),
                    'status_date' => esc_html__( 'Status date', 'bookly-responsive-appointment-booking-tool' ),
                );
                break;
            case self::STAFF_MEMBERS:
                $columns = array(
                    'id' => esc_html__( 'ID', 'bookly-responsive-appointment-booking-tool' ),
                    'full_name' => esc_html__( 'Name', 'bookly-responsive-appointment-booking-tool' ),
                    'email' => esc_html__( 'Email', 'bookly-responsive-appointment-booking-tool' ),
                    'phone' => esc_html__( 'Phone', 'bookly-responsive-appointment-booking-tool' ),
                    'wp_user' => esc_html__( 'User', 'bookly-responsive-appointment-booking-tool' ),
                    'image' => esc_html__( 'Image', 'bookly-responsive-appointment-booking-tool' ),
                );
                break;
            case self::CLOUD_MOBILE_STAFF_CABINET:
                $columns = array(
                    'id' => esc_html__( 'ID', 'bookly-responsive-appointment-booking-tool' ),
                    'full_name' => esc_html__( 'Name', 'bookly-responsive-appointment-booking-tool' ),
                    'token' => esc_html__( 'Access token', 'bookly-responsive-appointment-booking-tool' ),
                );
                break;
            case self::CUSTOMER_CABINET_APPOINTMENTS:
                $columns = array(
                    'category' => Common::getTranslatedOption( 'bookly_l10n_label_category' ),
                    'service' => Common::getTranslatedOption( 'bookly_l10n_label_service' ),
                    'staff' => Common::getTranslatedOption( 'bookly_l10n_label_employee' ),
                    'location' => Common::getTranslatedOption( 'bookly_l10n_label_location' ),
                    'duration' => __( 'Duration', 'bookly-responsive-appointment-booking-tool' ),
                    'date' => __( 'Date', 'bookly-responsive-appointment-booking-tool' ),
                    'time' => __( 'Time', 'bookly-responsive-appointment-booking-tool' ),
                    'price' => __( 'Price', 'bookly-responsive-appointment-booking-tool' ),
                    'online_meeting' => __( 'Online meeting', 'bookly-responsive-appointment-booking-tool' ),
                    'join_online_meeting' => __( 'Join online meeting', 'bookly-responsive-appointment-booking-tool' ),
                    'cancel' => __( 'Cancel', 'bookly-responsive-appointment-booking-tool' ),
                    'reschedule' => __( 'Reschedule', 'bookly-responsive-appointment-booking-tool' ),
                    'status' => __( 'Status', 'bookly-responsive-appointment-booking-tool' ),
                );
                break;
            case self::LOGS:
                $columns = array(
                    'id' => __( 'ID', 'bookly-responsive-appointment-booking-tool' ),
                    'created_at' => __( 'Date', 'bookly-responsive-appointment-booking-tool' ),
                    'action' => __( 'Action', 'bookly-responsive-appointment-booking-tool' ),
                    'target' => __( 'Target', 'bookly-responsive-appointment-booking-tool' ),
                    'target_id' => __( 'Target ID', 'bookly-responsive-appointment-booking-tool' ),
                    'author' => __( 'Author', 'bookly-responsive-appointment-booking-tool' ),
                    'details' => __( 'Details', 'bookly-responsive-appointment-booking-tool' ),
                    'comment' => __( 'Comment', 'bookly-responsive-appointment-booking-tool' ),
                    'ref' => __( 'Reference', 'bookly-responsive-appointment-booking-tool' ),
                );
                break;
        }

        return Lib\Proxy\Shared::prepareTableColumns( $columns, $table );
    }

    /**
     * Get table settings.
     *
     * @param string|array $tables
     * @return array
     */
    public static function getSettings( $tables )
    {
        if ( ! is_array( $tables ) ) {
            $tables = array( $tables );
        }
        $result = array();
        $l10n = array(
            'emptyTable' => __( 'No data available in table', 'bookly-responsive-appointment-booking-tool' ),
            'zeroRecords' => __( 'No matching records found', 'bookly-responsive-appointment-booking-tool' ),
            'rowsPerPage' => __( 'Rows per page', 'bookly-responsive-appointment-booking-tool' ),
            'responsiveTable' => __( 'Responsive table', 'bookly-responsive-appointment-booking-tool' ),
            'refresh' => __( 'Refresh', 'bookly-responsive-appointment-booking-tool' ),
            'tableSettings' => __( 'Table settings', 'bookly-responsive-appointment-booking-tool' ),
            'columns' => __( 'Columns', 'bookly-responsive-appointment-booking-tool' ),
            'searchColumns' => __( 'Search columns', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'noColumnsMatch' => __( 'No columns match', 'bookly-responsive-appointment-booking-tool' ),
            'resetToDefaults' => __( 'Reset to defaults', 'bookly-responsive-appointment-booking-tool' ),
            'save' => __( 'Save', 'bookly-responsive-appointment-booking-tool' ),
            'cancel' => __( 'Cancel', 'bookly-responsive-appointment-booking-tool' ),
            'apply' => __( 'Apply', 'bookly-responsive-appointment-booking-tool' ),
            'jumpToToday' => __( 'Jump to today', 'bookly-responsive-appointment-booking-tool' ),
            'quickRange' => __( 'Quick range', 'bookly-responsive-appointment-booking-tool' ),
            'custom' => __( 'Custom', 'bookly-responsive-appointment-booking-tool' ),
            'filter' => __( 'Filter', 'bookly-responsive-appointment-booking-tool' ),
            'addFilter' => __( 'Add filter', 'bookly-responsive-appointment-booking-tool' ),
            'clearSearch' => __( 'Clear search', 'bookly-responsive-appointment-booking-tool' ),
            'removeFilter' => __( 'Remove filter', 'bookly-responsive-appointment-booking-tool' ),
            'clearFilter' => __( 'Clear filter', 'bookly-responsive-appointment-booking-tool' ),
            'nOfM' => __( '%s of %s', 'bookly-responsive-appointment-booking-tool' ),
            'showing' => __( 'Showing %1$s of %2$s entries', 'bookly-responsive-appointment-booking-tool' ),
            'loadError' => __( 'Failed to load data', 'bookly-responsive-appointment-booking-tool' ),
        );
        foreach ( $tables as $table ) {
            $columns = self::getColumns( $table );
            $meta = get_user_meta( get_current_user_id(), 'bookly_' . $table . '_table_settings', true );
            $defaults = self::getDefaultSettings( $table );

            $exist = true;
            if ( ! $meta ) {
                $exist = false;
                $meta = array();
            }

            if ( ! isset ( $meta['columns'] ) ) {
                $meta['columns'] = array();
            }

            // Remove columns with no title.
            foreach ( $meta['columns'] as $key => $column ) {
                if ( ! isset( $columns[ $key ] ) ) {
                    unset( $meta['columns'][ $key ] );
                }
            }
            // New columns, which not saved at meta
            // show/hide if default settings exist and show without default settings
            foreach ( $columns as $column => $title ) {
                if ( ! isset ( $meta['columns'][ $column ] ) ) {
                    $meta['columns'][ $column ] = array_key_exists( $column, $defaults )
                        ? $defaults[ $column ]
                        : true;
                }
            }

            // Factory defaults — what the user sees on the very first visit
            // (no per-user customisations applied). The frontend uses this for
            // the "Reset to defaults" button.
            $factory_columns = array();
            $factory_order = array();
            foreach ( $columns as $column => $title ) {
                $factory_columns[ $column ] = array_key_exists( $column, $defaults ) ? $defaults[ $column ] : true;
                $factory_order[] = $column;
            }
            $factory_appearance = array( 'responsive_table' => true );

            $result[ $table ] = array(
                'settings' => array(
                    'columns' => $meta['columns'],
                    'filter' => isset ( $meta['filter'] ) ? $meta['filter'] : array(),
                    'order' => isset ( $meta['order'] ) ? $meta['order'] : array(),
                    'page_length' => isset ( $meta['page_length'] ) ? $meta['page_length'] : 25,
                    'appearance' => isset( $meta['appearance'] ) ? $meta['appearance'] : $factory_appearance,
                ),
                'defaults' => array(
                    'columns' => $factory_columns,
                    'order' => $factory_order,
                    'page_length' => 25,
                    'appearance' => $factory_appearance,
                ),
                'titles' => $columns,
                'exist' => $exist,
            );
        }
        $result['l10n'] = $l10n;

        return $result;
    }

    /**
     * Update table settings.
     *
     * @param string $table
     * @param array $columns
     * @param array $order
     * @param array $filter
     */
    public static function updateSettings( $table, $columns, $order, $filter )
    {
        $meta = get_user_meta( get_current_user_id(), 'bookly_' . $table . '_table_settings', true ) ?: array();
        if ( $columns !== null && $order !== null ) {
            $order_columns = array();
            foreach ( $order as $sort_by ) {
                if ( isset( $columns[ $sort_by['column'] ] ) ) {
                    $order_columns[] = array(
                        'column' => $columns[ $sort_by['column'] ]['data'],
                        'order' => $sort_by['dir'],
                    );
                }
            }
            $meta['order'] = $order_columns;
        }

        $meta['filter'] = $filter;

        update_user_meta( get_current_user_id(), 'bookly_' . $table . '_table_settings', $meta );
    }

    /**
     * @param array $columns
     * @param string $table
     * @return array
     */
    public static function filterColumns( $columns, $table )
    {
        $def_columns = array_keys( self::getColumns( $table ) );

        $replaces = array();
        switch ( $table ) {
            case self::CUSTOMER_CABINET_APPOINTMENTS:
                $replaces = array(
                    'date' => 'start_date',
                    'service' => 'service.title',
                    'staff' => 'staff_name',
                    'online_meeting' => 'online_meeting_provider',
                    'join_online_meeting' => 'online_meeting_provider',
                );
                foreach ( $def_columns as &$column ) {
                    if ( strpos( $column, 'custom_field' ) === 0 ) {
                        $column = 'custom_fields.' . substr( $column, 13 );
                    }
                }
                break;
            case self::APPOINTMENTS:
                $replaces = array(
                    'customer_full_name' => 'customer.full_name',
                    'customer_phone' => 'customer.phone',
                    'customer_email' => 'customer.email',
                    'customer_address' => 'customer.address',
                    'customer_birthday' => 'customer.birthday',
                    'staff_name' => 'staff.name',
                    'service_title' => 'service.title',
                    'service_duration' => 'service.duration',
                    'service_price' => 'service.price',
                    'attachments' => 'attachment',
                    'online_meeting' => 'online_meeting_provider',
                );
                break;
            case self::PACKAGES:
                foreach ( $def_columns as &$column ) {
                    $column = str_replace( '_', '.', $column );
                }
                $def_columns[] = 'customer.full_name';
                $def_columns[] = 'created_at';
                break;
        }
        foreach ( $def_columns as &$column ) {
            foreach ( $replaces as $key => $replacement ) {
                if ( $column === $key ) {
                    $column = $replacement;
                }
            }
            if ( strpos( $column, 'custom_field' ) === 0 ) {
                $column = 'custom_fields.' . substr( $column, 13 );
            }
        }

        foreach ( $columns as &$column ) {
            if ( ! in_array( $column['data'], $def_columns, true ) ) {
                $column['data'] = 'true';
            }
        }

        return $columns;
    }

    /**
     * Get default settings for hide/show table columns
     *
     * @param string $table
     * @return array
     */
    private static function getDefaultSettings( $table )
    {
        $columns = array();
        switch ( $table ) {
            case self::CUSTOMERS:
                $columns = array(
                    'id' => false,
                    'full_name' => ! Lib\Config::showFirstLastName(),
                    'first_name' => Lib\Config::showFirstLastName(),
                    'last_name' => Lib\Config::showFirstLastName(),
                    'birthday' => false,
                );
                break;
            case self::APPOINTMENTS:
                $columns = array(
                    'no' => false,
                    'internal_note' => false,
                    'service_price' => false
                );
                break;
            case self::ANALYTICS:
                // Waitlisted is addon-gated and usually empty — available but hidden by default.
                $columns = array( 'appointments_waitlisted' => false );
                break;
            case self::EMAIL_LOGS:
            case self::EMAIL_NOTIFICATIONS:
            case self::SERVICES:
            case self::SMS_DETAILS:
            case self::SMS_MAILING_CAMPAIGNS:
            case self::SMS_NOTIFICATIONS:
            case self::STAFF_MEMBERS:
            case self::CLOUD_MOBILE_STAFF_CABINET:
                $columns = array( 'id' => false, );
                break;
        }

        return Lib\Proxy\Shared::prepareTableDefaultSettings( $columns, $table );
    }

    /**
     * @param string $table
     * @return bool
     */
    public static function supportPagination( $table )
    {
        switch ( $table ) {
            case self::APPOINTMENTS:
            case self::CUSTOMERS:
            case self::EMAIL_LOGS:
            case self::PAYMENTS:
            case self::SERVICES:
            case self::SMS_DETAILS:
            case self::STAFF_MEMBERS:

                return true;
        }

        return false;
    }
}
