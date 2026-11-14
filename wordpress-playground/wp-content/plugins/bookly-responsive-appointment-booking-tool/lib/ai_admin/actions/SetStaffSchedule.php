<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\Format;
use Bookly\Lib\AiAdmin\StaffData;
use Bookly\Lib\AiAdmin\State;

/**
 * set_staff_schedule — working hours (and, when given, the full list of breaks) of one staff
 * member for the given weekdays, the general schedule (location_id NULL) of the Schedule tab
 * (assets/ai-setup/actions/set_staff_schedule.yaml).
 */
class SetStaffSchedule implements ActionInterface
{
    /**
     * @inheritDoc
     */
    public function validate( array $args, State $state )
    {
        $staff_id = isset( $args['staff_id'] ) ? $args['staff_id'] : null;
        if ( $error = StaffData::forbidden( 'field:staff_schedule_item.start_time' ) ) {
            return array( $error );
        }
        if ( ! StaffData::exists( $staff_id, $state ) ) {
            return array( 'staff_not_found: ' . wp_json_encode( $staff_id ) );
        }
        $days = isset( $args['days'] ) && is_array( $args['days'] ) ? $args['days'] : array();
        if ( ! $days ) {
            return array( 'days: at least one weekday (mon…sun)' );
        }
        $errors   = array();
        $schedule = StaffData::schedule( $staff_id, $state );
        foreach ( $days as $key => $day ) {
            if ( ! isset( $schedule[ $key ] ) ) {
                $errors[] = 'days: unknown weekday ' . $key;
                continue;
            }
            $current = $schedule[ $key ];
            if ( $day === 'off' ) {
                $schedule[ $key ] = 'off';
                continue;
            }
            if ( ! is_array( $day ) || ! isset( $day['start'], $day['end'] ) || ! is_string( $day['start'] ) || ! is_string( $day['end'] ) ) {
                $errors[] = 'days: ' . $key . ' is "off" or {start, end[, breaks]}';
                continue;
            }
            $given = array_key_exists( 'breaks', $day );
            if ( $given && ! is_array( $day['breaks'] ) ) {
                $errors[] = 'days: ' . $key . '.breaks is a list of {start, end}';
                continue;
            }
            $new = array( 'start' => $day['start'], 'end' => $day['end'], 'breaks' => $given ? array_values( $day['breaks'] ) : array() );
            foreach ( StaffData::dayErrors( 'staff_schedule_item', $new, $current, $key ) as $error ) {
                $errors[] = $error;
            }
            if ( ! $given && is_array( $current ) ) {
                // Breaks kept as they are must fit the new hours.
                $s = StaffData::seconds( $day['start'] );
                $e = StaffData::seconds( $day['end'] );
                foreach ( $current['breaks'] as $break ) {
                    if ( StaffData::seconds( $break['start'] ) < $s || StaffData::seconds( $break['end'] ) > $e ) {
                        $errors[] = sprintf( 'breaks_outside_new_hours: %s break %s–%s does not fit %s–%s; send the day\'s full breaks list', $key, $break['start'], $break['end'], $day['start'], $day['end'] );
                    }
                }
                $new['breaks'] = $current['breaks'];
            }
            $schedule[ $key ] = StaffData::dayShape( $new['start'], $new['end'], $new['breaks'] );
        }
        $state->set( 'staff:schedule#' . $staff_id, $schedule );

        return $errors;
    }

    /**
     * @inheritDoc
     */
    public function preview( array $args, State $state )
    {
        $staff_id = $args['staff_id'];
        $before   = StaffData::schedule( $staff_id, $state );
        $after    = self::merge( $before, $args['days'] );
        $rows     = array();
        $removed  = array();
        $rule     = Access::rule( 'field:staff_schedule_item.start_time' );
        foreach ( StaffData::week() as $key ) {
            if ( wp_json_encode( $before[ $key ] ) === wp_json_encode( $after[ $key ] ) ) {
                continue;
            }
            $rows[] = array(
                'label'  => StaffData::rowLabel( $staff_id, $state, Format::label( $rule, 'Schedule' ), array( 'day' => $key ) ),
                'before' => StaffData::dayText( $before[ $key ] ),
                'after'  => StaffData::dayText( $after[ $key ] ),
                'link'   => Format::link( $rule ),
            );
            $removed = array_merge( $removed, self::removedBreaks( $key, $before[ $key ], $after[ $key ] ) );
        }
        $warnings = self::notes( $staff_id, $state, $before, $after, $removed, 'schedule' );
        $warnings = array_merge( $warnings, StaffData::actionWarnings( 'set_staff_schedule', $state ) );
        $state->set( 'staff:schedule#' . $staff_id, $after );

        return array( 'rows' => $rows, 'warnings' => array_values( array_unique( $warnings ) ) );
    }

    /**
     * @inheritDoc
     */
    public function apply( array $args )
    {
        $staff_id = (int) $args['staff_id'];
        $after    = self::merge( StaffData::schedule( $staff_id ), $args['days'] );
        $days     = array();
        foreach ( array_keys( $args['days'] ) as $key ) {
            $days[ $key ] = $after[ $key ];
        }

        return self::write( $staff_id, $days );
    }

    /**
     * @inheritDoc
     */
    public function undo( array $snapshot )
    {
        self::restore( $snapshot );
    }

    /**
     * Schedule after the change: given days replace the current ones; a working day without
     * breaks keeps its current breaks.
     *
     * @param array $schedule
     * @param array $days
     * @return array
     */
    public static function merge( array $schedule, array $days )
    {
        foreach ( $days as $key => $day ) {
            if ( $day === 'off' ) {
                $schedule[ $key ] = 'off';
                continue;
            }
            $breaks = array_key_exists( 'breaks', $day )
                ? $day['breaks']
                : ( is_array( $schedule[ $key ] ) ? $schedule[ $key ]['breaks'] : array() );
            $schedule[ $key ] = StaffData::dayShape( $day['start'], $day['end'], $breaks );
        }

        return $schedule;
    }

