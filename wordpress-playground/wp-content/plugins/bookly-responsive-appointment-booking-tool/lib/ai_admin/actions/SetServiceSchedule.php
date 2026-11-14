<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\AllowedValues;
use Bookly\Lib\AiAdmin\Services\ScheduleCheck;
use Bookly\Lib\AiAdmin\Services\ServiceAction;
use Bookly\Lib\AiAdmin\Services\ServiceData;
use Bookly\Lib\AiAdmin\State;

/**
 * set_service_schedule — hours and breaks of one service for the given weekdays, other weekdays
 * stay (service-schedule add-on, assets/ai-setup/actions/set_service_schedule.yaml).
 */
class SetServiceSchedule extends ServiceAction implements ActionInterface
{
    protected function name()
    {
        return 'set_service_schedule';
    }

    /**
     * @inheritDoc
     */
    public function validate( array $args, State $state )
    {
        if ( $error = $this->forbidden() ) {
            return array( $error );
        }
        $id = isset( $args['service_id'] ) ? self::id( $args['service_id'] ) : 0;
        if ( ! self::exists( $id, $state ) ) {
            return array( 'service_not_found: ' . wp_json_encode( isset( $args['service_id'] ) ? $args['service_id'] : null ) );
        }
        if ( ! Lib\Config::serviceScheduleActive() ) {
            return array( 'addon_inactive: the service schedule needs the service-schedule add-on' );
        }
        $type = self::current( $state, $id, 'type' );
        if ( ! in_array( $type, array( 'simple', 'compound', 'collaborative' ), true ) ) {
            return array( 'type_without_schedule: a ' . $type . ' service has no schedule' );
        }
        $days = isset( $args['days'] ) && is_array( $args['days'] ) ? $args['days'] : array();
        if ( ! $days ) {
            return array( 'time_out_of_range: days — give at least one weekday' );
        }
        $current = self::current( $state, $id, 'schedule' );
        $stored = is_int( $id ) ? ServiceData::schedule( $id ) : $current;
        $errors = array();
        foreach ( $days as $name => $day ) {
            if ( ServiceData::dayIndex( $name ) === null ) {
                $errors[] = 'time_out_of_range: ' . $name . ' is not one of mon…sun';
                continue;
            }
            if ( $day === 'off' ) {
                continue;
            }
            if ( ! is_array( $day ) ) {
                $errors[] = 'day_off_has_no_breaks: ' . $name . ' is "off" or {start, end, breaks}';
                continue;
            }
            if ( isset( $day['off'] ) ) {
                $errors[] = 'day_off_has_no_breaks: an "off" day is the string "off", without breaks';
                continue;
            }
            $kept = is_array( $current[ $name ] ) ? $current[ $name ]['breaks'] : $this->storedBreaks( $id, $name );
            foreach ( ScheduleCheck::day( $name, $day, is_array( $stored[ $name ] ) ? $stored[ $name ] : null, $kept ) as $error ) {
                $errors[] = $error;
            }
        }
        if ( ! $errors ) {
            self::setCurrent( $state, $id, 'schedule', $this->merge( $id, $current, $days ) );
        }

        return $errors;
    }

    /**
     * Breaks stored for an "off" day (the dialog keeps them; they return when the day gets hours).
     *
     * @param int|string $id
     * @param string     $name
     * @return array
     */
    protected function storedBreaks( $id, $name )
    {
        if ( ! is_int( $id ) ) {
            return array();
        }
        $rows = ServiceData::scheduleRows( $id );

        return ServiceData::breaksHhmm( $rows[ ServiceData::dayIndex( $name ) ]['breaks'] );
    }

    /**
     * Schedule after the change.
     *
     * @param int|string $id
     * @param array      $current
     * @param array      $days
     * @return array
     */
    protected function merge( $id, array $current, array $days )
    {
        foreach ( $days as $name => $day ) {
            if ( $day === 'off' ) {
                $current[ $name ] = 'off';
            } else {
                $current[ $name ] = array(
                    'start' => $day['start'],
                    'end' => $day['end'],
                    'breaks' => array_key_exists( 'breaks', $day )
                        ? $this->breaks( $day['breaks'] )
                        : ( is_array( $current[ $name ] ) ? $current[ $name ]['breaks'] : $this->storedBreaks( $id, $name ) ),
                );
            }
        }

        return $current;
    }

