<?php
namespace Bookly\Frontend\Modules\Ai\BookingTools;

use Bookly\Frontend\Modules\Ai;
use Bookly\Lib;

/**
 * When the business is open: Settings > Business Hours and the company's days off from
 * Settings > Holidays. A tool and not a part of the prompt: few conversations ask, and a
 * question can be about any date. Staff members' own schedules are not given - Bookly shows
 * them to customers nowhere; what can be booked comes from get_available_slots.
 */
class GetBusinessHours implements ToolInterface
{
    const NAME = 'get_business_hours';

    // The longest period one call answers for, and the default one.
    const MAX_DAYS = 90;
    const DEFAULT_DAYS = 14;

    /**
     * @inheritDoc
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * @inheritDoc
     *
     * date_from is required for the Cloud-side bug described in GetServices::getSchema().
     */
    public function getSchema()
    {
        return array(
            'name'        => $this->getName(),
            'description' => 'The business\'s own opening hours by day of the week and its days off between two dates, from its settings. For questions about when the business is open. A day off cancels only the hours that start on it: hours of the day before with ends_next_day still run to their end. A staff member\'s own hours can be narrower; times that can be booked come only from get_available_slots.',
            'parameters'  => array(
                'type'       => 'object',
                'properties' => array(
                    'date_from' => array( 'type' => 'string', 'description' => 'First date of the period, format "YYYY-MM-DD".' ),
                    'date_to'   => array( 'type' => 'string', 'description' => 'Last date of the period, format "YYYY-MM-DD", at most ' . self::MAX_DAYS . ' days after date_from. Omit for ' . self::DEFAULT_DAYS . ' days.' ),
                ),
                'required'   => array( 'date_from' ),
            ),
        );
    }

    /**
     * @inheritDoc
     */
    public function execute( array $arguments )
    {
        $from = self::date( isset( $arguments['date_from'] ) ? $arguments['date_from'] : '' );
        if ( ! $from ) {
            return 'Error: date_from must be in the format YYYY-MM-DD.';
        }
        if ( isset( $arguments['date_to'] ) && $arguments['date_to'] !== '' ) {
            $to = self::date( $arguments['date_to'] );
            if ( ! $to ) {
                return 'Error: date_to must be in the format YYYY-MM-DD.';
            }
            if ( $to < $from ) {
                return 'Error: date_to is before date_from.';
            }
            $days = (int) $from->diff( $to )->days + 1;
            if ( $days > self::MAX_DAYS ) {
                return 'Error: the period is longer than ' . self::MAX_DAYS . ' days. Ask for a shorter one.';
            }
        } else {
            $days = self::DEFAULT_DAYS;
        }

        $hours = self::hours();
        if ( ! array_filter( $hours, 'is_array' ) ) {
            return 'The business has not set its opening hours. Tell the customer you don\'t have that information; for a particular day, look for free times with get_available_slots.';
        }

        return Ai\Catalog::json( array(
            'hours'    => $hours,
            'days_off' => self::daysOff( $from, $days ),
            'period'   => array( 'from' => $from->format( 'Y-m-d' ), 'days' => $days ),
        ) );
    }

    /**
     * Settings > Business Hours by day of the week, Monday first: "HH:MM" start and end, or
     * "closed". The settings keep a closing time past midnight as "26:00:00": it goes out as
     * "02:00" with ends_next_day.
     *
     * @return array
     */
    public static function hours()
    {
        $names = array( 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday' );
        $hours = array();
        foreach ( Lib\Config::getBusinessHours() as $index => $bh ) {
            if ( $bh['start'] === null || $bh['end'] === null ) {
                $hours[ $names[ $index ] ] = 'closed';
                continue;
            }
            $day = array( 'start' => substr( $bh['start'], 0, 5 ), 'end' => substr( $bh['end'], 0, 5 ) );
            $end_hour = (int) $bh['end'];
            if ( $end_hour >= 24 ) {
                $day['end'] = sprintf( '%02d', $end_hour - 24 ) . substr( $bh['end'], 2, 3 );
                $day['ends_next_day'] = true;
            }
            $hours[ $names[ $index ] ] = $day;
        }
        // Monday first, as people read a week.
        $sunday = array( 'sunday' => $hours['sunday'] );
        unset( $hours['sunday'] );

        return $hours + $sunday;
    }

    /**
     * The company's days off (Settings > Holidays) in the period, as YYYY-MM-DD: a one-time
     * day off, or a yearly one unless that date is excluded from it - as Staff::isOnHoliday()
     * reads a staff member's.
     *
     * @param \DateTime $from
     * @param int       $days
     * @return string[]
     */
    public static function daysOff( \DateTime $from, $days )
    {
        $once = $yearly = $excluded = array();
        foreach ( Lib\Entities\Holiday::query()->where( 'staff_id', null )->fetchArray() as $holiday ) {
            switch ( (int) $holiday['repeat_event'] ) {
                case Lib\Entities\Holiday::TYPE_YEARLY:
                    $yearly[ substr( $holiday['date'], 5 ) ] = true;
                    break;
                case Lib\Entities\Holiday::TYPE_EXCEPTION:
                    $excluded[ $holiday['date'] ] = true;
                    break;
                default:
                    $once[ $holiday['date'] ] = true;
            }
        }

        $off = array();
        $day = clone $from;
        for ( $i = 0; $i < $days; $i ++ ) {
            $date = $day->format( 'Y-m-d' );
            if ( isset( $once[ $date ] ) || ( isset( $yearly[ $day->format( 'm-d' ) ] ) && ! isset( $excluded[ $date ] ) ) ) {
                $off[] = $date;
            }
            $day->modify( '+1 day' );
        }

        return $off;
    }

    /**
     * @param mixed $value
     * @return \DateTime|null
     */
    private static function date( $value )
    {
        $value = trim( (string) $value );
        $date = \DateTime::createFromFormat( '!Y-m-d', $value );

        return $date && $date->format( 'Y-m-d' ) === $value ? $date : null;
    }
}
