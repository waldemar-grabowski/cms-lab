<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\Format;
use Bookly\Lib\AiAdmin\StaffData;
use Bookly\Lib\AiAdmin\State;

/**
 * set_staff_schedule_break — add one break to the given weekdays of a staff member's weekly
 * schedule, or change an existing one (Schedule → Add break / click on a break)
 * (assets/ai-setup/actions/set_staff_schedule_break.yaml).
 */
class SetStaffScheduleBreak implements ActionInterface
{
    /**
     * @inheritDoc
     */
    public function validate( array $args, State $state )
    {
        $staff_id = isset( $args['staff_id'] ) ? $args['staff_id'] : null;
        if ( $error = StaffData::forbidden( 'field:staff_schedule_item.breaks.start_time' ) ) {
            return array( $error );
        }
        if ( ! StaffData::exists( $staff_id, $state ) ) {
            return array( 'staff_not_found: ' . wp_json_encode( $staff_id ) );
        }
        $days = isset( $args['days'] ) && is_array( $args['days'] ) ? array_values( $args['days'] ) : array();
        if ( ! $days || count( array_unique( $days ) ) !== count( $days ) ) {
            return array( 'days: a non-empty list of different weekdays (mon…sun)' );
        }
        foreach ( array( 'start', 'end' ) as $key ) {
            if ( ! isset( $args[ $key ] ) || StaffData::seconds( $args[ $key ] ) === null ) {
                return array( $key . ': "HH:MM"' );
            }
        }
        if ( isset( $args['replaces'] ) && ( ! is_array( $args['replaces'] ) || ! isset( $args['replaces']['start'], $args['replaces']['end'] ) ) ) {
            return array( 'replaces: {start, end} of the existing break' );
        }
        $errors   = array();
        $schedule = StaffData::schedule( $staff_id, $state );
        $after    = self::merge( $schedule, $args, $errors );
        if ( $errors ) {
            return $errors;
        }
        if ( isset( $args['replaces'] )
            && StaffData::seconds( $args['replaces']['start'] ) === StaffData::seconds( $args['start'] )
            && StaffData::seconds( $args['replaces']['end'] ) === StaffData::seconds( $args['end'] ) ) {
            return array( 'nothing_to_change: replaces equals start and end' );
        }
        $map = array( 'time_out_of_range' => 'time_off_grid' );
        foreach ( $days as $key ) {
            foreach ( StaffData::dayErrors( 'staff_schedule_item', $after[ $key ], $schedule[ $key ], $key ) as $error ) {
                $id       = strstr( $error, ':', true );
                $errors[] = isset( $map[ $id ] ) ? $map[ $id ] . substr( $error, strlen( $id ) ) : $error;
            }
        }
        $state->set( 'staff:schedule#' . $staff_id, $after );

        return $errors;
    }

    /**
     * @inheritDoc
     */
    public function preview( array $args, State $state )
    {
        $staff_id = $args['staff_id'];
        $before   = StaffData::schedule( $staff_id, $state );
        $errors   = array();
        $after    = self::merge( $before, $args, $errors );
        $rows     = array();
        foreach ( StaffData::week() as $key ) {
            if ( wp_json_encode( $before[ $key ] ) === wp_json_encode( $after[ $key ] ) ) {
                continue;
            }
            $rows[] = array(
                // The whole day, as set_staff_schedule shows it: one Schedule row per day on the card.
                'label'  => StaffData::rowLabel( $staff_id, $state, Format::label( Access::rule( 'field:staff_schedule_item.start_time' ), 'Schedule' ), array( 'day' => $key ) ),
                'before' => StaffData::dayText( $before[ $key ] ),
                'after'  => StaffData::dayText( $after[ $key ] ),
                'link'   => Format::link( Access::rule( 'field:staff_schedule_item.start_time' ) ),
            );
        }
        $warnings = SetStaffSchedule::notes( $staff_id, $state, $before, $after, array(), 'schedule' );
        $warnings = array_merge( $warnings, StaffData::actionWarnings( 'set_staff_schedule_break', $state ) );
        $state->set( 'staff:schedule#' . $staff_id, $after );

        return array( 'rows' => $rows, 'warnings' => array_values( array_unique( $warnings ) ) );
    }

    /**
     * @inheritDoc
     */
    public function apply( array $args )
    {
        $staff_id = (int) $args['staff_id'];
        $errors   = array();
        $after    = self::merge( StaffData::schedule( $staff_id ), $args, $errors );
        $days     = array();
        foreach ( $args['days'] as $key ) {
            $days[ $key ] = $after[ $key ];
        }

        return SetStaffSchedule::write( $staff_id, $days );
    }

    /**
     * @inheritDoc
     */
    public function undo( array $snapshot )
    {
        SetStaffSchedule::restore( $snapshot );
    }

    /**
     * Schedule with the break added (or the replaced break changed) on the listed days; day_off
     * and break_not_found go to $errors.
     *
     * @param array $schedule
     * @param array $args
     * @param array $errors
     * @return array
     */
    protected static function merge( array $schedule, array $args, array &$errors )
    {
        $break = array( 'start' => StaffData::hhmm( $args['start'] ), 'end' => StaffData::hhmm( $args['end'] ) );
        foreach ( $args['days'] as $key ) {
            if ( ! isset( $schedule[ $key ] ) ) {
                $errors[] = 'days: unknown weekday ' . $key;
                continue;
            }
            $day = $schedule[ $key ];
            if ( ! is_array( $day ) ) {
                $errors[] = 'day_off: ' . $key . ' is not a working day';
                continue;
            }
            $breaks = $day['breaks'];
            if ( isset( $args['replaces'] ) ) {
                $found = false;
                foreach ( $breaks as $i => $old ) {
                    if ( ! $found && StaffData::seconds( $old['start'] ) === StaffData::seconds( $args['replaces']['start'] )
                        && StaffData::seconds( $old['end'] ) === StaffData::seconds( $args['replaces']['end'] ) ) {
                        $breaks[ $i ] = $break;
                        $found        = true;
                    }
                }
                if ( ! $found ) {
                    $errors[] = 'break_not_found: no break ' . $args['replaces']['start'] . '–' . $args['replaces']['end'] . ' on ' . $key;
                    continue;
                }
            } else {
                $breaks[] = $break;
            }
            $schedule[ $key ] = StaffData::dayShape( $day['start'], $day['end'], $breaks );
        }

        return $schedule;
    }
}
