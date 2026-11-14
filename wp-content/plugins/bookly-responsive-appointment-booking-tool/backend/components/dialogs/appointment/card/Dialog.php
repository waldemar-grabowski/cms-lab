<?php
namespace Bookly\Backend\Components\Dialogs\Appointment\Card;

use Bookly\Lib;
use Bookly\Lib\Entities\CustomerAppointment;

/**
 * Appointment card — the view opened by a click on an appointment.
 *
 * Reads through the endpoints the edit form already uses; the classic form stays and is
 * opened from the card.
 */
class Dialog extends Lib\Base\Component
{
    /** How the card is arranged, per user — the same idea as table column settings. */
    const LAYOUT_META_KEY = 'bookly_appointment_card_layout';

    public static function render()
    {
        self::enqueueScripts( array(
            'module' => array( 'js/appointment-card.js' => array( 'bookly-backend-globals' ) ),
        ) );

        $statuses = array();
        foreach ( CustomerAppointment::getStatuses() as $status ) {
            $statuses[ $status ] = CustomerAppointment::statusToString( $status );
        }

        wp_localize_script( 'bookly-appointment-card.js', 'BooklyL10nAppointmentCard', array(
            // Which blocks and subfields the card may show at all. A block whose add-on is
            // missing is not rendered and gets no row in the settings dialog either.
            'addons' => array(
                'pro' => Lib\Config::proActive(),
                'locations' => Lib\Config::locationsActive(),
                'group-booking' => Lib\Config::groupBookingActive(),
                'packages' => Lib\Config::packagesActive(),
                'recurring-appointments' => Lib\Config::recurringAppointmentsActive(),
                'compound-services' => Lib\Config::compoundServicesActive(),
                'collaborative-services' => Lib\Config::collaborativeServicesActive(),
                'files' => Lib\Config::filesActive(),
                'service-extras' => Lib\Config::serviceExtrasActive(),
                'custom-fields' => Lib\Config::customFieldsActive(),
            ),
            // Status titles come from PHP so the Custom Statuses add-on has its say.
            'statuses' => $statuses,
            // Which statuses hold a seat. Not "everything except cancelled and rejected":
            // done and waitlisted hold none either, and the Custom Statuses add-on decides
            // for its own. Counting it here rather than in JS is what keeps the card's
            // seat figure equal to the one the booking wizard and the capacity check use.
            'busy_statuses' => array_values( Lib\Proxy\CustomStatuses::prepareBusyStatuses( array(
                CustomerAppointment::STATUS_PENDING,
                CustomerAppointment::STATUS_APPROVED,
            ) ) ),
            // Saved arrangement, or null for a card that was never arranged. It is passed
            // through as stored: reconciling it with the blocks this version knows is done
            // in JS, where the registry lives.
            'layout' => get_user_meta( get_current_user_id(), self::LAYOUT_META_KEY, true ) ?: null,
            // Last state of the notify switch, shared with the appointment form and the
            // booking wizard. Null — never touched, so the window picks its own default.
            'notify_default' => ( $notify_default = get_user_meta( get_current_user_id(), 'bookly_appointment_form_send_notifications', true ) ) === ''
                ? null
                : (int) $notify_default,
            'format_price' => Lib\Utils\Price::formatOptions(),
            'moment_format_date' => Lib\Utils\DateTime::convertFormat( 'date', Lib\Utils\DateTime::FORMAT_MOMENT_JS ),
            'moment_format_time' => Lib\Utils\DateTime::convertFormat( 'time', Lib\Utils\DateTime::FORMAT_MOMENT_JS ),
            'l10n' => array(
                'appointment' => __( 'Appointment', 'bookly-responsive-appointment-booking-tool' ),
                'card_settings' => __( 'Card settings', 'bookly-responsive-appointment-booking-tool' ),
                'close' => __( 'Close', 'bookly-responsive-appointment-booking-tool' ),
                'more_actions' => __( 'More actions', 'bookly-responsive-appointment-booking-tool' ),
                'edit_appointment' => __( 'Edit appointment', 'bookly-responsive-appointment-booking-tool' ),
                'delete_appointment' => __( 'Delete appointment', 'bookly-responsive-appointment-booking-tool' ),
                'reschedule_appointment' => __( 'Reschedule appointment', 'bookly-responsive-appointment-booking-tool' ),
                'reschedule_booking' => __( 'Reschedule booking', 'bookly-responsive-appointment-booking-tool' ),
                // An appointment without a time is not moved — it is given one.
                'set_time' => __( 'Set time', 'bookly-responsive-appointment-booking-tool' ),
                'edit_customer' => __( 'Edit customer', 'bookly-responsive-appointment-booking-tool' ),
                'add_customer' => __( 'Add customer', 'bookly-responsive-appointment-booking-tool' ),
                'remove_from_appointment' => __( 'Remove from appointment', 'bookly-responsive-appointment-booking-tool' ),
                'remove' => __( 'Remove', 'bookly-responsive-appointment-booking-tool' ),
                'remove_confirm' => __( 'Remove %s from this appointment?', 'bookly-responsive-appointment-booking-tool' ),
                'booking_removed' => __( 'Booking removed', 'bookly-responsive-appointment-booking-tool' ),
                'delete' => __( 'Delete', 'bookly-responsive-appointment-booking-tool' ),
                'delete_appointment_confirm' => __( 'Delete this appointment with everything booked in it?', 'bookly-responsive-appointment-booking-tool' ),
                // Deleting from the appointments list, where a row is a booking.
                'delete_booking_confirm' => __( 'Delete this booking?', 'bookly-responsive-appointment-booking-tool' ),
                'delete_bookings_confirm' => __( 'Delete the selected bookings?', 'bookly-responsive-appointment-booking-tool' ),
                'deleted' => __( 'Deleted', 'bookly-responsive-appointment-booking-tool' ),
                // The count is stated separately rather than inside the question: the
                // question would have to agree with the number, and the plural forms of a
                // number known only in the browser cannot be resolved here.
                'customers_count' => __( 'Customers: %s', 'bookly-responsive-appointment-booking-tool' ),
                'appointments_count' => __( 'Appointments: %s', 'bookly-responsive-appointment-booking-tool' ),
                'bookings_count' => __( 'Bookings: %s', 'bookly-responsive-appointment-booking-tool' ),
                // What a delete reaches beyond the one appointment — the same two questions
                // the booking wizard asks before it moves one, in the same words, so that an
                // operator who has moved a series already knows what the answers mean.
                'compound_of_n' => __( 'Compound service, %s stages', 'bookly-responsive-appointment-booking-tool' ),
                'collaborative_of_n' => __( 'Collaborative service, %s parts', 'bookly-responsive-appointment-booking-tool' ),
                'scope_stage' => __( 'This stage', 'bookly-responsive-appointment-booking-tool' ),
                'scope_cascade' => __( 'All stages', 'bookly-responsive-appointment-booking-tool' ),
                'series_of_n' => __( 'Series of %s', 'bookly-responsive-appointment-booking-tool' ),
                'scope_this' => __( 'This visit', 'bookly-responsive-appointment-booking-tool' ),
                'scope_next' => __( 'This and later', 'bookly-responsive-appointment-booking-tool' ),
                'scope_all' => __( 'Whole series', 'bookly-responsive-appointment-booking-tool' ),
                'appointment_deleted' => __( 'Appointment deleted', 'bookly-responsive-appointment-booking-tool' ),
                'change_status' => __( 'Change status', 'bookly-responsive-appointment-booking-tool' ),
                'send_notifications' => __( 'Send notifications', 'bookly-responsive-appointment-booking-tool' ),
                'reason_optional' => __( 'Reason (optional)', 'bookly-responsive-appointment-booking-tool' ),
                'reason_placeholder' => __( 'Will be added to the notification', 'bookly-responsive-appointment-booking-tool' ),
                'apply' => __( 'Apply', 'bookly-responsive-appointment-booking-tool' ),
                'status_changed' => __( 'Status changed', 'bookly-responsive-appointment-booking-tool' ),
                'edit_booking_details' => __( 'Edit booking details', 'bookly-responsive-appointment-booking-tool' ),
                'appointment_notes' => __( 'Appointment notes', 'bookly-responsive-appointment-booking-tool' ),
                'appointment_notes_help' => sprintf( __( 'This text can be inserted into notifications with %s code', 'bookly-responsive-appointment-booking-tool' ), '{appointment_notes}' ),
                'number_of_persons' => __( 'Number of persons', 'bookly-responsive-appointment-booking-tool' ),
                'timezone' => __( 'Timezone', 'bookly-responsive-appointment-booking-tool' ),
                'select_a_city' => __( 'Select a city', 'bookly-responsive-appointment-booking-tool' ),
                'custom_fields' => __( 'Custom fields', 'bookly-responsive-appointment-booking-tool' ),
                'extras' => __( 'Extras', 'bookly-responsive-appointment-booking-tool' ),
                'save_failed' => __( 'Could not save the booking', 'bookly-responsive-appointment-booking-tool' ),
                'remove_failed' => __( 'Could not remove the booking', 'bookly-responsive-appointment-booking-tool' ),
                'notifications_sent' => __( 'Notifications sent', 'bookly-responsive-appointment-booking-tool' ),
                'no_notifications_sent' => __( 'No notifications were sent', 'bookly-responsive-appointment-booking-tool' ),
                'actions_for' => __( 'Actions for %s', 'bookly-responsive-appointment-booking-tool' ),
                // Blocks and their subfields.
                'when' => __( 'When', 'bookly-responsive-appointment-booking-tool' ),
                'date' => __( 'Date', 'bookly-responsive-appointment-booking-tool' ),
                'time' => __( 'Time', 'bookly-responsive-appointment-booking-tool' ),
                // An appointment may have no time at all — that is how a task is stored.
                // The block answers in words rather than with an empty line.
                'no_time' => __( 'No time', 'bookly-responsive-appointment-booking-tool' ),
                'service' => __( 'Service', 'bookly-responsive-appointment-booking-tool' ),
                'service_name' => __( 'Service name', 'bookly-responsive-appointment-booking-tool' ),
                'duration' => __( 'Duration', 'bookly-responsive-appointment-booking-tool' ),
                'staff' => __( 'Staff', 'bookly-responsive-appointment-booking-tool' ),
                'location' => __( 'Location', 'bookly-responsive-appointment-booking-tool' ),
                'online_meeting' => __( 'Online meeting', 'bookly-responsive-appointment-booking-tool' ),
                'created' => __( 'Created', 'bookly-responsive-appointment-booking-tool' ),
                'internal_note' => __( 'Internal note', 'bookly-responsive-appointment-booking-tool' ),
                'edit_note' => __( 'Edit note', 'bookly-responsive-appointment-booking-tool' ),
                'add_a_note' => __( 'Add a note', 'bookly-responsive-appointment-booking-tool' ),
                'internal_note_help' => sprintf( __( 'This text can be inserted into notifications with %s code', 'bookly-responsive-appointment-booking-tool' ), '{internal_note}' ),
                'customers' => __( 'Customers', 'bookly-responsive-appointment-booking-tool' ),
                'seats_summary' => __( 'Seats summary', 'bookly-responsive-appointment-booking-tool' ),
                'name' => __( 'Name', 'bookly-responsive-appointment-booking-tool' ),
                'phone' => __( 'Phone', 'bookly-responsive-appointment-booking-tool' ),
                'email' => __( 'Email', 'bookly-responsive-appointment-booking-tool' ),
                'seats' => __( 'Seats', 'bookly-responsive-appointment-booking-tool' ),
                'status' => __( 'Status', 'bookly-responsive-appointment-booking-tool' ),
                'payment' => __( 'Payment', 'bookly-responsive-appointment-booking-tool' ),
                'package' => __( 'Package', 'bookly-responsive-appointment-booking-tool' ),
                'series' => __( 'Series', 'bookly-responsive-appointment-booking-tool' ),
                'compound_service' => __( 'Compound service', 'bookly-responsive-appointment-booking-tool' ),
                'collaborative_service' => __( 'Collaborative service', 'bookly-responsive-appointment-booking-tool' ),
                'attachments' => __( 'Attachments', 'bookly-responsive-appointment-booking-tool' ),
                // Chips of a booking row.
                'one_seat' => __( '1 seat', 'bookly-responsive-appointment-booking-tool' ),
                'n_seats' => __( '%s seats', 'bookly-responsive-appointment-booking-tool' ),
                'n_of_m_seats' => __( '%1$s of %2$s seats', 'bookly-responsive-appointment-booking-tool' ),
                'of' => __( 'of', 'bookly-responsive-appointment-booking-tool' ),
                'attach_payment' => __( 'Attach payment', 'bookly-responsive-appointment-booking-tool' ),
                'payment_details' => __( 'Payment details', 'bookly-responsive-appointment-booking-tool' ),
                'attach_package' => __( 'Attach package', 'bookly-responsive-appointment-booking-tool' ),
                'package_schedule' => __( 'Package schedule', 'bookly-responsive-appointment-booking-tool' ),
                'view_series' => __( 'View series', 'bookly-responsive-appointment-booking-tool' ),
                'files' => __( 'Files', 'bookly-responsive-appointment-booking-tool' ),
                'part_of_compound' => __( 'Part of compound service', 'bookly-responsive-appointment-booking-tool' ),
                'part_of_collaborative' => __( 'Part of collaborative service', 'bookly-responsive-appointment-booking-tool' ),
                'show_titles' => __( 'Show titles', 'bookly-responsive-appointment-booking-tool' ),
                'compact_rows' => __( 'Compact rows', 'bookly-responsive-appointment-booking-tool' ),
                'preview' => __( 'Preview', 'bookly-responsive-appointment-booking-tool' ),
                'reset_to_defaults' => __( 'Reset to defaults', 'bookly-responsive-appointment-booking-tool' ),
                'save' => __( 'Save', 'bookly-responsive-appointment-booking-tool' ),
                'cancel' => __( 'Cancel', 'bookly-responsive-appointment-booking-tool' ),
                'drag_a_block_here' => __( 'Drag a block here', 'bookly-responsive-appointment-booking-tool' ),
                'no_data' => __( 'no data', 'bookly-responsive-appointment-booking-tool' ),
                'toggle_x' => __( 'Toggle %s', 'bookly-responsive-appointment-booking-tool' ),
                'drag_x_to_reorder' => __( 'Drag %s to reorder', 'bookly-responsive-appointment-booking-tool' ),
                'reorder_x_within_block' => __( 'Reorder %s within the block', 'bookly-responsive-appointment-booking-tool' ),
            ),
        ) );
    }

