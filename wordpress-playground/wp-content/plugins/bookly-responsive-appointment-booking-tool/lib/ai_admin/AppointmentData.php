<?php
namespace Bookly\Lib\AiAdmin;

use Bookly\Lib;

/**
 * Appointment data of the setup assistant's read tools (find_appointments, get_appointment,
 * find_free_slots, get_staff_day): times "YYYY-MM-DD HH:MM" in the site time zone, durations in
 * seconds, money rounded to cents.
 */
abstract class AppointmentData
{
    /**
     * Staff members whose appointments the current user may read: null — every staff member.
     * Every read tool of appointments narrows its query by this list and nothing else.
     *
     * @return int[]|null
     */
    public static function staffScope()
    {
        return Lib\Utils\Common::getCurrentUserStaffIds();
    }

    /**
     * Whether the current user may read the appointments of a staff member.
     *
     * @param int $staff_id
     * @return bool
     */
    public static function staffAllowed( $staff_id )
    {
        $scope = self::staffScope();

        return $scope === null || in_array( (int) $staff_id, $scope, true );
    }

    /**
     * @return string
     */
    public static function timeZone()
    {
        return Lib\Config::getWPTimeZone();
    }

    /**
     * "YYYY-MM-DD HH:MM" of a stored datetime, or null.
     *
     * @param string|null $datetime
     * @return string|null
     */
    public static function minute( $datetime )
    {
        return $datetime === null || $datetime === '' ? null : substr( $datetime, 0, 16 );
    }

    /**
     * Start, end (extras included) and duration in seconds of an appointment row with start_date,
     * end_date and extras_duration; nulls for a task without a time.
     *
     * @param array $row
     * @return array array( start, end, duration )
     */
    public static function interval( array $row )
    {
        if ( $row['start_date'] === null ) {
            return array( null, null, null );
        }
        $start = strtotime( $row['start_date'] );
        $end   = strtotime( $row['end_date'] ) + (int) $row['extras_duration'];

        return array( self::minute( $row['start_date'] ), date( 'Y-m-d H:i', $end ), $end - $start );
    }

    /**
     * Payment of a booking: paid includes later payments (child_paid), due is never negative.
     *
     * @param array $row payment_id, payment_type, payment_status, payment_total, payment_paid, payment_child_paid
     * @return array|null
     */
    public static function payment( array $row )
    {
        if ( $row['payment_id'] === null || $row['payment_total'] === null ) {
            return null;
        }
        $paid = (float) $row['payment_paid'] + (float) $row['payment_child_paid'];

        return array(
            'id'     => (int) $row['payment_id'],
            'type'   => $row['payment_type'],
            'status' => $row['payment_status'],
            'total'  => round( (float) $row['payment_total'], 2 ),
            'paid'   => round( $paid, 2 ),
            'due'    => max( 0, round( (float) $row['payment_total'] - $paid, 2 ) ),
        );
    }

    /**
     * Columns payment() reads, for a query with Payment joined as p.
     *
     * @return string
     */
    public static function paymentColumns()
    {
        return 'p.id AS payment_id, p.type AS payment_type, p.status AS payment_status, p.total AS payment_total, p.paid AS payment_paid, p.child_paid AS payment_child_paid';
    }

    /**
     * Extras of a booking from its stored JSON {extra_id: quantity}: id, title, quantity.
     *
     * @param string|null $json
     * @return array
     */
    public static function extras( $json )
    {
        $quantities = json_decode( (string) $json, true );
        if ( ! is_array( $quantities ) || ! $quantities ) {
            return array();
        }
        $titles = array();
        foreach ( Lib\Proxy\ServiceExtras::findByIds( array_keys( $quantities ) ) ?: array() as $extra ) {
            $titles[ (int) $extra->getId() ] = $extra->getTitle();
        }
        $list = array();
        foreach ( $quantities as $id => $quantity ) {
            $list[] = array(
                'id'       => (int) $id,
                'title'    => isset( $titles[ (int) $id ] ) ? $titles[ (int) $id ] : null,
                'quantity' => (int) $quantity,
            );
        }

        return $list;
    }

    /**
     * Online meeting of an appointment: the provider and whether a link exists, never the link.
     *
     * @param string|null $provider
     * @param string|null $meeting_id
     * @return array|null
     */
    public static function onlineMeeting( $provider, $meeting_id )
    {
        if ( ! $provider ) {
            return null;
        }

        return array( 'provider' => $provider, 'link' => (string) $meeting_id !== '' );
    }

    /**
     * Where an appointment came from when it was not created in Bookly (google, outlook, apple).
     *
     * @param string $created_from
     * @return string|null
     */
    public static function importedFrom( $created_from )
    {
        return $created_from === 'bookly' || $created_from === null ? null : $created_from;
    }

    /**
     * Statuses whose bookings hold the staff member's time, as the slot search counts them.
     *
     * @return string[]
     */
    public static function busyStatuses()
    {
        $statuses = Lib\Proxy\CustomStatuses::prepareBusyStatuses( array(
            Lib\Entities\CustomerAppointment::STATUS_PENDING,
            Lib\Entities\CustomerAppointment::STATUS_APPROVED,
        ) );
        if ( Lib\Config::waitingListActive() ) {
            $statuses[] = Lib\Entities\CustomerAppointment::STATUS_WAITLISTED;
        }

        return $statuses;
    }

    /**
     * Time zone of a staff member's schedule, or null for the site's (no own zone or no Pro).
     *
     * @param Lib\Entities\Staff $staff
     * @return string|null
     */
    public static function staffTimeZone( Lib\Entities\Staff $staff )
    {
        return Lib\Config::proActive() && $staff->getTimeZone( false ) ? $staff->getTimeZone( false ) : null;
    }

    /**
     * Escaped LIKE pattern for a substring.
     *
     * @param string $text
     * @return string
     */
    public static function like( $text )
    {
        global $wpdb;

        return '%' . $wpdb->esc_like( $text ) . '%';
    }
}
