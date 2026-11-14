<?php
namespace Bookly\Lib\AiAdmin\Services;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\Format;
use Bookly\Lib\AiAdmin\Rules;
use Bookly\Lib\AiAdmin\State;

/**
 * Common part of the service actions: the service an item refers to (an id or "$N" of a service
 * created earlier in the same proposal) and its values after the earlier items.
 *
 * Values of a service live in State under "field:service.<key>#<id>", where <key> is a field of
 * set_service or one of staff_ids, steps, tags, schedule, special_days, preference_order; a value
 * not set by an earlier item is read from the database.
 */
abstract class ServiceAction
{
    /**
     * Name of the action (assets/ai-setup/actions/<name>.yaml).
     *
     * @return string
     */
    abstract protected function name();

    /**
     * "forbidden" error when the current user may not run the action, or null.
     *
     * @return string|null
     */
    protected function forbidden()
    {
        $actions = Rules::actions();
        $roles = isset( $actions[ $this->name() ]['roles'] ) ? $actions[ $this->name() ]['roles'] : array( 'admin' );

        return Access::allowed( $roles ) ? null : 'forbidden: the current user is not a Bookly administrator';
    }

    /**
     * Warnings of the action for the card (Rules::actions()[name]['warnings']).
     *
     * @param State $state
     * @return string[]
     */
    protected function actionWarnings( State $state )
    {
        $actions = Rules::actions();

        return isset( $actions[ $this->name() ] ) ? Format::warnings( $actions[ $this->name() ], null, null, $state ) : array();
    }

    /**
     * Number of the item being checked (from 1).
     *
     * @param State $state
     * @return int
     */
    public static function item( State $state )
    {
        return $state->item();
    }

    /**
     * Reference "$N" of the object the current item creates.
     *
     * @param State $state
     * @return string
     */
    public static function newRef( State $state )
    {
        return '$' . self::item( $state );
    }

    /**
     * Whether $id is an existing service or "$N" of a service created earlier.
     *
     * @param mixed $id
     * @param State $state
     * @return bool
     */
    public static function exists( $id, State $state )
    {
        if ( $state->isNew( $id ) === 'service' ) {
            return true;
        }

        return is_int( $id ) || ( is_string( $id ) && ctype_digit( $id ) ) ? ServiceData::row( $id ) !== null : false;
    }

    /**
     * Normalised id: int for an existing service, "$N" as is.
     *
     * @param mixed $id
     * @return int|string
     */
    public static function id( $id )
    {
        return is_string( $id ) && $id !== '' && $id[0] === '$' ? $id : (int) $id;
    }

    /**
     * Value of a service after the earlier items.
     *
     * @param State      $state
     * @param int|string $id
     * @param string     $key
     * @return mixed
     */
    public static function current( State $state, $id, $key )
    {
        $ref = 'field:service.' . $key . '#' . $id;
        if ( $state->has( $ref ) ) {
            return $state->get( $ref );
        }
        if ( ! is_int( $id ) ) {
            return null;
        }
        switch ( $key ) {
            case 'staff_ids':
                return ServiceData::staffIds( $id );
            case 'steps':
                return ServiceData::steps( $id );
            case 'schedule':
                return ServiceData::schedule( $id );
            case 'special_days':
                return ServiceData::specialDays( $id );
            case 'preference_order':
                return ServiceData::preferenceOrder( $id, self::current( $state, $id, 'staff_ids' ) );
        }
        $row = ServiceData::row( $id );
        if ( ! $row ) {
            return null;
        }
        if ( $key === 'tags' ) {
            return ServiceData::tags( $row );
        }
        $shape = ServiceData::shape( $row );

        return array_key_exists( $key, $shape ) ? $shape[ $key ] : null;
    }

    /**
     * @param State      $state
     * @param int|string $id
     * @param string     $key
     * @param mixed      $value
     */
    public static function setCurrent( State $state, $id, $key, $value )
    {
        $state->set( 'field:service.' . $key . '#' . $id, $value );
    }

    /**
     * Record all fields of a new service (create_service, duplicate_service).
     *
     * @param State  $state
     * @param string $ref "$N"
     * @param array  $shape fields of the service
     * @param array  $lists staff_ids, steps, tags, schedule, special_days
     */
    public static function setNew( State $state, $ref, array $shape, array $lists )
    {
        foreach ( $shape as $key => $value ) {
            self::setCurrent( $state, $ref, $key, $value );
        }
        foreach ( $lists as $key => $value ) {
            self::setCurrent( $state, $ref, $key, $value );
        }
        $count = (int) $state->get( 'count:services_created' );
        $state->set( 'count:services_created', $count + 1 );
    }