    /**
     * @param array $breaks
     * @return array sorted array( array( start, end ) )
     */
    protected function breaks( array $breaks )
    {
        $result = array();
        foreach ( $breaks as $break ) {
            $result[] = array( 'start' => $break['start'], 'end' => $break['end'] );
        }
        usort( $result, function ( $a, $b ) {
            return strcmp( $a['start'], $b['start'] );
        } );

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function preview( array $args, State $state )
    {
        $id = self::id( $args['service_id'] );
        $current = self::current( $state, $id, 'schedule' );
        $after = $this->merge( $id, $current, $args['days'] );
        $rows = array();
        foreach ( ServiceData::weekOrder() as $day_index ) {
            $name = ServiceData::dayName( $day_index );
            if ( ! array_key_exists( $name, $args['days'] ) ) {
                continue;
            }
            $rows[] = array(
                'label' => self::rowLabel( $state, $id, __( 'Schedule', 'bookly-responsive-appointment-booking-tool' ), array( 'day' => $name ) ),
                'before' => ServiceData::dayText( $current[ $name ] ),
                'after' => ServiceData::dayText( $after[ $name ] ),
                'link' => add_query_arg( array( 'page' => 'bookly-services' ), admin_url( 'admin.php' ) ),
            );
        }
        $warnings = $this->actionWarnings( $state );
        if ( is_int( $id ) ) {
            $conflicts = $this->conflicts( $id, $current, $after );
            if ( $conflicts ) {
                $warnings[] = sprintf(
                    __( '%d future appointments fit the current service hours but not the new ones: %s', 'bookly-responsive-appointment-booking-tool' ),
                    count( $conflicts ),
                    self::appointmentsText( $conflicts )
                );
            }
        }
        self::setCurrent( $state, $id, 'schedule', $after );

        return array( 'rows' => $rows, 'warnings' => $warnings );
    }

    /**
     * Future appointments of the service that fit the current weekly hours but not the new ones;
     * dates with a special day of the service are skipped.
     *
     * @param int   $id
     * @param array $current
     * @param array $after
     * @return array
     */
    protected function conflicts( $id, array $current, array $after )
    {
        $special = Lib\Config::specialDaysActive() ? ServiceData::specialDays( $id ) : array();
        $conflicts = array();
        foreach ( self::futureAppointments( $id ) as $appointment ) {
            $date = substr( $appointment['start_date'], 0, 10 );
            if ( isset( $special[ $date ] ) ) {
                continue;
            }
            $name = strtolower( date( 'D', strtotime( $date ) ) );
            if ( $current[ $name ] === $after[ $name ] ) {
                continue;
            }
            if ( self::fits( $appointment, $current[ $name ] ) && ! self::fits( $appointment, $after[ $name ] ) ) {
                $conflicts[] = $appointment;
            }
        }

        return $conflicts;
    }

    /**
     * Whether an appointment lies inside the hours of its day and outside its breaks.
     *
     * @param array        $appointment
     * @param array|string $day
     * @return bool
     */
    public static function fits( array $appointment, $day )
    {
        if ( ! is_array( $day ) ) {
            return false;
        }
        $start = AllowedValues::seconds( substr( $appointment['start_date'], 11, 5 ) );
        $end = $start + ( strtotime( $appointment['end_date'] ) - strtotime( $appointment['start_date'] ) );
        if ( $start < AllowedValues::seconds( $day['start'] ) || $end > AllowedValues::seconds( $day['end'] ) ) {
            return false;
        }
        foreach ( isset( $day['breaks'] ) ? $day['breaks'] : array() as $break ) {
            if ( $start < AllowedValues::seconds( $break['end'] ) && $end > AllowedValues::seconds( $break['start'] ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function apply( array $args )
    {
        $service_id = (int) $args['service_id'];
        $stored = (array) Lib\Proxy\ServiceSchedule::getServiceScheduleDays( $service_id );
        $rows = ServiceData::scheduleRows( $service_id );
        $days = array();
        foreach ( $rows as $day_index => $row ) {
            unset( $row['stored'], $row['breaks'] );
            $name = ServiceData::dayName( $day_index );
            if ( array_key_exists( $name, $args['days'] ) ) {
                $day = $args['days'][ $name ];
                if ( $day === 'off' ) {
                    // The dialog keeps the breaks of a day turned OFF.
                    $row['start_time'] = null;
                    $row['end_time'] = null;
                } else {
                    $row['start_time'] = ServiceData::hhmmss( $day['start'] );
                    $row['end_time'] = ServiceData::hhmmss( $day['end'] );
                    if ( array_key_exists( 'breaks', $day ) ) {
                        $row['breaks'] = array();
                        foreach ( $this->breaks( $day['breaks'] ) as $break ) {
                            $row['breaks'][] = array( 'start_time' => ServiceData::hhmmss( $break['start'] ), 'end_time' => ServiceData::hhmmss( $break['end'] ) );
                        }
                    }
                }
            }
            $days[ $day_index ] = $row;
        }
        Lib\Proxy\ServiceSchedule::setServiceScheduleDays( $service_id, $days );
        // Undo rewrites the breaks only of the days whose breaks were replaced.
        foreach ( $stored as $day_index => $row ) {
            if ( ! array_key_exists( 'breaks', $days[ $day_index ] ) ) {
                unset( $stored[ $day_index ]['breaks'] );
            }
        }

        return array( 'service_id' => $service_id, 'days' => $stored );
    }

    /**
     * @inheritDoc
     */
    public function undo( array $snapshot )
    {
        Lib\Proxy\ServiceSchedule::setServiceScheduleDays( $snapshot['service_id'], $snapshot['days'] );
    }
}
