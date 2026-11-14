<?php
namespace Bookly\Lib\AiAdmin\Services;

use Bookly\Lib\AiAdmin\AllowedValues;

/**
 * Checks of working hours and breaks of one day in the shape {start, end, breaks} ("HH:MM"), as the
 * time selects of the schedule tabs offer them (Backend\Components\Schedule\Select): starts up to
 * 24:00 minus one grid step, ends up to 48:00, every time on the grid of bookly_gen_time_slot_length
 * unless it is the value stored at the same place.
 */
abstract class ScheduleCheck
{
    /**
     * Errors of one day.
     *
     * @param string     $where  "mon", "2026-12-24" — prefix of the messages
     * @param array      $day    array( start, end, breaks? )
     * @param array|null $stored the stored day in the same shape, or null
     * @param array|null $breaks breaks the day keeps when $day has none (stored breaks), or null
     * @return string[]
     */
    public static function day( $where, array $day, $stored, $breaks = null )
    {
        $errors = array();
        $stored_start = is_array( $stored ) ? $stored['start'] : null;
        $stored_end = is_array( $stored ) ? $stored['end'] : null;
        $start_error = self::time( $where . ' start', isset( $day['start'] ) ? $day['start'] : null, 'from', $stored_start );
        $end_error = self::time( $where . ' end', isset( $day['end'] ) ? $day['end'] : null, 'to', $stored_end );
        if ( $start_error || $end_error ) {
            return array_values( array_filter( array( $start_error, $end_error ) ) );
        }
        $start = AllowedValues::seconds( $day['start'] );
        $end = AllowedValues::seconds( $day['end'] );
        if ( $start >= $end ) {
            $errors[] = 'start_not_before_end: ' . $where . ' — the start is before the end';
        } elseif ( $end - $start > DAY_IN_SECONDS ) {
            $errors[] = 'day_longer_than_24h: ' . $where . ' — at most 24 hours';
        }
        if ( $errors ) {
            return $errors;
        }
        $given = array_key_exists( 'breaks', $day );
        $list = $given ? $day['breaks'] : (array) $breaks;
        if ( $given && ! is_array( $list ) ) {
            return array( 'break_start_not_before_end: ' . $where . ' — breaks is a list of {start, end}' );
        }
        $stored_breaks = array();
        foreach ( is_array( $stored ) && isset( $stored['breaks'] ) ? $stored['breaks'] : array() as $break ) {
            $stored_breaks[] = $break['start'];
            $stored_breaks[] = $break['end'];
        }
        $ranges = array();
        foreach ( array_values( $list ) as $i => $break ) {
            $label = $where . ' break ' . ( $i + 1 );
            if ( ! is_array( $break ) ) {
                $errors[] = 'break_start_not_before_end: ' . $label . ' is {start, end}';
                continue;
            }
            if ( $given ) {
                $b_start = isset( $break['start'] ) ? $break['start'] : null;
                $b_end = isset( $break['end'] ) ? $break['end'] : null;
                $error = self::time( $label . ' start', $b_start, 'break_from', in_array( $b_start, $stored_breaks, true ) ? $b_start : null )
                    ?: self::time( $label . ' end', $b_end, 'to', in_array( $b_end, $stored_breaks, true ) ? $b_end : null );
                if ( $error ) {
                    $errors[] = $error;
                    continue;
                }
            }
            $b_start = AllowedValues::seconds( $break['start'] );
            $b_end = AllowedValues::seconds( $break['end'] );
            if ( $b_start >= $b_end ) {
                $errors[] = 'break_start_not_before_end: ' . $label . ' — the start is before the end';
            } elseif ( $b_start < $start || $b_start >= $end || $b_end > $end ) {
                $errors[] = 'break_outside_hours: ' . $label . ' ' . $break['start'] . '–' . $break['end'] . ' is not inside ' . $day['start'] . '–' . $day['end']
                    . ( $given ? '' : ' (a kept break — give breaks for this day)' );
            } else {
                $ranges[] = array( $b_start, $b_end );
            }
        }
        usort( $ranges, function ( $a, $b ) {
            return $a[0] - $b[0];
        } );
        for ( $i = 1; $i < count( $ranges ); $i++ ) {
            if ( $ranges[ $i ][0] < $ranges[ $i - 1 ][1] ) {
                $errors[] = 'breaks_overlap: ' . $where . ' — breaks overlap';
                break;
            }
        }

        return $errors;
    }

    /**
     * Error of one time value, or null.
     *
     * @param string      $label
     * @param mixed       $value  "HH:MM"
     * @param string      $kind   from | to | break_from (TimeChoice types)
     * @param string|null $stored value stored at the same place
     * @return string|null
     */
    public static function time( $label, $value, $kind, $stored )
    {
        if ( ! is_string( $value ) || ! preg_match( '/^\d{2}:\d{2}$/', $value ) ) {
            return 'time_out_of_range: ' . $label . ' is "HH:MM"';
        }
        $grid = AllowedValues::of( array( 'values' => array( 'resolver' => array( 'id' => 'time_grid', 'type' => $kind ) ) ), $stored );
        $seconds = AllowedValues::seconds( $value );
        if ( $value !== $stored && ( $seconds < AllowedValues::seconds( $grid['grid']['from'] ) || $seconds > AllowedValues::seconds( $grid['grid']['to'] ) ) ) {
            return 'time_out_of_range: ' . $label . ' ' . $value . ' — the latest is ' . substr( $grid['grid']['to'], 0, 5 );
        }
        if ( ! AllowedValues::contains( $value, $grid ) ) {
            return 'time_off_grid: ' . $label . ' ' . $value . ' — times are on a ' . $grid['grid']['step'] . '-minute grid';
        }

        return null;
    }
}
