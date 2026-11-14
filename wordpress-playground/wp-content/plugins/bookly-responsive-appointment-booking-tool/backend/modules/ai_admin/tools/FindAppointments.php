<?php
namespace Bookly\Backend\Modules\AiAdmin\Tools;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\AppointmentData;

/**
 * find_appointments(filters) — bookings as the Appointments page lists them, one row per customer
 * of an appointment (an appointment without customers is one row), sorted by start, site time zone.
 */
class FindAppointments extends Tool
{
    const NAME = 'find_appointments';

    /** Rows returned at most. */
    const MAX = 200;

    /** Rows returned without a limit. */
    const DEFAULT_LIMIT = 50;

    public function getName()
    {
        return self::NAME;
    }

    protected function description()
    {
        return 'Bookings as the Appointments page lists them, one row per customer of an appointment, with its filters.';
    }

    protected function parameters()
    {
        $ints  = array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) );
        $dates = array( 'type' => 'string', 'description' => 'YYYY-MM-DD' );

        return array(
            'type'       => 'object',
            'properties' => array(
                'staff_id'     => array( 'type' => 'integer' ),
                'staff_ids'    => $ints,
                'from'         => $dates,
                'to'           => $dates,
                'timeless'     => array( 'type' => 'boolean' ),
                'service_ids'  => $ints,
                'customer_id'  => array( 'type' => 'integer' ),
                'location_ids' => $ints,
                'status'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
                'created_from' => $dates,
                'created_to'   => $dates,
                'source'       => array( 'type' => 'string', 'enum' => array( 'frontend', 'backend' ) ),
                'search'       => array( 'type' => 'string' ),
                'limit'        => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX ),
            ),
        );
    }

    public function execute( array $arguments )
    {
        if ( ! Access::allowed( array( 'admin' ) ) ) {
            return self::json( array( 'error' => 'forbidden' ) );
        }
        foreach ( array( 'from', 'to', 'created_from', 'created_to' ) as $key ) {
            if ( isset( $arguments[ $key ] ) && $arguments[ $key ] !== '' && ! Lib\Utils\DateTime::validateDate( (string) $arguments[ $key ] ) ) {
                return self::json( array( 'error' => $key . ' is a date YYYY-MM-DD' ) );
            }
        }
        if ( isset( $arguments['source'] ) && $arguments['source'] !== '' && ! in_array( $arguments['source'], array( 'frontend', 'backend' ), true ) ) {
            return self::json( array( 'error' => 'source is frontend or backend' ) );
        }

        global $wpdb;
        $wpdb->query( 'SET SQL_BIG_SELECTS=1' );

        $query = Lib\Entities\Appointment::query( 'a' )
            ->select( 'a.id, a.staff_id, a.staff_any, a.service_id, a.location_id, a.start_date, a.end_date, a.extras_duration,
                a.internal_note, a.online_meeting_provider, a.online_meeting_id, a.created_from AS imported_from,
                COALESCE(s.title, a.custom_service_name) AS service,
                st.full_name AS staff, st.visibility AS staff_visibility,
                ca.id AS ca_id, ca.customer_id, ca.status, ca.number_of_persons, ca.units, ca.extras, ca.notes,
                ca.series_id, ca.package_id, ca.rating, ca.created_from AS source,
                COALESCE(ca.created_at, a.created_at) AS created_date,
                c.full_name AS customer, c.phone AS customer_phone, c.email AS customer_email, '
                . AppointmentData::paymentColumns() )
            ->leftJoin( 'CustomerAppointment', 'ca', 'ca.appointment_id = a.id' )
            ->leftJoin( 'Service', 's', 's.id = a.service_id' )
            ->leftJoin( 'Customer', 'c', 'c.id = ca.customer_id' )
            ->leftJoin( 'Payment', 'p', 'p.id = ca.payment_id' )
            ->leftJoin( 'Staff', 'st', 'st.id = a.staff_id' );

        $scope = AppointmentData::staffScope();
        if ( $scope !== null ) {
            $query->whereIn( 'a.staff_id', $scope );
        }
        self::applyFilters( $query, $arguments );

        $total = (int) $query->count();
        $limit = isset( $arguments['limit'] ) ? max( 1, min( self::MAX, (int) $arguments['limit'] ) ) : self::DEFAULT_LIMIT;
        $rows  = $query
            ->sortBy( 'a.start_date, a.id, ca.id' )
            ->limit( $limit )
            ->fetchArray();

        $locations = Lib\Config::locationsActive();
        $ratings   = Lib\Config::ratingsActive();
        $bookings  = array();
        foreach ( $rows as $row ) {
            list( $start, $end, $duration ) = AppointmentData::interval( $row );
            $has_customer = $row['ca_id'] !== null;
            $booking      = array(
                'id'             => (int) $row['id'],
                'booking_id'     => $has_customer ? (int) $row['ca_id'] : null,
                'start'          => $start,
                'end'            => $end,
                'duration'       => $duration,
                'service'        => $row['service'],
                'service_id'     => $row['service_id'] !== null ? (int) $row['service_id'] : null,
                'staff'          => $row['staff'],
                'staff_id'       => (int) $row['staff_id'],
                'staff_archived' => $row['staff_visibility'] === 'archive',
                'staff_any'      => (bool) $row['staff_any'],
            );
            if ( $locations ) {
                $booking['location_id'] = $row['location_id'] !== null ? (int) $row['location_id'] : null;
            }
            $booking += array(
                'customer'       => $has_customer ? $row['customer'] : null,
                'customer_id'    => $has_customer ? (int) $row['customer_id'] : null,
                'customer_phone' => $has_customer ? (string) $row['customer_phone'] : null,
                'customer_email' => $has_customer ? (string) $row['customer_email'] : null,
                'persons'        => $has_customer ? (int) $row['number_of_persons'] : null,
                'units'          => $has_customer ? (int) $row['units'] : null,
                'status'         => $has_customer ? $row['status'] : null,
                'extras'         => $has_customer ? AppointmentData::extras( $row['extras'] ) : array(),
                'payment'        => $has_customer ? AppointmentData::payment( $row ) : null,
                'notes'          => $has_customer ? (string) $row['notes'] : null,
                'internal_note'  => (string) $row['internal_note'],
                'series_id'      => $row['series_id'] !== null ? (int) $row['series_id'] : null,
                'package_id'     => $row['package_id'] !== null ? (int) $row['package_id'] : null,
                'online_meeting' => AppointmentData::onlineMeeting( $row['online_meeting_provider'], $row['online_meeting_id'] ),
            );
            if ( $ratings ) {
                $booking['rating'] = $row['rating'] !== null ? (int) $row['rating'] : null;
            }
            $booking += array(
                'created_at'    => AppointmentData::minute( $row['created_date'] ),
                'source'        => $has_customer ? $row['source'] : null,
                'imported_from' => AppointmentData::importedFrom( $row['imported_from'] ),
            );
            $bookings[] = $booking;
        }

        return self::json( array(
            'total_found' => $total,
            'returned'    => count( $bookings ),
            'time_zone'   => AppointmentData::timeZone(),
            'bookings'    => $bookings,
        ) );
    }

    /**
     * Filters of the arguments, combined with AND, as the Appointments page applies its own.
     *
     * @param Lib\Query $query
     * @param array     $arguments
     */
    private static function applyFilters( Lib\Query $query, array $arguments )
    {
        if ( ! empty( $arguments['timeless'] ) ) {
            $query->where( 'a.start_date', null );
        } else {
            $from = ! empty( $arguments['from'] ) ? $arguments['from'] : current_time( 'Y-m-d' );
            $to   = ! empty( $arguments['to'] ) ? $arguments['to'] : date( 'Y-m-d', strtotime( $from . ' +7 days' ) );
            $query
                ->whereGte( 'a.start_date', $from . ' 00:00:00' )
                ->whereLt( 'a.start_date', date( 'Y-m-d', strtotime( $to . ' +1 day' ) ) . ' 00:00:00' );
        }

        $staff_ids = self::ints( $arguments, 'staff_ids' );
        if ( ! empty( $arguments['staff_id'] ) ) {
            $staff_ids[] = (int) $arguments['staff_id'];
        }
        if ( $staff_ids ) {
            $query->whereIn( 'a.staff_id', array_values( array_unique( $staff_ids ) ) );
        }

        self::whereIdsOrNull( $query, 'a.service_id', self::ints( $arguments, 'service_ids' ) );
        self::whereIdsOrNull( $query, 'a.location_id', self::ints( $arguments, 'location_ids' ) );

        if ( ! empty( $arguments['customer_id'] ) ) {
            $query->where( 'ca.customer_id', (int) $arguments['customer_id'] );
        }
        if ( ! empty( $arguments['status'] ) && is_array( $arguments['status'] ) ) {
            $query->whereIn( 'ca.status', array_map( 'strval', array_values( $arguments['status'] ) ) );
        }
        if ( ! empty( $arguments['created_from'] ) ) {
            $query->whereGte( 'COALESCE(ca.created_at, a.created_at)', $arguments['created_from'] . ' 00:00:00' );
        }
        if ( ! empty( $arguments['created_to'] ) ) {
            $query->whereLt( 'COALESCE(ca.created_at, a.created_at)', date( 'Y-m-d', strtotime( $arguments['created_to'] . ' +1 day' ) ) . ' 00:00:00' );
        }
        if ( ! empty( $arguments['source'] ) ) {
            $query->where( 'ca.created_from', $arguments['source'] );
        }
        if ( isset( $arguments['search'] ) && is_string( $arguments['search'] ) && trim( $arguments['search'] ) !== '' ) {
            $like = AppointmentData::like( trim( $arguments['search'] ) );
            $query->whereRaw(
                'a.id LIKE %s OR ca.id LIKE %s OR c.full_name LIKE %s OR c.phone LIKE %s OR c.email LIKE %s OR st.full_name LIKE %s OR COALESCE(s.title, a.custom_service_name) LIKE %s',
                array( $like, $like, $like, $like, $like, $like, $like )
            );
        }
    }

    /**
     * Integer list of an argument.
     *
     * @param array  $arguments
     * @param string $key
     * @return int[]
     */
    private static function ints( array $arguments, $key )
    {
        return isset( $arguments[ $key ] ) && is_array( $arguments[ $key ] ) ? array_map( 'intval', array_values( $arguments[ $key ] ) ) : array();
    }

    /**
     * Column in the ids, where id 0 stands for NULL (Custom service, no location).
     *
     * @param Lib\Query $query
     * @param string    $column
     * @param int[]     $ids
     */
    private static function whereIdsOrNull( Lib\Query $query, $column, array $ids )
    {
        if ( ! $ids ) {
            return;
        }
        $positive = array_values( array_unique( array_filter( $ids, function ( $id ) { return $id > 0; } ) ) );
        $null     = in_array( 0, $ids, true );
        if ( $null && $positive ) {
            $query->whereRaw( $column . ' IS NULL OR ' . $column . ' IN (' . implode( ',', $positive ) . ')', array() );
        } elseif ( $null ) {
            $query->where( $column, null );
        } else {
            $query->whereIn( $column, $positive );
        }
    }
}