    /**
     * Reader for Constraints: field:service.<field> of this service (with the values of $after),
     * anything else from the state.
     *
     * @param State      $state
     * @param int|string $id
     * @param array      $after field => value after this item
     * @return \Closure
     */
    public static function reader( State $state, $id, array $after = array() )
    {
        return function ( $ref ) use ( $state, $id, $after ) {
            if ( preg_match( '/^field:service\.([a-z_]+)$/', $ref, $m ) ) {
                return array_key_exists( $m[1], $after ) ? $after[ $m[1] ] : ServiceAction::current( $state, $id, $m[1] );
            }

            return $state->get( $ref );
        };
    }

    /**
     * Title of a service for the card.
     *
     * @param State      $state
     * @param int|string $id
     * @return string
     */
    public static function title( State $state, $id )
    {
        $title = self::current( $state, $id, 'title' );

        return $title === null || $title === '' ? __( 'Untitled', 'bookly-responsive-appointment-booking-tool' ) : $title;
    }

    /**
     * Label of a card row: "<service title> › <label>".
     *
     * @param State      $state
     * @param int|string $id
     * @param string     $label
     * @return string
     */
    public static function rowLabel( State $state, $id, $label, array $options = array() )
    {
        return Format::rowLabel( self::cardObject( $state, $id ), $label, $options );
    }

    /**
     * The service as an object of the card (see Format::object()).
     *
     * @param State      $state
     * @param int|string $id
     * @param bool       $new the current item creates it ($state->isNew() covers earlier items only)
     * @return array
     */
    public static function cardObject( State $state, $id, $new = false )
    {
        return Format::object(
            'service:' . $id,
            self::title( $state, $id ),
            __( 'New service', 'bookly-responsive-appointment-booking-tool' ),
            $new || $state->isNew( $id ) === 'service'
        );
    }

    /**
     * Names of staff members, comma separated.
     *
     * @param int[] $ids
     * @return string
     */
    public static function staffNames( array $ids )
    {
        static $staff;
        if ( $staff === null ) {
            $staff = ServiceData::staff();
        }
        $names = array();
        foreach ( $ids as $id ) {
            $names[] = isset( $staff[ $id ] ) ? $staff[ $id ]['full_name'] : '#' . $id;
        }

        return $names ? implode( ', ', $names ) : '—';
    }

    /**
     * Title of a service by id or "$N" in the state.
     *
     * @param State      $state
     * @param int|string $id
     * @return string
     */
    public static function serviceTitle( State $state, $id )
    {
        return self::title( $state, self::id( $id ) );
    }

    /**
     * Insert a row of an entity table with its own id (restoring a deleted row on undo).
     *
     * @param string $entity_class
     * @param array  $row
     */
    public static function insertRow( $entity_class, array $row )
    {
        global $wpdb;

        $data = array();
        foreach ( array_keys( call_user_func( array( $entity_class, 'getSchema' ) ) ) as $field ) {
            if ( array_key_exists( $field, $row ) ) {
                $data[ $field ] = $row[ $field ];
            }
        }
        $wpdb->insert( call_user_func( array( $entity_class, 'getTableName' ) ), $data );
    }

    /**
     * Future appointments of a service (busy statuses) with their staff member and time.
     *
     * @param int        $service_id
     * @param int[]|null $staff_ids only of these staff members
     * @return array array( array( id, staff_id, start_date, end_date ) )
     */
    public static function futureAppointments( $service_id, $staff_ids = null )
    {
        $statuses = Lib\Proxy\CustomStatuses::prepareBusyStatuses( array(
            Lib\Entities\CustomerAppointment::STATUS_PENDING,
            Lib\Entities\CustomerAppointment::STATUS_APPROVED,
        ) );
        $query = Lib\Entities\Appointment::query( 'a' )
            ->select( 'DISTINCT a.id, a.staff_id, a.start_date, a.end_date' )
            ->innerJoin( 'CustomerAppointment', 'ca', 'ca.appointment_id = a.id' )
            ->where( 'a.service_id', $service_id )
            ->whereGte( 'a.start_date', current_time( 'mysql' ) )
            ->whereIn( 'ca.status', $statuses )
            ->sortBy( 'a.start_date' );
        if ( $staff_ids !== null ) {
            if ( ! $staff_ids ) {
                return array();
            }
            $query->whereIn( 'a.staff_id', $staff_ids );
        }

        return $query->fetchArray();
    }

    /**
     * Short list of appointment dates for the card: "2026-10-20 10:00, … (+N)".
     *
     * @param array $appointments
     * @return string
     */
    public static function appointmentsText( array $appointments )
    {
        $dates = array();
        foreach ( array_slice( $appointments, 0, 5 ) as $appointment ) {
            $dates[] = Lib\Utils\DateTime::formatDateTime( $appointment['start_date'] );
        }
        $more = count( $appointments ) - count( $dates );

        return implode( ', ', $dates ) . ( $more > 0 ? sprintf( ' (+%d)', $more ) : '' );
    }
}
