<?php
namespace Bookly\Backend\Components\Dialogs\Appointment\Edit;

use Bookly\Lib;
use Bookly\Lib\Entities\CustomerAppointment;

class Dialog extends Lib\Base\Component
{
    /**
     * Render create/edit appointment dialog.
     *
     * @param bool $show_wp_users
     */
    public static function render( $show_wp_users = true )
    {
        self::enqueueStyles( array(
            'backend' => array( 'css/fontawesome-all.min.css' => array( 'bookly-backend-globals' ) ),
        ) );

        self::enqueueScripts( array(
            'module' => array( 'js/appointment.js' => array( 'bookly-backend-globals' ) ),
        ) );

        self::enqueueData( array(
            'extras_list',
            'extras_multiply_nop',
        ), 'bookly-appointment.js' );

        $statuses = array();
        foreach ( CustomerAppointment::getStatuses() as $status ) {
            $statuses[] = array(
                'id' => $status,
                'title' => CustomerAppointment::statusToString( $status ),
                'icon' => CustomerAppointment::statusToIcon( $status ),
            );
        }

        wp_localize_script( 'bookly-appointment.js', 'BooklyL10nAppDialog', Proxy\Shared::prepareL10n( array(
            'statuses' => $statuses,
            'freeStatuses' => Lib\Proxy\CustomStatuses::prepareFreeStatuses( array(
                CustomerAppointment::STATUS_CANCELLED,
                CustomerAppointment::STATUS_REJECTED,
                CustomerAppointment::STATUS_WAITLISTED,
                CustomerAppointment::STATUS_DONE,
            ) ),
            'send_notifications' => (int) get_user_meta( get_current_user_id(), 'bookly_appointment_form_send_notifications', true ),
            'appropriate_slots' => get_option( 'bookly_appointments_displayed_time_slots', 'all' ) === 'appropriate',
            'service_main' => get_option( 'bookly_appointments_main_value', 'all' ) === 'service',
            'l10n' => array(
                'edit_appointment' => __( 'Edit appointment', 'bookly-responsive-appointment-booking-tool' ),
                'new_appointment' => __( 'New appointment', 'bookly-responsive-appointment-booking-tool' ),
                'open_booking_wizard' => __( 'Open booking wizard', 'bookly-responsive-appointment-booking-tool' ),
                'send_notifications' => __( 'Send notifications', 'bookly-responsive-appointment-booking-tool' ),
                'provider' => __( 'Provider', 'bookly-responsive-appointment-booking-tool' ),
                'service' => __( 'Service', 'bookly-responsive-appointment-booking-tool' ),
                'select_a_service' => __( '-- Select a service --', 'bookly-responsive-appointment-booking-tool' ),
                'location' => __( 'Location', 'bookly-responsive-appointment-booking-tool' ),
                'staff_any' => __( 'Any', 'bookly-responsive-appointment-booking-tool' ),
                'date' => __( 'Date', 'bookly-responsive-appointment-booking-tool' ),
                'period' => __( 'Period', 'bookly-responsive-appointment-booking-tool' ),
                'to' => __( 'to', 'bookly-responsive-appointment-booking-tool' ),
                'customers' => __( 'Customers', 'bookly-responsive-appointment-booking-tool' ),
                'selected_maximum' => __( 'Selected / maximum', 'bookly-responsive-appointment-booking-tool' ),
                'minimum_capacity' => __( 'Minimum capacity', 'bookly-responsive-appointment-booking-tool' ),
                'edit_booking_details' => __( 'Edit booking details', 'bookly-responsive-appointment-booking-tool' ),
                'status' => __( 'Status', 'bookly-responsive-appointment-booking-tool' ),
                'payment' => __( 'Payment', 'bookly-responsive-appointment-booking-tool' ),
                'remove_customer' => __( 'Remove customer', 'bookly-responsive-appointment-booking-tool' ),
                'search_customers' => __( '-- Search customers --', 'bookly-responsive-appointment-booking-tool' ),
                'new_customer' => __( 'New customer', 'bookly-responsive-appointment-booking-tool' ),
                'no_result_found' => __( 'No results found', 'bookly-responsive-appointment-booking-tool' ),
                'searching' => __( 'Searching', 'bookly-responsive-appointment-booking-tool' ),
                'save' => __( 'Save', 'bookly-responsive-appointment-booking-tool' ),
                'create' => __( 'Create', 'bookly-responsive-appointment-booking-tool' ),
                'close' => __( 'Close', 'bookly-responsive-appointment-booking-tool' ),
                'internal_note' => __( 'Internal note', 'bookly-responsive-appointment-booking-tool' ),
                'chose_queue_type_info' => __( 'If you have added a new customer to this appointment or changed the appointment status for an existing customer, and for these records you want the corresponding email or SMS notifications to be sent to their recipients, select the "Send if new or status changed" option before clicking Send. You can also send notifications as if all customers were added as new by selecting "Send as for new".', 'bookly-responsive-appointment-booking-tool' ),
                'send_if_new_or_status_changed' => __( 'Send if new or status changed', 'bookly-responsive-appointment-booking-tool' ),
                'send_as_for_new' => __( 'Send as for new', 'bookly-responsive-appointment-booking-tool' ),
                'send' => __( 'Send', 'bookly-responsive-appointment-booking-tool' ),
                'view' => __( 'View', 'bookly-responsive-appointment-booking-tool' ),
                'internal_note_help' => sprintf( __( 'This text can be inserted into notifications with %s code', 'bookly-responsive-appointment-booking-tool' ), '{internal_note}' ),
                'notices' => array(
                    'service_required' => __( 'Please select a service', 'bookly-responsive-appointment-booking-tool' ),
                    'provider_required' => __( 'Please select a provider', 'bookly-responsive-appointment-booking-tool' ),
                    'date_interval_not_available' => __( 'The selected period is occupied by another appointment', 'bookly-responsive-appointment-booking-tool' ),
                    'date_interval_warning' => __( 'Selected period doesn\'t match service duration', 'bookly-responsive-appointment-booking-tool' ),
                    'interval_not_in_staff_schedule' => __( 'Selected period doesn\'t match provider\'s schedule', 'bookly-responsive-appointment-booking-tool' ),
                    'no_timeslots_available' => __( 'No timeslots available', 'bookly-responsive-appointment-booking-tool' ),
                ),
            ),
        ) ) );

        self::renderTemplate( 'edit', compact( 'show_wp_users' ) );
    }
}