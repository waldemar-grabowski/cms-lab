<?php
namespace Bookly\Lib\Utils;

use Bookly\Lib\Entities\Holiday;
use Bookly\Lib\Entities\Staff;

/**
 * Class Holidays
 * Days off of the company and of the staff members.
 *
 * A day off is stored as a row in the holidays table, the `repeat_event` column tells
 * how the date of the row is treated:
 *   Holiday::TYPE_ONCE       day off on this date,
 *   Holiday::TYPE_YEARLY     day off on this month and day of every year,
 *   Holiday::TYPE_EXCEPTION  working day on this date despite the yearly day off.
 *
 * Rows of the company have staff_id = null, they are not taken into account when the
 * slots are calculated and serve as a source for the copies made for every staff member,
 * the copies refer to the company row with parent_id.
 *
 * There is at most one row per date and owner with TYPE_ONCE or TYPE_EXCEPTION, while a
 * yearly row may share the date with them, its date being just the year it was set in.
 */
class Holidays
{
    /**
     * Set days off of a staff member.
     *
     * @param string[] $days Format Y-m-d
     * @param int $staff_id
     * @param bool $repeat Day off every year
     * @return Holiday[] Saved days off indexed by date
     */
    public static function setDaysOff( array $days, $staff_id, $repeat )
    {
        // On a day excluded from a yearly day off the day off returns the day to it and the day
        // becomes a day off of the yearly kind again: without "every year" only the exception of
        // this date is deleted, with it every exception of that day is.
        $days = array_values( array_diff( $days, self::deleteExceptions( $days, $staff_id, $repeat ) ) );
        if ( ! $days ) {
            return array();
        }

        if ( ! $repeat ) {
            // A day off of this year only cancels the yearly day off it belonged to.
            self::deleteYearly( $days, $staff_id );

            return self::saveDaysOff( $days, $staff_id, false );
        }

        // A day already covered by a yearly day off needs no row of its own.
        $days = array_values( array_diff( $days, self::filterYearly( $days, $staff_id ) ) );

        return $days ? self::saveDaysOff( $days, $staff_id, true ) : array();
    }

    /**
     * Set working days of a staff member.
     *
     * @param string[] $days Format Y-m-d
     * @param int $staff_id
     * @param bool $repeat Working on these days every year, the yearly day off is cancelled
     * @return Holiday[] Created exceptions indexed by date
     */
    public static function setWorkingDays( array $days, $staff_id, $repeat = false )
    {
        if ( $repeat ) {
            self::deleteYearly( $days, $staff_id );
        }
        self::deleteOnDates( $days, $staff_id );

        // The days still covered by a yearly day off are excluded from it for their year only.
        return self::createExceptions( $days, $staff_id );
    }

    /**
     * Set days off of the company and of every staff member.
     *
     * @param string[] $days Format Y-m-d
     * @param bool $repeat Day off every year
     * @return void
     */
    public static function setCompanyDaysOff( array $days, $repeat )
    {
        $staff_ids = self::getStaffIds();

        // On a day excluded from a yearly day off the day off returns the day to it, here and for
        // the staff: without "every year" only the exception of this date, with it every exception
        // of that day.
        $excluded = self::deleteExceptions( $days, null, $repeat );
        foreach ( $staff_ids as $staff_id ) {
            self::deleteExceptions( $days, $staff_id, $repeat );
        }
        $days = array_values( array_diff( $days, $excluded ) );
        if ( ! $days ) {
            return;
        }

        if ( ! $repeat ) {
            // A day off of this year only cancels the yearly days off of the company together with
            // the copies made for the staff.
            self::deleteChildren( self::deleteYearly( $days, null ) );
        } else {
            // A day already covered by a yearly day off needs no row of its own.
            $days = array_values( array_diff( $days, self::filterYearly( $days, null ) ) );
            if ( ! $days ) {
                return;
            }
        }

        $holidays = self::saveDaysOff( $days, null, $repeat );
        $parents = self::getIds( $holidays );
        foreach ( $staff_ids as $staff_id ) {
            self::saveDaysOff( $days, $staff_id, $repeat, $parents );
        }
    }

