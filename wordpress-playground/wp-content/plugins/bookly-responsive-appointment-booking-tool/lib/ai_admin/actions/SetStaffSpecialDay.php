<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\Format;
use Bookly\Lib\AiAdmin\StaffData;
use Bookly\Lib\AiAdmin\State;

/**
 * set_staff_special_day — special hours of one staff member on given dates (Special Days add-on):
 * a date without a special day gets one, a date with one gets the new hours
 * (assets/ai-setup/actions/set_staff_special_day.yaml).
 */
class SetStaffSpecialDay implements ActionInterface
{
    /**
     * @inheritDoc
     */
    public function validate( array $args, State $state )
    {
        if ( $error = StaffData::forbidden( 'field:staff_special_day.start_time' ) ) {
            return array( $error );
        }
        if ( ! Lib\Config::specialDaysActive() ) {
            return array( 'addon_inactive: special days need the special-days add-on' );
        }
        $staff_id = isset( $args['staff_id'] ) ? $args['staff_id'] : null;
        if ( ! StaffData::exists( $staff_id, $state ) ) {
            return array( 'staff_not_found: ' . wp_json_encode( $staff_id ) );
        }
        $days = isset( $args['days'] ) && is_array( $args['days'] ) ? array_values( $args['days'] ) : array();
        if ( ! $days ) {
            return array( 'days: at least one {date, start, end[, breaks]}' );
        }
        $errors  = array();
        $today   = date_create( current_time( 'mysql' ) )->format( 'Y-m-d' );
        $current = StaffData::specialDays( $staff_id, $state );
        $seen    = array();
        $changes = array();
        foreach ( $days as $day ) {
            $date = is_array( $day ) && isset( $day['date'] ) ? (string) $day['date'] : '';
            if ( ! Lib\Utils\DateTime::validateDate( $date ) ) {
                $errors[] = 'days: date ' . wp_json_encode( $date ) . ' is YYYY-MM-DD';
                continue;
            }
            if ( $date < $today ) {
                $errors[] = 'date_in_past: ' . $date;
                continue;
            }
            if ( isset( $seen[ $date ] ) ) {
                $errors[] = 'duplicate_date: ' . $date;
                continue;
            }
            $seen[ $date ] = true;
            if ( ! empty( $current[ $date ]['duplicates'] ) ) {
                $errors[] = 'duplicate_rows_exist: ' . $date . ' has several special days — the owner cleans them up in the dialog';
                continue;
            }
            if ( ! isset( $day['start'], $day['end'] ) || ! is_string( $day['start'] ) || ! is_string( $day['end'] ) || $day['start'] === '' ) {
                $errors[] = 'hours_required: ' . $date . ' — start and end; to close the date use a day off';
                continue;
            }
            if ( array_key_exists( 'breaks', $day ) && ! is_array( $day['breaks'] ) ) {
                $errors[] = 'days: ' . $date . '.breaks is a list of {start, end}';
                continue;
            }
            $new = self::day( $day, isset( $current[ $date ] ) ? $current[ $date ] : null );
            foreach ( StaffData::dayErrors( 'staff_special_day', $new, isset( $current[ $date ]['start'] ) ? $current[ $date ] : 'off', $date ) as $error ) {
                $errors[] = $error;
            }
            $changes[ $date ] = array_merge( array( 'date' => $date ), $new );
        }
        $state->set( 'staff:special_days#' . $staff_id, array_merge( self::stateDays( $staff_id, $state ), $changes ) );

        return $errors;
    }