    /**
     * Write the given weekdays (full day: hours and all breaks, or "off") of the general schedule
     * with the plugin entities, as the Schedule tab and its break dialog save them.
     *
     * @param int   $staff_id
     * @param array $days key => day shape or "off"
     * @return array snapshot for restore()
     */
    public static function write( $staff_id, array $days )
    {
        $rows     = StaffData::scheduleRows( $staff_id );
        $index    = array_flip( StaffData::days() );
        $snapshot = array( 'staff_id' => $staff_id, 'days' => array() );
        foreach ( $days as $key => $day ) {
            $day_index = $index[ $key ];
            $row       = $rows[ $day_index ];
            $snapshot['days'][ $key ] = array(
                'id'      => $row['id'],
                'start'   => $row['start'],
                'end'     => $row['end'],
                'breaks'  => $row['breaks'],
            );
            $item = $row['id'] ? Lib\Entities\StaffScheduleItem::find( $row['id'], false ) : false;
            if ( ! $item ) {
                // A missing weekday row is created as Staff::getScheduleItems() does.
                $item = new Lib\Entities\StaffScheduleItem();
                $item
                    ->setStaffId( $staff_id )
                    ->setLocationId( null )
                    ->setDayIndex( $day_index );
                $snapshot['days'][ $key ]['id'] = null;
            }
            self::saveDay( $item, $day );
            $snapshot['days'][ $key ]['item_id'] = (int) $item->getId();
        }

        return $snapshot;
    }

    /**
     * Undo write(): previous hours and breaks back, created rows deleted.
     *
     * @param array $snapshot
     */
    public static function restore( array $snapshot )
    {
        if ( empty( $snapshot['days'] ) ) {
            return;
        }
        foreach ( $snapshot['days'] as $day ) {
            $item = Lib\Entities\StaffScheduleItem::find( $day['item_id'], false );
            if ( ! $item ) {
                continue;
            }
            if ( $day['id'] === null ) {
                $item->delete();
                continue;
            }
            self::saveDay( $item, StaffData::dayShape( $day['start'], $day['end'], $day['breaks'] ) );
        }
    }

    /**
     * Set a schedule row's hours and replace its breaks.
     *
     * @param Lib\Entities\StaffScheduleItem $item
     * @param array|string                   $day
     */
    protected static function saveDay( Lib\Entities\StaffScheduleItem $item, $day )
    {
        if ( $item->getId() ) {
            Lib\Entities\ScheduleItemBreak::query()->delete()->where( 'staff_schedule_item_id', $item->getId() )->execute();
        }
        if ( is_array( $day ) ) {
            $item->setStartTime( StaffData::dbTime( $day['start'] ) )->setEndTime( StaffData::dbTime( $day['end'] ) );
        } else {
            $item->setStartTime( null )->setEndTime( null );
        }
        $item->save();
        if ( is_array( $day ) ) {
            foreach ( $day['breaks'] as $break ) {
                $row = new Lib\Entities\ScheduleItemBreak();
                $row
                    ->setStaffScheduleItemId( $item->getId() )
                    ->setStartTime( StaffData::dbTime( $break['start'] ) )
                    ->setEndTime( StaffData::dbTime( $break['end'] ) )
                    ->save();
            }
        }
    }

    /**
     * Breaks of a day that the change deletes, as card text.
     *
     * @param string       $key
     * @param array|string $before
     * @param array|string $after
     * @return string[]
     */
    public static function removedBreaks( $key, $before, $after )
    {
        $removed = array();
        if ( ! is_array( $before ) ) {
            return $removed;
        }
        foreach ( $before['breaks'] as $break ) {
            $kept = false;
            if ( is_array( $after ) ) {
                foreach ( $after['breaks'] as $new ) {
                    if ( $new['start'] === $break['start'] && $new['end'] === $break['end'] ) {
                        $kept = true;
                    }
                }
            }
            if ( ! $kept ) {
                $removed[] = StaffData::dayName( $key ) . ' ' . StaffData::interval( $break['start'], $break['end'] );
            }
        }

        return $removed;
    }

    /**
     * Card notes shared by the weekly schedule actions: time zone, removed breaks, appointments
     * that no longer fit, locations with their own schedule.
     *
     * @param mixed  $staff_id
     * @param State  $state
     * @param array  $before
     * @param array  $after
     * @param array  $removed
     * @param string $what
     * @return string[]
     */
    public static function notes( $staff_id, State $state, array $before, array $after, array $removed, $what )
    {
        $notes   = array();
        $profile = StaffData::profile( $staff_id, $state );
        if ( ! empty( $profile['time_zone'] ) ) {
            $notes[] = sprintf( __( 'Hours are in the staff member\'s time zone %s.', 'bookly-responsive-appointment-booking-tool' ), $profile['time_zone'] );
        }
        if ( $removed ) {
            $notes[] = sprintf( __( 'Breaks deleted: %s.', 'bookly-responsive-appointment-booking-tool' ), implode( ', ', $removed ) );
        }
        if ( ! StaffData::isNew( $staff_id, $state ) ) {
            $conflicts = StaffData::weeklyConflicts( (int) $staff_id, $before, $after );
            if ( $conflicts ) {
                $notes[] = StaffData::conflictsText( $conflicts );
            }
            $notes = array_merge( $notes, StaffData::notAffected( StaffData::ownLocations( (int) $staff_id, $what ) ) );
        }

        return $notes;
    }
}
