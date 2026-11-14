<?php
namespace Bookly\Backend\Components\Dialogs\Customer\Edit;

use Bookly\Lib;

class Dialog extends Lib\Base\Component
{
    /**
     * Render customer dialog.
     *
     * @param bool $show_wp_users
     */
    public static function render( $show_wp_users = true )
    {
        /** @global */
        global $wpdb;

        $tel_input_enabled = get_option( 'bookly_cst_phone_default_country' ) != 'disabled';
        is_admin() && wp_enqueue_media();
        self::enqueueStyles(
            $tel_input_enabled
                ? array( 'frontend' => array( 'css/intlTelInput.css' => array( 'bookly-backend-globals' ) ), )
                : array( 'alias' => array( 'bookly-backend-globals' ) )
        );

        self::enqueueScripts( array(
            'frontend' => $tel_input_enabled
                ? array( 'js/intlTelInput.min.js' => array( 'jquery' ) )
                : array(),
            'module' => array( 'js/customer.js' => is_admin() ? array( 'bookly-backend-globals', 'media-views' ) : array( 'bookly-backend-globals' ) ),
        ) );

        if ( $show_wp_users ) {
            $query = 'SELECT COUNT(*) FROM ' . $wpdb->users . ' AS u';
            if ( is_multisite() ) {
                $query .= ' INNER JOIN ' . $wpdb->usermeta . ' AS usermeta ON ( u.ID = usermeta.user_id ) WHERE usermeta.meta_key = \'' . $wpdb->prefix . 'capabilities\'';
            }
            $wp_users_remote = $wpdb->get_var( $query ) >= Lib\Entities\Customer::REMOTE_LIMIT;
            $wp_users = $wp_users_remote
                ? array()
                : $wpdb->get_results( self::getWPUsersQuery() . ' ORDER BY u.display_name', ARRAY_A );
        }

        wp_localize_script( 'bookly-customer.js', 'BooklyL10nCustomerDialog', Proxy\Shared::prepareL10n( array(
            'wpUsers' => $show_wp_users ? $wp_users : array(),
            'wpUsersRemote' => $show_wp_users ? $wp_users_remote : false,
            'moment_format_time' => Lib\Utils\DateTime::convertFormat( 'time', Lib\Utils\DateTime::FORMAT_MOMENT_JS ),
            'intlTelInput' => array(
                'enabled' => $tel_input_enabled,
                'country' => get_option( 'bookly_cst_phone_default_country' ),
            ),
            'datePicker' => Lib\Utils\DateTime::datePickerOptions( array(
                'yearRange' => sprintf( '%s:%s', date_create()->modify( '-100 years' )->format( 'Y' ), date( 'Y' ) ),
                'changeYear' => true,
            ) ),
            'fullName' => ! Lib\Config::showFirstLastName(),
            'l10n' => array(
                'editCustomer' => __( 'Edit customer', 'bookly-responsive-appointment-booking-tool' ),
                'newCustomer' => __( 'New customer', 'bookly-responsive-appointment-booking-tool' ),
                'selectUser' => __( 'User', 'bookly-responsive-appointment-booking-tool' ),
                'firstName' => __( 'First name', 'bookly-responsive-appointment-booking-tool' ),
                'lastName' => __( 'Last name', 'bookly-responsive-appointment-booking-tool' ),
                'fullName' => __( 'Full name', 'bookly-responsive-appointment-booking-tool' ),
                'phone' => __( 'Phone', 'bookly-responsive-appointment-booking-tool' ),
                'email' => __( 'Email', 'bookly-responsive-appointment-booking-tool' ),
                'notes' => __( 'Notes', 'bookly-responsive-appointment-booking-tool' ),
                'notes_help' => sprintf( __( 'This text can be inserted into notifications with %s code', 'bookly-responsive-appointment-booking-tool' ), '{client_note}' ),
                'save' => __( 'Save', 'bookly-responsive-appointment-booking-tool' ),
                'create' => __( 'Create', 'bookly-responsive-appointment-booking-tool' ),
                'cancel' => __( 'Cancel', 'bookly-responsive-appointment-booking-tool' ),
                'required' => __( 'Required', 'bookly-responsive-appointment-booking-tool' ),
                'no_result_found' => __( 'No results found', 'bookly-responsive-appointment-booking-tool' ),
                'searching' => __( 'Searching', 'bookly-responsive-appointment-booking-tool' ),
                'image' => __( 'Image', 'bookly-responsive-appointment-booking-tool' ),
                'delete' => __( 'Delete', 'bookly-responsive-appointment-booking-tool' )
            ),
        ) ) );
    }

    /**
     * Query for select WordPress users
     *
     * @return string
     */
    public static function getWPUsersQuery()
    {
        global $wpdb;

        $query = 'SELECT SQL_CALC_FOUND_ROWS ID, user_email, display_name, um.* FROM ' . $wpdb->users . ' AS u
            LEFT JOIN (
                SELECT user_id,
                GROUP_CONCAT( IF(meta_key = \'first_name\', meta_value, NULL) ) AS \'first_name\',
                GROUP_CONCAT( IF(meta_key = \'last_name\', meta_value, NULL) ) AS \'last_name\',
                GROUP_CONCAT( IF(meta_key = \'billing_phone\', meta_value, NULL) ) AS \'phone\'
                FROM ' . $wpdb->usermeta . ' GROUP BY user_id
            ) AS um ON (um.user_id = u.ID)';
        if ( is_multisite() ) {
            $query .= ' INNER JOIN ' . $wpdb->usermeta . ' AS usermeta ON ( u.ID = usermeta.user_id ) WHERE usermeta.meta_key = \'' . $wpdb->prefix . 'capabilities\'';
        }

        return $query;
    }
}