    /**
     * Keep only what a layout is made of, without judging the blocks themselves.
     *
     * Block ids are not checked against a list here on purpose: the list of blocks lives
     * in JS, add-ons extend it, and a block unknown to this version of the plugin may well
     * be known to the next one. What is enforced is the shape — zones hold lists of block
     * ids, the rest are maps — so that no arbitrary structure lands in user meta.
     *
     * @param array $layout
     * @return array
     */
    public static function sanitizeLayout( $layout )
    {
        $layout = (array) $layout;
        $result = array();

        foreach ( array( 'main', 'facts', 'band', 'roster' ) as $zone ) {
            $result[ $zone ] = array();
            if ( isset( $layout[ $zone ] ) && is_array( $layout[ $zone ] ) ) {
                foreach ( $layout[ $zone ] as $id ) {
                    if ( is_string( $id ) || is_numeric( $id ) ) {
                        $result[ $zone ][] = sanitize_key( $id );
                    }
                }
            }
        }

        $result['titles'] = ! empty( $layout['titles'] );
        $result['compact'] = ! empty( $layout['compact'] );

        // Which blocks are switched off: block id → bool.
        $result['visible'] = array();
        if ( isset( $layout['visible'] ) && is_array( $layout['visible'] ) ) {
            foreach ( $layout['visible'] as $id => $on ) {
                $result['visible'][ sanitize_key( $id ) ] = (bool) $on;
            }
        }

        // Per-block subfield settings: block id → ( subfield → bool ) for `subfields`,
        // block id → list of subfields for `subfieldOrder`, block id → ( subfield → slot )
        // for `subfieldSlot`.
        $result['subfields'] = array();
        if ( isset( $layout['subfields'] ) && is_array( $layout['subfields'] ) ) {
            foreach ( $layout['subfields'] as $id => $fields ) {
                $result['subfields'][ sanitize_key( $id ) ] = array();
                foreach ( (array) $fields as $name => $on ) {
                    $result['subfields'][ sanitize_key( $id ) ][ sanitize_key( $name ) ] = (bool) $on;
                }
            }
        }

        $result['subfieldOrder'] = array();
        if ( isset( $layout['subfieldOrder'] ) && is_array( $layout['subfieldOrder'] ) ) {
            foreach ( $layout['subfieldOrder'] as $id => $order ) {
                $result['subfieldOrder'][ sanitize_key( $id ) ] = array_map( 'sanitize_key', (array) $order );
            }
        }

        $result['subfieldSlot'] = array();
        if ( isset( $layout['subfieldSlot'] ) && is_array( $layout['subfieldSlot'] ) ) {
            foreach ( $layout['subfieldSlot'] as $id => $slots ) {
                $result['subfieldSlot'][ sanitize_key( $id ) ] = array();
                foreach ( (array) $slots as $name => $slot ) {
                    $result['subfieldSlot'][ sanitize_key( $id ) ][ sanitize_key( $name ) ] = sanitize_key( $slot );
                }
            }
        }

        return $result;
    }
}