    /**
     * @inheritDoc
     */
    public function preview( array $args, State $state )
    {
        $staff_id = $args['staff_id'];
        $current  = StaffData::specialDays( $staff_id, $state );
        $weekly   = StaffData::schedule( $staff_id, $state );
        $profile  = StaffData::profile( $staff_id, $state );
        $existing = ! StaffData::isNew( $staff_id, $state );
        $rule     = Access::rule( 'field:staff_special_day.start_time' );
        $keys     = array_values( StaffData::days() );
        $rows     = array();
        $off      = array();
        $changes  = array();
        $conflict = array();
        $appointments = $existing ? StaffData::futureAppointments( (int) $staff_id ) : array();
        foreach ( $args['days'] as $day ) {
            $date    = $day['date'];
            $new     = self::day( $day, isset( $current[ $date ] ) ? $current[ $date ] : null );
            $key     = $keys[ (int) date( 'w', strtotime( $date ) ) ];
            $prev    = $keys[ ( (int) date( 'w', strtotime( $date ) ) + 6 ) % 7 ];
            $day_off = $existing && StaffData::isDayOff( (int) $staff_id, $date );
            if ( isset( $current[ $date ] ) ) {
                $now  = isset( $current[ $date ]['start'] ) ? $current[ $date ] : 'off';
                $text = StaffData::dayText( $now );
            } elseif ( $day_off ) {
                $now  = 'off';
                $text = __( 'Day off', 'bookly-responsive-appointment-booking-tool' );
            } else {
                $now  = $weekly[ $key ];
                $text = StaffData::dayText( $now ) . ' (' . __( 'weekly hours', 'bookly-responsive-appointment-booking-tool' ) . ')';
            }
            if ( $day_off ) {
                $off[] = Lib\Utils\DateTime::formatDate( $date );
            }
            $rows[] = array(
                'label'  => StaffData::rowLabel( $staff_id, $state, Format::label( $rule, 'Special Days' ) . ' › ' . Lib\Utils\DateTime::formatDate( $date ) ),
                'before' => $text,
                'after'  => StaffData::dayText( $new ),
                'link'   => Format::link( $rule ),
            );
            $changes[ $date ] = array_merge( array( 'date' => $date ), $new );
            foreach ( $appointments as $appointment ) {
                list( $a_date, $start, $end ) = StaffData::localInterval( $appointment, $profile['time_zone'] );
                $before_prev = isset( $current[ date( 'Y-m-d', strtotime( $date . ' -1 day' ) ) ] ) ? 'off' : $weekly[ $prev ];
                if ( $a_date === $date && StaffData::fits( $start, $end, $now, $before_prev ) && ! StaffData::fits( $start, $end, $new, $before_prev ) ) {
                    $conflict[] = $appointment;
                }
            }
        }
        $warnings = array();
        if ( $off ) {
            $warnings[] = sprintf( __( 'These dates are days off of the staff member; the special day makes them working days: %s.', 'bookly-responsive-appointment-booking-tool' ), implode( ', ', $off ) );
        }
        if ( $conflict ) {
            $warnings[] = StaffData::conflictsText( $conflict );
        }
        if ( $existing ) {
            $warnings = array_merge( $warnings, StaffData::notAffected( StaffData::ownLocations( (int) $staff_id, 'special_days' ) ) );
        }
        $warnings = array_merge( $warnings, StaffData::actionWarnings( 'set_staff_special_day', $state ) );
        $state->set( 'staff:special_days#' . $staff_id, array_merge( self::stateDays( $staff_id, $state ), $changes ) );

        return array( 'rows' => $rows, 'warnings' => array_values( array_unique( $warnings ) ) );
    }

    /**
     * @inheritDoc
     */
    public function apply( array $args )
    {
        $staff_id = (int) $args['staff_id'];
        $current  = StaffData::specialDays( $staff_id );
        $snapshot = array( 'staff_id' => $staff_id, 'created' => array(), 'changed' => array() );
        foreach ( $args['days'] as $day ) {
            $date = $day['date'];
            if ( isset( $current[ $date ] ) ) {
                $old = $current[ $date ];
                $snapshot['changed'][] = array(
                    'date'   => $date,
                    'start'  => isset( $old['start'] ) ? StaffData::dbTime( $old['start'] ) : null,
                    'end'    => isset( $old['end'] ) ? StaffData::dbTime( $old['end'] ) : null,
                    'breaks' => isset( $old['breaks'] ) ? self::dbBreaks( $old['breaks'] ) : null,
                );
            }
            $id = Lib\Proxy\SpecialDays::saveStaffSpecialDay(
                $staff_id,
                $date,
                StaffData::dbTime( $day['start'] ),
                StaffData::dbTime( $day['end'] ),
                array_key_exists( 'breaks', $day ) ? self::dbBreaks( $day['breaks'] ) : ( isset( $current[ $date ] ) ? null : array() )
            );
            if ( ! isset( $current[ $date ] ) ) {
                $snapshot['created'][] = (int) $id;
            }
        }

        return $snapshot;
    }

    /**
     * @inheritDoc
     */
    public function undo( array $snapshot )
    {
        if ( empty( $snapshot['staff_id'] ) ) {
            return;
        }
        foreach ( $snapshot['created'] as $id ) {
            Lib\Proxy\SpecialDays::deleteStaffSpecialDay( $id );
        }
        foreach ( $snapshot['changed'] as $day ) {
            Lib\Proxy\SpecialDays::saveStaffSpecialDay( $snapshot['staff_id'], $day['date'], $day['start'], $day['end'], $day['breaks'] );
        }
    }

    /**
     * The date after the change: given hours, given breaks or the kept ones of an existing
     * special day (none for a new one).
     *
     * @param array      $day
     * @param array|null $current
     * @return array
     */
    protected static function day( array $day, $current )
    {
        if ( array_key_exists( 'breaks', $day ) ) {
            $breaks = $day['breaks'];
        } else {
            $breaks = is_array( $current ) && isset( $current['breaks'] ) ? $current['breaks'] : array();
        }

        return array( 'start' => StaffData::hhmm( $day['start'] ), 'end' => StaffData::hhmm( $day['end'] ), 'breaks' => array_values( $breaks ) );
    }

    /**
     * Special days changed by earlier items of the proposal.
     *
     * @param mixed $staff_id
     * @param State $state
     * @return array
     */
    protected static function stateDays( $staff_id, State $state )
    {
        $days = $state->get( 'staff:special_days#' . $staff_id );

        return is_array( $days ) ? $days : array();
    }

    /**
     * @param array $breaks array( array( 'start', 'end' ) )
     * @return array array( array( 'start_time', 'end_time' ) )
     */
    protected static function dbBreaks( array $breaks )
    {
        $list = array();
        foreach ( $breaks as $break ) {
            $list[] = array( 'start_time' => StaffData::dbTime( $break['start'] ), 'end_time' => StaffData::dbTime( $break['end'] ) );
        }

        return $list;
    }
}
