<?php
namespace Bookly\Backend\Modules\AiAdmin\Tools;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\AppointmentData;
use Bookly\Lib\AiAdmin\StaffData;

/**
 * get_staff_day({date, days, staff_ids, location_id}) — per day and staff member the working hours
 * that apply (special day, day off or weekly schedule, in that order, as the slot engine decides),
 * what holds their time (appointments in busy statuses, events) and the working time left, all
 * "HH:MM" in the site time zone. Reads the tables the slot engine reads; nothing is written.
 */
class GetStaffDay extends Tool
{
    const NAME = 'get_staff_day';

    /** @var array staff_id => special days by date (dayShape() + 'start' / 'end') */
    private $special_days = array();

    public function getName()
    {
        return self::NAME;
    }

    protected function description()
    {
        return 'Whether staff members work on given days, their hours, what is booked and the working time left.';
    }

    protected function parameters()
    {
        return array(
            'type'       => 'object',
            'properties' => array(
                'date'        => array( 'type' => 'string', 'description' => 'YYYY-MM-DD' ),
                'days'        => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 7 ),
                'staff_ids'   => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
                'location_id' => array( 'type' => 'integer' ),
            ),
            'required'   => array( 'date' ),
        );
    }

    public function execute( array $arguments )
    {
        if ( ! Access::allowed( array( 'admin' ) ) ) {
            return self::json( array( 'error' => 'forbidden' ) );
        }
        $date = isset( $arguments['date'] ) ? (string) $arguments['date'] : '';
        if ( ! Lib\Utils\DateTime::validateDate( $date ) ) {
            return self::json( array( 'error' => 'date is a date YYYY-MM-DD' ) );
        }
        $days        = isset( $arguments['days'] ) ? min( 7, max( 1, (int) $arguments['days'] ) ) : 1;
        $location_id = Lib\Config::locationsActive() && ! empty( $arguments['location_id'] ) ? (int) $arguments['location_id'] : null;

        $query = Lib\Entities\Staff::query()->sortBy( 'position, id' );
        if ( isset( $arguments['staff_ids'] ) && is_array( $arguments['staff_ids'] ) && $arguments['staff_ids'] ) {
            $query->whereIn( 'id', array_map( 'intval', array_values( $arguments['staff_ids'] ) ) );
        } else {
            $query->whereNot( 'visibility', 'archive' );
        }
        $scope = AppointmentData::staffScope();
        if ( $scope !== null ) {
            $query->whereIn( 'id', $scope );
        }
        /** @var Lib\Entities\Staff[] $members */
        $members = $query->find();

        $dates = array();
        for ( $i = 0; $i < $days; $i++ ) {
            $dates[] = date( 'Y-m-d', strtotime( $date . ' +' . $i . ' days' ) );
        }
        // One day around the period: night shifts and staff time zones move hours across midnight.
        $busy = self::busy(
            array_map( function ( $member ) { return (int) $member->getId(); }, $members ),
            date( 'Y-m-d', strtotime( $date . ' -1 day' ) ),
            date( 'Y-m-d', strtotime( $date . ' +' . ( $days + 1 ) . ' days' ) )
        );

        $result = array( 'time_zone' => AppointmentData::timeZone(), 'days' => array() );
        foreach ( $dates as $day ) {
            $staff = array();
            foreach ( $members as $member ) {
                $staff[] = $this->staffDay( $member, $day, $location_id, $busy );
            }
            $result['days'][] = array( 'date' => $day, 'staff' => $staff );
        }

        return self::json( $result );
    }

    /**
     * One staff member on one day.
     *
     * @param Lib\Entities\Staff $member
     * @param string             $date
     * @param int|null           $location_id
     * @param array              $busy busy() result
     * @return array
     */
    private function staffDay( Lib\Entities\Staff $member, $date, $location_id, array $busy )
    {
        $staff_id  = (int) $member->getId();
        $time_zone = AppointmentData::staffTimeZone( $member );
        $special   = $this->specialDay( $staff_id, $date, $location_id );
        if ( $special !== null ) {
            $hours      = $special;
            $hours_from = 'special_day';
        } elseif ( StaffData::isDayOff( $staff_id, $date ) ) {
            $hours      = 'off';
            $hours_from = 'day_off';
        } else {
            $hours      = self::weekday( $staff_id, $date, $location_id );
            $hours_from = 'schedule';
        }

        // Hours are kept in the staff member's time zone; seconds from the site's midnight of $date.
        $midnight = strtotime( $date . ' 00:00:00' );
        $shift    = $time_zone
            ? strtotime( Lib\Utils\DateTime::convertTimeZone( $date . ' 00:00:00', $member->getTimeZone(), AppointmentData::timeZone() ) ) - $midnight
            : 0;
        $work     = array();
        $window   = array( 0, DAY_IN_SECONDS );
        if ( is_array( $hours ) ) {
            $start = StaffData::seconds( $hours['start'] ) + $shift;
            $end   = StaffData::seconds( $hours['end'] ) + $shift;
            $work  = array( array( $start, $end ) );
            foreach ( $hours['breaks'] as $break ) {
                $work = self::subtract( $work, StaffData::seconds( $break['start'] ) + $shift, StaffData::seconds( $break['end'] ) + $shift );
            }
            $hours = array(
                'start'  => self::time( $start ),
                'end'    => self::time( $end ),
                'breaks' => array_map( function ( $break ) use ( $shift ) {
                    return array(
                        'start' => GetStaffDay::time( StaffData::seconds( $break['start'] ) + $shift ),
                        'end'   => GetStaffDay::time( StaffData::seconds( $break['end'] ) + $shift ),
                    );
                }, $hours['breaks'] ),
            );
            $window = array( min( 0, $start ), max( DAY_IN_SECONDS, $end ) );
        }

        $taken = array();
        $items = isset( $busy[ $staff_id ] ) ? $busy[ $staff_id ] : array();
        foreach ( $items as $item ) {
            $start = $item['start'] - $midnight;
            $end   = $item['end'] - $midnight;
            if ( $end <= $window[0] || $start >= $window[1] ) {
                continue;
            }
            $taken[] = array( $start, array_merge( array( 'start' => self::time( $start ), 'end' => self::time( $end ) ), $item['data'] ) );
            $work    = self::subtract( $work, $start, $end );
        }
        usort( $taken, function ( $a, $b ) {
            return $a[0] - $b[0];
        } );

        $free = array();
        foreach ( $work as $interval ) {
            $free[] = array( 'start' => self::time( $interval[0] ), 'end' => self::time( $interval[1] ) );
        }

        return array(
            'staff_id'        => $staff_id,
            'full_name'       => $member->getFullName(),
            'staff_time_zone' => $time_zone,
            'hours'           => $hours,
            'hours_from'      => $hours_from,
            'busy'            => array_map( function ( $item ) { return $item[1]; }, $taken ),
            'free'            => $free,
        );
    }

    /**
     * Special day with working hours on the date (Special Days add-on), in the shape of a
     * schedule day, or null. A special day without hours does not count, as in the slot engine.
     *
     * @param int      $staff_id
     * @param string   $date
     * @param int|null $location_id
     * @return array|null
     */
    private function specialDay( $staff_id, $date, $location_id )
    {
        if ( ! Lib\Config::specialDaysActive() ) {
            return null;
        }
        if ( ! isset( $this->special_days[ $staff_id ] ) ) {
            $own  = $location_id ? Lib\Proxy\Locations::prepareStaffSpecialDaysLocationId( $location_id, $staff_id ) : 0;
            $rows = \Bookly\Backend\Components\Dialogs\Staff\Edit\Proxy\SpecialDays::getStaffSpecialDays( $staff_id, $own ?: null );
            $this->special_days[ $staff_id ] = array();
            foreach ( is_array( $rows ) ? $rows : array() as $row ) {
                $day = substr( $row['date'], 0, 10 );
                if ( $row['start_time'] !== null && $row['start_time'] !== '' && ! isset( $this->special_days[ $staff_id ][ $day ] ) ) {
                    $this->special_days[ $staff_id ][ $day ] = StaffData::dayShape( $row['start_time'], $row['end_time'], $row['breaks'] );
                }
            }
        }

        return isset( $this->special_days[ $staff_id ][ $date ] ) ? $this->special_days[ $staff_id ][ $date ] : null;
    }

    /**
     * Weekly schedule's day of the date: the location's own schedule when the staff member has
     * one there (Locations add-on, custom settings per location), otherwise the main schedule.
     *
     * @param int      $staff_id
     * @param string   $date
     * @param int|null $location_id
     * @return array|string
     */
    private static function weekday( $staff_id, $date, $location_id )
    {
        $day_index = (int) date( 'w', strtotime( $date ) ) + 1;
        $own       = $location_id ? Lib\Proxy\Locations::prepareStaffScheduleLocationId( $location_id, $staff_id ) : 0;
        if ( ! $own ) {
            $rows = StaffData::scheduleRows( $staff_id );
            $row  = $rows[ $day_index ];

            return StaffData::dayShape( $row['start'], $row['end'], $row['breaks'] );
        }
        $item = Lib\Entities\StaffScheduleItem::query()
            ->where( 'staff_id', $staff_id )
            ->where( 'location_id', $own )
            ->where( 'day_index', $day_index )
            ->fetchRow();
        if ( ! $item ) {
            return 'off';
        }
        $breaks = Lib\Entities\ScheduleItemBreak::query()
            ->where( 'staff_schedule_item_id', $item['id'] )
            ->fetchArray();

        return StaffData::dayShape( $item['start_time'], $item['end_time'], $breaks );
    }

    /**
     * Appointments in statuses that hold time (and those without customers) and events of the
     * staff members between the dates: staff_id => list of array( start, end, data ), timestamps
     * of the site's wall time; the end of an appointment includes its extras.
     *
     * @param int[]  $staff_ids
     * @param string $from Y-m-d
     * @param string $to   Y-m-d, exclusive
     * @return array
     */
    private static function busy( array $staff_ids, $from, $to )
    {
        $busy = array();
        if ( ! $staff_ids ) {
            return $busy;
        }
        $statuses = AppointmentData::busyStatuses();
        $rows     = Lib\Entities\Appointment::query( 'a' )
            ->select( 'a.id, a.staff_id, a.start_date, a.end_date, a.extras_duration, GROUP_CONCAT(DISTINCT ca.status ORDER BY ca.status) AS statuses' )
            ->leftJoin( 'CustomerAppointment', 'ca', 'ca.appointment_id = a.id' )
            ->whereIn( 'a.staff_id', $staff_ids )
            ->whereNot( 'a.start_date', null )
            ->whereLt( 'a.start_date', $to . ' 00:00:00' )
            ->whereRaw( 'DATE_ADD(a.end_date, INTERVAL a.extras_duration SECOND) > %s', array( $from . ' 00:00:00' ) )
            ->whereRaw( 'ca.status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ') OR ca.status IS NULL', $statuses )
            ->groupBy( 'a.id' )
            ->fetchArray();
        foreach ( $rows as $row ) {
            $busy[ (int) $row['staff_id'] ][] = array(
                'start' => strtotime( $row['start_date'] ),
                'end'   => strtotime( $row['end_date'] ) + (int) $row['extras_duration'],
                'data'  => array(
                    'appointment_id' => (int) $row['id'],
                    'status'         => $row['statuses'] === null ? null : $row['statuses'],
                ),
            );
        }
        $events = Lib\Proxy\Events::getList( $staff_ids, date_create( $from ), date_create( $to ) ) ?: array();
        foreach ( $events as $event ) {
            $busy[ (int) $event['staff_id'] ][] = array(
                'start' => strtotime( $event['start_date'] ),
                'end'   => strtotime( $event['end_date'] ),
                'data'  => array( 'event_id' => isset( $event['id'] ) ? (int) $event['id'] : null ),
            );
        }

        return $busy;
    }

    /**
     * Intervals without [start, end).
     *
     * @param array $intervals list of array( start, end ), seconds
     * @param int   $start
     * @param int   $end
     * @return array
     */
    private static function subtract( array $intervals, $start, $end )
    {
        $result = array();
        foreach ( $intervals as $interval ) {
            if ( $end <= $interval[0] || $start >= $interval[1] ) {
                $result[] = $interval;
                continue;
            }
            if ( $start > $interval[0] ) {
                $result[] = array( $interval[0], $start );
            }
            if ( $end < $interval[1] ) {
                $result[] = array( $end, $interval[1] );
            }
        }

        return $result;
    }

    /**
     * "HH:MM" of seconds from midnight; over 24:00 — the next day, below 00:00 — the day before.
     *
     * @param int $seconds
     * @return string
     */
    public static function time( $seconds )
    {
        $sign    = $seconds < 0 ? '-' : '';
        $seconds = abs( $seconds );

        return sprintf( '%s%02d:%02d', $sign, floor( $seconds / HOUR_IN_SECONDS ), floor( $seconds % HOUR_IN_SECONDS / MINUTE_IN_SECONDS ) );
    }
}
