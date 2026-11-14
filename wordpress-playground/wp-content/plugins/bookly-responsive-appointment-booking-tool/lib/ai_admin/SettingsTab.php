<?php
namespace Bookly\Lib\AiAdmin;

use Bookly\Lib;
use Bookly\Backend\Modules\Settings\Page as SettingsPage;

/**
 * Saving options the way a tab of the Settings page saves them (set_option handler settings_tab):
 * the same code as the Save button — Settings\Page::saveTab(), that is the tab's own options,
 * then Proxy\Shared::saveSettings() of Pro and add-ons with their side effects
 * (AdvancedGoogleCalendar::preSaveSettings, sync and change notifications of calendars).
 *
 * The handlers write every field of the tab they read, a field missing in the request is saved
 * empty. So the request carries the current value of every Bookly option, the changes over them,
 * and the fields of the tab that are not options under their own names (colours by status,
 * Stripe metadata rows, Mailchimp field rows), rebuilt from the options they are saved to.
 */
abstract class SettingsTab
{
    /** Tabs whose form posts fields that cannot be rebuilt from options. */
    protected static $unsupported = array( 'user_permissions' );

    /**
     * Save changed options of one tab.
     *
     * @param string $tab     Settings tab, as in the tab parameter of the form
     * @param array  $changes option name => new value
     * @return string[] errors the handlers reported (the options are saved anyway, as on the page)
     * @throws \Exception when the tab cannot be saved this way
     */
    public static function save( $tab, array $changes )
    {
        if ( in_array( $tab, self::$unsupported, true ) ) {
            throw new \Exception( 'tab "' . $tab . '" cannot be saved by the assistant' );
        }
        $alert = SettingsPage::saveTab( $tab, self::params( $tab, $changes ) );

        return is_array( $alert ) && isset( $alert['error'] ) ? self::messages( $alert['error'] ) : array();
    }

    /**
     * Request of the tab's form: current values of all Bookly options, the changes, the tab's
     * own fields rebuilt from them.
     *
     * @param string $tab
     * @param array  $changes
     * @return array
     */
    protected static function params( $tab, array $changes )
    {
        global $wpdb;

        $params = array();
        $names  = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM `' . $wpdb->options . '` WHERE option_name LIKE %s', $wpdb->esc_like( 'bookly_' ) . '%' ) );
        foreach ( $names as $name ) {
            $params[ $name ] = get_option( $name );
        }
        foreach ( $changes as $name => $value ) {
            $params[ $name ] = $value;
        }
        $params['tab'] = $tab;

        switch ( $tab ) {
            case 'calendar':
                // Colours are posted as status[<status>]; custom statuses keep theirs in their own table.
                $statuses = Lib\Proxy\Shared::prepareColorsStatuses( array(
                    Lib\Entities\CustomerAppointment::STATUS_PENDING => get_option( 'bookly_appointment_status_pending_color' ),
                    Lib\Entities\CustomerAppointment::STATUS_APPROVED => get_option( 'bookly_appointment_status_approved_color' ),
                    Lib\Entities\CustomerAppointment::STATUS_CANCELLED => get_option( 'bookly_appointment_status_cancelled_color' ),
                    Lib\Entities\CustomerAppointment::STATUS_REJECTED => get_option( 'bookly_appointment_status_rejected_color' ),
                ) );
                if ( Lib\Config::proActive() ) {
                    $statuses['mixed'] = get_option( 'bookly_appointment_status_mixed_color' );
                }
                foreach ( array_keys( $statuses ) as $status ) {
                    $option = 'bookly_appointment_status_' . $status . '_color';
                    if ( array_key_exists( $option, $params ) ) {
                        $statuses[ $status ] = $params[ $option ];
                    }
                }
                $params['status'] = $statuses;
                break;
            case 'payments':
                // Metadata rows are posted as two parallel lists.
                $params['bookly_cloud_stripe_meta_name']  = array();
                $params['bookly_cloud_stripe_meta_value'] = array();
                if ( isset( $params['bookly_cloud_stripe_metadata'] ) && is_array( $params['bookly_cloud_stripe_metadata'] ) ) {
                    foreach ( $params['bookly_cloud_stripe_metadata'] as $row ) {
                        $params['bookly_cloud_stripe_meta_name'][]  = isset( $row['name'] ) ? $row['name'] : '';
                        $params['bookly_cloud_stripe_meta_value'][] = isset( $row['value'] ) ? $row['value'] : '';
                    }
                }
                break;
            case 'mailchimp':
                // Field mapping rows are posted as two parallel lists.
                $params['bookly_mailchimp_field_name']  = array();
                $params['bookly_mailchimp_field_value'] = array();
                if ( isset( $params['bookly_mailchimp_fields'] ) && is_array( $params['bookly_mailchimp_fields'] ) ) {
                    foreach ( $params['bookly_mailchimp_fields'] as $name => $value ) {
                        $params['bookly_mailchimp_field_name'][]  = $name;
                        $params['bookly_mailchimp_field_value'][] = $value;
                    }
                }
                break;
        }

        return $params;
    }

    /**
     * Error messages of an alert as plain strings.
     *
     * @param mixed $errors
     * @return string[]
     */
    protected static function messages( $errors )
    {
        $messages = array();
        foreach ( (array) $errors as $error ) {
            if ( is_array( $error ) ) {
                $messages = array_merge( $messages, self::messages( $error ) );
            } elseif ( (string) $error !== '' ) {
                $messages[] = wp_strip_all_tags( (string) $error );
            }
        }

        return $messages;
    }
}
