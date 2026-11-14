<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Services\ScheduleCheck;
use Bookly\Lib\AiAdmin\Services\ServiceAction;
use Bookly\Lib\AiAdmin\Services\ServiceData;
use Bookly\Lib\AiAdmin\State;

/**
 * set_service_special_day — hours (or OFF) and breaks of one service on given dates instead of its
 * weekly schedule (special-days with service-schedule, assets/ai-setup/actions/set_service_special_day.yaml).
 */
class SetServiceSpecialDay extends ServiceAction implements ActionInterface
{
    protected function name()
    {
        return 'set_service_special_day';
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
        if ( ! Lib\Config::specialDaysActive() || ! Lib\Config::serviceScheduleActive() ) {
            return array( 'addon_inactive: special days of a service need the special-days and service-schedule add-ons' );
        }
        $type = self::current( $state, $id, 'type' );
        if ( ! in_array( $type, array( 'simple', 'compound', 'collaborative' ), true ) ) {
            return array( 'type_without_special_days: a ' . $type . ' service has no special days' );
        }
        $days = isset( $args['days'] ) && is_array( $args['days'] ) ? array_values( $args['days'] ) : array();
        if ( ! $days ) {
            return array( 'date_in_past: days — give at least one date' );
        }
        $current = self::current( $state, $id, 'special_days' );
        $stored = is_int( $id ) ? ServiceData::specialDays( $id ) : array();
        $today = current_time( 'Y-m-d' );
        $errors = array();
        $dates = array();
        foreach ( $days as $day ) {
            $date = is_array( $day ) && isset( $day['date'] ) ? $day['date'] : null;
            if ( ! is_string( $date ) || ! Lib\Utils\DateTime::validateDate( $date ) ) {
                $errors[] = 'date_in_past: ' . wp_json_encode( $date ) . ' is not a date YYYY-MM-DD';
                continue;
            }
            if ( $date < $today ) {
                $errors[] = 'date_in_past: ' . $date;
                continue;
            }
            if ( in_array( $date, $dates, true ) ) {
                $errors[] = 'duplicate_date: ' . $date;
                continue;
            }
            $dates[] = $date;
            if ( isset( $current[ $date ]['duplicates'] ) ) {
                $errors[] = 'duplicate_rows_exist: ' . $date . ' has several special days already — the owner fixes it in the dialog';
                continue;
            }
            if ( array_key_exists( 'off', $day ) ) {
                if ( $day['off'] !== true || count( $day ) !== 2 ) {
                    $errors[] = 'off_has_no_breaks: ' . $date . ' — an off day is {date, off: true} only';
                }
                continue;
            }
            $kept = isset( $current[ $date ]['breaks'] ) ? $current[ $date ]['breaks'] : $this->storedBreaks( $id, $date );
            $old = isset( $stored[ $date ]['start'] ) ? $stored[ $date ] : null;
            foreach ( ScheduleCheck::day( $date, $day, $old, $kept ) as $error ) {
                $errors[] = $error;
            }
        }
        if ( ! $errors ) {
            self::setCurrent( $state, $id, 'special_days', $this->merge( $id, $current, $days ) );
        }

        return $errors;
    }

    /**
     * Breaks stored on a date (also of an OFF day, which keeps them).
     *
     * @param int|string $id
     * @param string     $date
     * @return array
     */
    protected function storedBreaks( $id, $date )
    {
        if ( ! is_int( $id ) ) {
            return array();
        }
        foreach ( (array) Lib\Proxy\SpecialDays::getServiceSpecialDays( $id ) as $row ) {
            if ( $row['date'] === $date ) {
                return ServiceData::breaksHhmm( $row['breaks'] );
            }
        }

        return array();
    }

    /**
     * Special days after the change.
     *
     * @param int|string $id
     * @param array      $current
     * @param array      $days
     * @return array
     */
    protected function merge( $id, array $current, array $days )
    {
        foreach ( $days as $day ) {
            $date = $day['date'];
            if ( isset( $day['off'] ) ) {
                $current[ $date ] = array( 'date' => $date, 'off' => true );
            } else {
                $breaks = array_key_exists( 'breaks', $day )
                    ? $day['breaks']
                    : ( isset( $current[ $date ]['breaks'] ) ? $current[ $date ]['breaks'] : $this->storedBreaks( $id, $date ) );
                $current[ $date ] = array( 'date' => $date, 'start' => $day['start'], 'end' => $day['end'], 'breaks' => array_values( $breaks ) );
            }
        }
        ksort( $current );

        return $current;
    }

