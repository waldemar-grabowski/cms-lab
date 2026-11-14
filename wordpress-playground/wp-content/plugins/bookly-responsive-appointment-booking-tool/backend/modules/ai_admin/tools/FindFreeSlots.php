<?php
namespace Bookly\Backend\Modules\AiAdmin\Tools;

use Bookly\Backend\Modules\AiAdmin\WizardSlots;
use Bookly\Lib;
use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\AppointmentData;
use Bookly\Lib\Slots\DatePoint;

/**
 * find_free_slots(service_id | duration, …) — free start times as the admin's New appointment
 * wizard finds them, per day and staff member, in the site time zone; runs of starts every
 * step_minutes are written as one range "10:00-12:30".
 */
class FindFreeSlots extends Tool
{
    const NAME = 'find_free_slots';

    public function getName()
    {
        return self::NAME;
    }

    protected function description()
    {
        return 'Free start times for a service as the New appointment wizard finds them, per day and staff member.';
    }

    protected function parameters()
    {
        return array(
            'type'       => 'object',
            'properties' => array(
                'service_id'  => array( 'type' => 'integer' ),
                'duration'    => array( 'type' => 'integer', 'minimum' => 60, 'maximum' => 86400, 'description' => 'Seconds, for an own (Custom) service.' ),
                'date_from'   => array( 'type' => 'string', 'description' => 'YYYY-MM-DD' ),
                'days'        => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 7 ),
                'staff_ids'   => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
                'location_id' => array( 'type' => 'integer' ),
                'persons'     => array( 'type' => 'integer', 'minimum' => 1 ),
                'units'       => array( 'type' => 'integer', 'minimum' => 1 ),
                'extras'      => array( 'type' => 'object', 'additionalProperties' => array( 'type' => 'integer' ) ),
                'time_from'   => array( 'type' => 'string', 'description' => 'HH:MM' ),
                'time_to'     => array( 'type' => 'string', 'description' => 'HH:MM' ),
            ),
        );
    }

    public function execute( array $arguments )
    {
        if ( ! Access::allowed( array( 'admin' ) ) ) {
            return self::json( array( 'error' => 'forbidden' ) );
        }
        $service_id = isset( $arguments['service_id'] ) ? (int) $arguments['service_id'] : 0;
        $duration   = isset( $arguments['duration'] ) ? min( DAY_IN_SECONDS, max( 60, (int) $arguments['duration'] ) ) : 0;
        $service    = $service_id ? Lib\Entities\Service::find( $service_id ) : null;
        if ( $service_id ? ! $service : ! $duration ) {
            return self::json( array( 'not_found' => 'service' ) );
        }
        $date_from = isset( $arguments['date_from'] ) && $arguments['date_from'] !== '' ? (string) $arguments['date_from'] : current_time( 'Y-m-d' );
        if ( ! Lib\Utils\DateTime::validateDate( $date_from ) ) {
            return self::json( array( 'error' => 'date_from is a date YYYY-MM-DD' ) );
        }
        foreach ( array( 'time_from', 'time_to' ) as $key ) {
            if ( isset( $arguments[ $key ] ) && $arguments[ $key ] !== '' && ! preg_match( '/^\d{1,2}:\d{2}$/', $arguments[ $key ] ) ) {
                return self::json( array( 'error' => $key . ' is a time HH:MM' ) );
            }
        }
        $extras = array();
        if ( isset( $arguments['extras'] ) && is_array( $arguments['extras'] ) ) {
            foreach ( $arguments['extras'] as $extra_id => $quantity ) {
                $extras[ (int) $extra_id ] = (int) $quantity;
            }
        }
        $staff_ids = isset( $arguments['staff_ids'] ) && is_array( $arguments['staff_ids'] ) ? array_map( 'intval', array_values( $arguments['staff_ids'] ) ) : array();
        $units     = isset( $arguments['units'] ) ? max( 1, (int) $arguments['units'] ) : 1;

        $time_zone = AppointmentData::timeZone();
        $client_tz = DatePoint::$client_timezone;
        $found     = WizardSlots::find( array(
            'client_time_zone'    => $time_zone,
            'service_id'          => $service ? $service_id : 0,
            'staff_ids'           => $staff_ids,
            'location_id'         => ! empty( $arguments['location_id'] ) ? (int) $arguments['location_id'] : null,
            'nop'                 => isset( $arguments['persons'] ) ? max( 1, (int) $arguments['persons'] ) : 1,
            'days'                => isset( $arguments['days'] ) ? min( 7, max( 1, (int) $arguments['days'] ) ) : 3,
            'start_date'          => $date_from,
            'custom_duration'     => $service ? 0 : $duration,
            'ignore_appointments' => array(),
            'units'               => $units,
            'extras'              => $extras,
            'order'               => array(),
            'allowed_staff'       => AppointmentData::staffScope(),
        ) );
        DatePoint::$client_timezone = $client_tz;
        if ( $found === null ) {
            return self::json( array( 'not_found' => 'service' ) );
        }

        $step  = self::step( $service, $service ? $units * (int) $service->getDuration() : $duration );
        $from  = isset( $arguments['time_from'] ) && $arguments['time_from'] !== '' ? self::minutes( $arguments['time_from'] ) : null;
        $to    = isset( $arguments['time_to'] ) && $arguments['time_to'] !== '' ? self::minutes( $arguments['time_to'] ) : null;
        $names = array();
        $days  = array();
        foreach ( $found['days'] as $day ) {
            $by_staff = array();
            foreach ( $day['slots'] as $slot ) {
                $alternatives = isset( $slot['alt'] ) ? $slot['alt'] : array();
                unset( $slot['alt'] );
                array_unshift( $alternatives, $slot );
                foreach ( $alternatives as $item ) {
                    if ( $item['busy'] ) {
                        continue;
                    }
                    $minutes = self::minutes( $item['time'] );
                    if ( ( $from !== null && $minutes < $from ) || ( $to !== null && $minutes > $to ) ) {
                        continue;
                    }
                    $kind = isset( $item['waiting_list'] ) ? 'waiting_list' : 'free';
                    $by_staff[ (int) $item['staff_id'] ][ $kind ][ $minutes ] = true;
                }
            }
            if ( ! $by_staff ) {
                continue;
            }
            $staff = array();
            foreach ( $by_staff as $staff_id => $kinds ) {
                if ( ! isset( $names[ $staff_id ] ) ) {
                    $member = Lib\Entities\Staff::find( $staff_id );
                    $names[ $staff_id ] = $member ? $member->getFullName() : null;
                }
                $item = array(
                    'staff_id'  => $staff_id,
                    'full_name' => $names[ $staff_id ],
                    'free'      => isset( $kinds['free'] ) ? self::ranges( array_keys( $kinds['free'] ), $step ) : array(),
                );
                if ( isset( $kinds['waiting_list'] ) ) {
                    $item['waiting_list'] = self::ranges( array_keys( $kinds['waiting_list'] ), $step );
                }
                $staff[] = $item;
            }
            $days[] = array( 'date' => $day['date'], 'staff' => $staff );
        }

        return self::json( array(
            'time_zone'    => $time_zone,
            'service_id'   => $service ? $service_id : null,
            'step_minutes' => (int) ( $step / MINUTE_IN_SECONDS ),
            'days'         => $days,
            'next_date'    => $found['next_start'],
            'timed_out'    => ! empty( $found['timed_out'] ),
        ) );
    }

    /**
     * Grid step of the search in seconds, as the slot engine picks it for the first stage.
     *
     * @param Lib\Entities\Service|null $service null — own (Custom) service
     * @param int                       $duration
     * @return int
     */
    private static function step( $service, $duration )
    {
        $length = $service && $service->getType() !== Lib\Entities\Service::TYPE_COMPOUND
            ? $service->getSlotLength()
            : Lib\Entities\Service::SLOT_LENGTH_DEFAULT;
        if ( $length === Lib\Entities\Service::SLOT_LENGTH_DEFAULT ) {
            return Lib\Config::useServiceDurationAsSlotLength() ? $duration : Lib\Config::getTimeSlotLength();
        }
        if ( $length === Lib\Entities\Service::SLOT_LENGTH_AS_SERVICE_DURATION ) {
            return $duration;
        }

        return (int) $length;
    }

    /**
     * @param string $time HH:MM
     * @return int minutes from midnight
     */
    private static function minutes( $time )
    {
        list( $h, $m ) = explode( ':', $time );

        return (int) $h * 60 + (int) $m;
    }

    /**
     * Start times as "HH:MM", a run of starts every $step seconds as "HH:MM-HH:MM".
     *
     * @param int[] $minutes
     * @param int   $step seconds
     * @return string[]
     */
    private static function ranges( array $minutes, $step )
    {
        sort( $minutes );
        $step   = max( 1, (int) ( $step / MINUTE_IN_SECONDS ) );
        $format = function ( $m ) {
            return sprintf( '%02d:%02d', floor( $m / 60 ), $m % 60 );
        };
        $list  = array();
        $first = null;
        $last  = null;
        foreach ( $minutes as $m ) {
            if ( $first !== null && $m === $last + $step ) {
                $last = $m;
                continue;
            }
            if ( $first !== null ) {
                $list[] = $first === $last ? $format( $first ) : $format( $first ) . '-' . $format( $last );
            }
            $first = $last = $m;
        }
        if ( $first !== null ) {
            $list[] = $first === $last ? $format( $first ) : $format( $first ) . '-' . $format( $last );
        }

        return $list;
    }
}