    /**
     * Set working days of the company and of every staff member.
     *
     * @param string[] $days Format Y-m-d
     * @param bool $repeat Working on these days every year, the yearly day off is cancelled
     * @return void
     */
    public static function setCompanyWorkingDays( array $days, $repeat = false )
    {
        if ( $repeat ) {
            // Cancel the yearly days off of the company together with the copies made for the staff.
            self::deleteChildren( self::deleteYearly( $days, null ) );
        }

        // Delete the days off set on these dates together with the copies made for the staff.
        $ids = self::getIds( self::findOnDates( $days, null ) );
        if ( $ids ) {
            self::deleteChildren( $ids );
            Holiday::query()->delete()->whereIn( 'id', $ids )->execute();
        }

        // Exclude these days from the yearly days off of the company.
        $exceptions = self::createExceptions( $days, null );
        if ( $exceptions ) {
            $parents = self::getIds( $exceptions );
            $days = array_keys( $exceptions );
            foreach ( self::getStaffIds() as $staff_id ) {
                self::createExceptions( $days, $staff_id, $parents );
            }
        }
    }

    /**
     * Save days off on given dates reusing the rows which are already there.
     *
     * @param string[] $days Format Y-m-d
     * @param int $staff_id
     * @param bool $repeat
     * @param array $parents Parent ids indexed by date
     * @return Holiday[] Saved days off indexed by date
     */
    protected static function saveDaysOff( array $days, $staff_id, $repeat, array $parents = array() )
    {
        $result = array();
        $existing = self::findOnDates( $days, $staff_id );
        foreach ( $days as $day ) {
            $holiday = isset( $existing[ $day ] ) ? $existing[ $day ] : new Holiday();
            $holiday
                ->setStaffId( $staff_id )
                ->setDate( $day )
                ->setRepeatEvent( $repeat ? Holiday::TYPE_YEARLY : Holiday::TYPE_ONCE );
            if ( isset( $parents[ $day ] ) ) {
                $holiday->setParentId( $parents[ $day ] );
            }
            $holiday->save();
            $result[ $day ] = $holiday;
        }

        return $result;
    }

    /**
     * Exclude the days covered by a yearly day off from it.
     *
     * @param string[] $days Format Y-m-d
     * @param int $staff_id
     * @param array $parents Parent ids indexed by date
     * @return Holiday[] Created exceptions indexed by date
     */
    protected static function createExceptions( array $days, $staff_id, array $parents = array() )
    {
        $result = array();
        $existing = self::findOnDates( $days, $staff_id );
        foreach ( self::filterYearly( $days, $staff_id ) as $day ) {
            if ( isset( $existing[ $day ] ) ) {
                // The day is already a day off on its own.
                continue;
            }
            $exception = new Holiday();
            $exception
                ->setStaffId( $staff_id )
                ->setDate( $day )
                ->setRepeatEvent( Holiday::TYPE_EXCEPTION );
            if ( isset( $parents[ $day ] ) ) {
                $exception->setParentId( $parents[ $day ] );
            }
            $exception->save();
            $result[ $day ] = $exception;
        }

        return $result;
    }

    /**
     * Find the rows bound to given dates, the yearly ones are not included.
     *
     * @param string[] $days Format Y-m-d
     * @param int $staff_id
     * @return Holiday[] Indexed by date
     */
    protected static function findOnDates( array $days, $staff_id )
    {
        if ( ! $days ) {
            return array();
        }

        return Holiday::query( 'h' )
            ->where( 'h.staff_id', $staff_id )
            ->whereNot( 'h.repeat_event', Holiday::TYPE_YEARLY )
            ->whereIn( 'h.date', $days )
            ->indexBy( 'date' )
            ->find();
    }

    /**
     * Delete the rows bound to given dates, the yearly ones are kept.
     *
     * @param string[] $days Format Y-m-d
     * @param int $staff_id
     * @return void
     */
    protected static function deleteOnDates( array $days, $staff_id )
    {
        if ( $days ) {
            Holiday::query( 'h' )
                ->delete()
                ->where( 'h.staff_id', $staff_id )
                ->whereNot( 'h.repeat_event', Holiday::TYPE_YEARLY )
                ->whereIn( 'h.date', $days )
                ->execute();
        }
    }