    /**
     * What applies on a date now: the special day or the weekly service hours.
     *
     * @param State      $state
     * @param int|string $id
     * @param array      $current
     * @param string     $date
     * @return array|string
     */
    protected function now( State $state, $id, array $current, $date )
    {
        if ( isset( $current[ $date ] ) ) {
            return isset( $current[ $date ]['off'] ) ? 'off' : $current[ $date ];
        }
        $schedule = self::current( $state, $id, 'schedule' );

        return $schedule[ strtolower( date( 'D', strtotime( $date ) ) ) ];
    }

    /**
     * @inheritDoc
     */
    public function preview( array $args, State $state )
    {
        $id = self::id( $args['service_id'] );
        $current = self::current( $state, $id, 'special_days' );
        $after = $this->merge( $id, $current, $args['days'] );
        $rows = array();
        $warnings = $this->actionWarnings( $state );
        $appointments = is_int( $id ) ? self::futureAppointments( $id ) : array();
        $conflicts = array();
        foreach ( $args['days'] as $day ) {
            $date = $day['date'];
            $now = $this->now( $state, $id, $current, $date );
            $new = isset( $after[ $date ]['off'] ) ? 'off' : $after[ $date ];
            $rows[] = array(
                'label' => self::rowLabel( $state, $id, Lib\Utils\DateTime::formatDate( $date ) . ' (' . ( isset( $current[ $date ] ) ? __( 'changed', 'bookly-responsive-appointment-booking-tool' ) : __( 'new', 'bookly-responsive-appointment-booking-tool' ) ) . ')' ),
                'before' => ( isset( $current[ $date ] ) ? '' : __( 'Weekly hours', 'bookly-responsive-appointment-booking-tool' ) . ': ' ) . ServiceData::dayText( $now ),
                'after' => ServiceData::dayText( $new ),
            );
            if ( $new === 'off' && is_int( $id ) && $this->storedBreaks( $id, $date ) ) {
                $warnings[] = sprintf( __( '%s: the breaks are kept and return if the date gets hours again.', 'bookly-responsive-appointment-booking-tool' ), $date );
            }
            foreach ( $appointments as $appointment ) {
                if ( substr( $appointment['start_date'], 0, 10 ) === $date
                    && SetServiceSchedule::fits( $appointment, $now ) && ! SetServiceSchedule::fits( $appointment, $new ) ) {
                    $conflicts[] = $appointment;
                }
            }
        }
        if ( $conflicts ) {
            $warnings[] = sprintf(
                __( '%d appointments on these dates fit the current hours but not the new ones: %s', 'bookly-responsive-appointment-booking-tool' ),
                count( $conflicts ),
                self::appointmentsText( $conflicts )
            );
        }
        self::setCurrent( $state, $id, 'special_days', $after );

        return array( 'rows' => $rows, 'warnings' => $warnings );
    }

    /**
     * @inheritDoc
     */
    public function apply( array $args )
    {
        $service_id = (int) $args['service_id'];
        $stored = array();
        foreach ( (array) Lib\Proxy\SpecialDays::getServiceSpecialDays( $service_id ) as $row ) {
            $stored[ $row['date'] ][] = $row;
        }
        $snapshot = array( 'service_id' => $service_id, 'dates' => array() );
        foreach ( $args['days'] as $day ) {
            $date = $day['date'];
            $old = isset( $stored[ $date ] ) ? $stored[ $date ] : array();
            $snapshot['dates'][ $date ] = $old;
            $breaks = $old ? $old[0]['breaks'] : array();
            if ( isset( $day['off'] ) ) {
                $new = array( 'start_time' => null, 'end_time' => null, 'breaks' => $breaks );
            } else {
                if ( array_key_exists( 'breaks', $day ) ) {
                    $breaks = array();
                    foreach ( $day['breaks'] as $break ) {
                        $breaks[] = array( 'start_time' => ServiceData::hhmmss( $break['start'] ), 'end_time' => ServiceData::hhmmss( $break['end'] ) );
                    }
                }
                $new = array( 'start_time' => ServiceData::hhmmss( $day['start'] ), 'end_time' => ServiceData::hhmmss( $day['end'] ), 'breaks' => $breaks );
            }
            Lib\Proxy\SpecialDays::setServiceSpecialDays( $service_id, $date, array( $new ) );
        }

        return $snapshot;
    }

    /**
     * @inheritDoc
     */
    public function undo( array $snapshot )
    {
        foreach ( $snapshot['dates'] as $date => $rows ) {
            Lib\Proxy\SpecialDays::setServiceSpecialDays( $snapshot['service_id'], $date, $rows );
        }
    }
}