    /**
     * Delete the exceptions of given days: the ones set on these dates, or the ones of these
     * days in every year.
     *
     * @param string[] $days Format Y-m-d
     * @param int $staff_id
     * @param bool $every_year
     * @return string[] Those of given days which were excluded from a yearly day off
     */
    protected static function deleteExceptions( array $days, $staff_id, $every_year = false )
    {
        if ( ! $days ) {
            return array();
        }

        $month_days = array();
        foreach ( $days as $day ) {
            $month_days[] = substr( $day, 5 );
        }

        $ids = array();
        $excluded = array();
        $rows = Holiday::query( 'h' )
            ->select( 'h.id, h.date' )
            ->where( 'h.staff_id', $staff_id )
            ->where( 'h.repeat_event', Holiday::TYPE_EXCEPTION )
            ->fetchArray();
        foreach ( $rows as $row ) {
            $matched = $every_year
                ? in_array( substr( $row['date'], 5 ), $month_days, true )
                : in_array( $row['date'], $days, true );
            if ( $matched ) {
                $ids[] = $row['id'];
                $excluded[] = $row['date'];
            }
        }
        if ( $ids ) {
            Holiday::query()->delete()->whereIn( 'id', $ids )->execute();
        }

        return array_values( array_intersect( $days, $excluded ) );
    }

    /**
     * Delete the yearly days off which cover given dates together with their exceptions.
     *
     * @param string[] $days Format Y-m-d
     * @param int $staff_id
     * @return array Ids of the deleted rows
     */
    protected static function deleteYearly( array $days, $staff_id )
    {
        $month_days = array();
        foreach ( $days as $day ) {
            $month_days[] = substr( $day, 5 );
        }

        $ids = array();
        $rows = Holiday::query( 'h' )
            ->select( 'h.id, h.date' )
            ->where( 'h.staff_id', $staff_id )
            ->whereNot( 'h.repeat_event', Holiday::TYPE_ONCE )
            ->fetchArray();
        foreach ( $rows as $row ) {
            if ( in_array( substr( $row['date'], 5 ), $month_days, true ) ) {
                $ids[] = $row['id'];
            }
        }
        if ( $ids ) {
            Holiday::query()->delete()->whereIn( 'id', $ids )->execute();
        }

        return $ids;
    }

    /**
     * Delete the copies made for the staff from given company rows.
     *
     * @param array $parent_ids
     * @return void
     */
    protected static function deleteChildren( array $parent_ids )
    {
        if ( $parent_ids ) {
            Holiday::query( 'h' )
                ->delete()
                ->whereIn( 'h.parent_id', $parent_ids )
                ->execute();
        }
    }

    /**
     * Get the days which are covered by a yearly day off.
     *
     * @param string[] $days Format Y-m-d
     * @param int $staff_id
     * @return string[]
     */
    protected static function filterYearly( array $days, $staff_id )
    {
        $month_days = array();
        $dates = Holiday::query( 'h' )
            ->where( 'h.staff_id', $staff_id )
            ->where( 'h.repeat_event', Holiday::TYPE_YEARLY )
            ->fetchCol( 'date' );
        foreach ( $dates as $date ) {
            $month_days[] = substr( $date, 5 );
        }

        $result = array();
        foreach ( $days as $day ) {
            if ( in_array( substr( $day, 5 ), $month_days, true ) ) {
                $result[] = $day;
            }
        }

        return $result;
    }

    /**
     * @param Holiday[] $holidays
     * @return array Ids indexed by date
     */
    protected static function getIds( array $holidays )
    {
        $ids = array();
        foreach ( $holidays as $date => $holiday ) {
            $ids[ $date ] = $holiday->getId();
        }

        return $ids;
    }

    /**
     * @return array
     */
    protected static function getStaffIds()
    {
        return Staff::query()->whereNot( 'visibility', 'archive' )->fetchCol( 'id' );
    }
}
