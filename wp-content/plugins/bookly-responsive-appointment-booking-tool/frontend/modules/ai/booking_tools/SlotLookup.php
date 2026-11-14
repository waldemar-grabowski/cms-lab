<?php
namespace Bookly\Frontend\Modules\Ai\BookingTools;

use Bookly\Lib;

class SlotLookup
{
    const PERSONS_ARGUMENT = 'How many people the booking is for, within the service\'s "persons" range in the catalog. Omit for one.';

    /**
     * Bookable start times for one staff/service/day ("Y-m-d H:i:s", WP time zone).
     *
     * Goes through Lib\Utils\Appointment::getDaySchedule() — the widget's own slot engine
     * (Lib\Slots\Finder), which also sees external calendar events, Bookly Events, padding,
     * the slot grid etc. — unlike Appointment::checkTime(), which only sees bookly_appointments.
     *
     * @param Lib\Entities\Service $service
     * @param int $staff_id
     * @param string $date "Y-m-d"
     * @param int|null $location_id
     * @param array $extras [extra_id => quantity], from ExtrasInput::parse()
     * @param int $nop Persons the booking is for: times with room for all of them
     * @return string[]
     */
    public static function slotsForDay( Lib\Entities\Service $service, $staff_id, $date, $location_id = null, array $extras = array(), $nop = 1 )
    {
        // Scheduler lets one day past the booking form's window through.
        if ( $date > self::lastBookableDate() ) {
            return array();
        }

        $schedule = Lib\Utils\Appointment::getDaySchedule(
            array( (int) $staff_id ),
            $service->getId(),
            $date,
            0,
            $location_id ?: null,
            array( $extras ), // per-customer list, getDaySchedule() keeps the longest one
            $nop
        );

        $times = array();
        foreach ( $schedule['start'] as $option ) {
            if ( empty( $option['disabled'] ) ) {
                $times[] = $date . ' ' . $option['value'] . ':00';
            }
        }

        return $times;
    }

    /**
     * The first day after $date with bookable start times, up to the booking horizon.
     *
     * @param Lib\Entities\Service $service
     * @param int $staff_id
     * @param string $date "Y-m-d"
     * @param int|null $location_id
     * @param array $extras [extra_id => quantity], from ExtrasInput::parse()
     * @param int $nop
     * @return string|null "Y-m-d"
     */
    public static function nextDayWithSlots( Lib\Entities\Service $service, $staff_id, $date, $location_id = null, array $extras = array(), $nop = 1 )
    {
        $chain_item = new Lib\ChainItem();
        $chain_item
            ->setStaffIds( array( (int) $staff_id ) )
            ->setServiceId( $service->getId() )
            ->setLocationId( $location_id ?: null )
            ->setNumberOfPersons( $nop )
            ->setQuantity( 1 )
            ->setUnits( 1 )
            ->setExtras( $extras );

        $userData = new Lib\UserBookingData( null );
        $userData->resetChain();
        $userData->chain = new Lib\Chain();
        $userData->chain->add( $chain_item );
        $userData->setDays( array( 1, 2, 3, 4, 5, 6, 7 ) );

        $next_day = date_create( $date )->modify( '+1 day' )->format( 'Y-m-d' );
        $slots = Lib\Slots\Finder::forCustomer( $userData, $next_day )->getSlots();

        return $slots ? key( $slots ) : null;
    }

    /**
     * The nearest day before $date with bookable start times, for when nothing
     * is left between $date and the end of the booking window. Day by day
     * through the week before (the customer's date is the anchor), then the
     * first day of the window that has any.
     *
     * @param Lib\Entities\Service $service
     * @param int $staff_id
     * @param string $date "Y-m-d"
     * @param int|null $location_id
     * @param array $extras [extra_id => quantity], from ExtrasInput::parse()
     * @param int $nop
     * @return string|null "Y-m-d"
     */
    public static function previousDayWithSlots( Lib\Entities\Service $service, $staff_id, $date, $location_id = null, array $extras = array(), $nop = 1 )
    {
        $today = Lib\Slots\DatePoint::now()->format( 'Y-m-d' );
        $day = min( date( 'Y-m-d', strtotime( $date . ' -1 day' ) ), self::lastBookableDate() );
        for ( $i = 0; $i < 7 && $day >= $today; $i++ ) {
            if ( self::slotsForDay( $service, $staff_id, $day, $location_id, $extras, $nop ) ) {
                return $day;
            }
            $day = date( 'Y-m-d', strtotime( $day . ' -1 day' ) );
        }

        return $day >= $today
            ? self::nextDayWithSlots( $service, $staff_id, date( 'Y-m-d', strtotime( $today . ' -1 day' ) ), $location_id, $extras, $nop )
            : null;
    }

    /**
     * Persons one booking may be for: from the smallest minimum to the largest maximum of the
     * staff members' own rows (per location, else the general row), as the booking form
     * offers it for "Any" (Config::getCaSeSt()); [1, 1] without Group Booking.
     *
     * Not Service::getPersonsRange(): that widens the range by the service's own template,
     * which only fills new staff rows - slots hold what the staff member's row says, so a
     * service whose staff take groups of 2 or more has no times for one person.
     *
     * @param Lib\Entities\Service $service
     * @param int[] $staff_ids
     * @param int $location_id
     * @return int[] [ min, max ]
     */
    public static function personsRange( Lib\Entities\Service $service, array $staff_ids, $location_id = 0 )
    {
        if ( ! Lib\Config::groupBookingActive() ) {
            return array( 1, 1 );
        }

        $min = null;
        $max = 1;
        foreach ( $staff_ids as $staff_id ) {
            $row = new Lib\Entities\StaffService();
            $staff_location_id = $location_id ? ( Lib\Proxy\Locations::prepareStaffLocationId( $location_id, $staff_id ) ?: null ) : null;
            if ( $staff_location_id ) {
                $row->loadBy( array( 'staff_id' => $staff_id, 'service_id' => $service->getId(), 'location_id' => $staff_location_id ) );
            }
            if ( ! $row->isLoaded() ) {
                $row->loadBy( array( 'staff_id' => $staff_id, 'service_id' => $service->getId(), 'location_id' => null ) );
            }
            if ( $row->isLoaded() ) {
                $row_min = max( 1, (int) $row->getCapacityMin() );
                $min = $min === null ? $row_min : min( $min, $row_min );
                $max = max( $max, $row_min, (int) $row->getCapacityMax() );
            }
        }

        return array( $min === null ? 1 : $min, $max );
    }

    /**
     * Persons a booking may be for with this staff member (personsRange()), 1 without Group Booking.
     *
     * @param Lib\Entities\Service $service
     * @param int $staff_id
     * @param int $location_id
     * @param int $nop
     * @return string|null Error for the model, null when fine
     */
    public static function checkPersons( Lib\Entities\Service $service, $staff_id, $location_id, $nop )
    {
        list( $min, $max ) = self::personsRange( $service, array( $staff_id ), $location_id );
        if ( $nop >= $min && $nop <= $max ) {
            return null;
        }

        return $max > 1
            ? sprintf( 'Error: with this staff member one booking of this service is for %d to %d people, not %d. Ask the customer how many people will come if they have not said it.', $min, $max, $nop )
            : 'Error: this service is booked for one person at a time - each person needs a booking of their own.';
    }

    /**
     * The last day the booking form offers: Finder ends the customer's window
     * at midnight "number of days available for booking" ahead.
     *
     * @return string "Y-m-d"
     */
    public static function lastBookableDate()
    {
        return Lib\Slots\DatePoint::now()
            ->modify( Lib\Config::getMaximumAvailableDaysForBooking() . ' days midnight' )
            ->modify( '-1 day' )
            ->format( 'Y-m-d' );
    }

    /**
     * Minimum time prior booking as the customer would say it: over a day in
     * days ("7 day(s)"), otherwise in hours, so the model doesn't relay "168 hour(s)".
     *
     * @param int $seconds
     * @return string
     */
    public static function describeNotice( $seconds )
    {
        return $seconds > DAY_IN_SECONDS
            ? round( $seconds / DAY_IN_SECONDS, 1 ) . ' day(s)'
            : round( $seconds / HOUR_IN_SECONDS, 1 ) . ' hour(s)';
    }

    /**
     * Why a day has no bookable times when the cause is the booking window, not
     * a busy schedule: the service's minimum time prior booking, the business's
     * booking horizon, or both together leaving no window at all. Neither depends
     * on the staff member, so the model should not go through other staff.
     *
     * @param Lib\Entities\Service $service
     * @param string $date "Y-m-d"
     * @return string|null
     */
    public static function describeWindow( Lib\Entities\Service $service, $date )
    {
        $days = Lib\Config::getMaximumAvailableDaysForBooking();
        $last = self::lastBookableDate();
        $min_prior = (int) Lib\Proxy\Pro::getMinimumTimePriorBooking( $service->getId() );
        $earliest = Lib\Slots\DatePoint::now()->modify( $min_prior );
        $horizon = 'this business takes bookings at most ' . $days . ' day(s) ahead, the last bookable date is ' . $last;
        $notice = 'this service must be booked at least ' . self::describeNotice( $min_prior ) . ' in advance';

        if ( $earliest->format( 'Y-m-d' ) > $last ) {
            return 'this service cannot be booked online on any date right now: ' . $notice . ', but ' . $horizon . '. This is the same for every staff member';
        }
        if ( $date > $last ) {
            return 'the date is too far ahead: ' . $horizon . '; bookings for ' . $date . ' open on ' . date( 'Y-m-d', strtotime( $date . ' -' . ( $days - 1 ) . ' days' ) )
                . '. This is the same for every staff member. Tell the customer bookings are taken only ' . $days . ' day(s) ahead, and offer the nearest times up to ' . $last
                . ' (get_available_slots for ' . $last . ' finds them) rather than asking them to come back later';
        }
        if ( $min_prior > 0 && $date <= $earliest->format( 'Y-m-d' ) ) {
            return $notice . ', the earliest possible start is ' . $earliest->format( 'Y-m-d H:i' );
        }

        return null;
    }

    /**
     * Whether the widget would offer a slot starting exactly at $start.
     *
     * @param Lib\Entities\Service $service
     * @param int $staff_id
     * @param \DateTime $start WP time zone
     * @param int|null $location_id
     * @param array $extras
     * @param string[] $day_times Out: all bookable starts that day, for suggesting alternatives
     * @param int $nop
     * @return bool
     */
    public static function isBookable( Lib\Entities\Service $service, $staff_id, \DateTime $start, $location_id, array $extras, &$day_times = array(), $nop = 1 )
    {
        $day_times = self::slotsForDay( $service, $staff_id, $start->format( 'Y-m-d' ), $location_id, $extras, $nop );

        return in_array( $start->format( 'Y-m-d H:i:s' ), $day_times, true );
    }

    /**
     * One day's start times, all of them, as "HH:MM, HH:MM, ...". The date is
     * said once by the caller rather than on every time: a day at a 15-minute
     * grid is some forty times, and only the whole day lets the model answer
     * "something after lunch" - the first dozen would all be morning.
     *
     * @param string[] $day_times "Y-m-d H:i:s", one day, in order
     * @return string
     */
    public static function listTimes( array $day_times )
    {
        return implode( ', ', array_map( function ( $time ) {
            return substr( $time, 11, 5 );
        }, $day_times ) );
    }

    /**
     * Alternatives for a "not available" reply: the bookable times right before
     * and after the one asked for, then the whole day. The nearest ones come
     * first because a customer who asked for 15:00 means "around 15:00", not
     * the first free time in the morning.
     *
     * @param string[] $day_times
     * @param \DateTime $wanted The start the customer asked for
     * @param Lib\Entities\Service $service
     * @return string
     */
    public static function describeAlternatives( array $day_times, \DateTime $wanted, Lib\Entities\Service $service )
    {
        if ( ! $day_times ) {
            $why = self::describeWindow( $service, $wanted->format( 'Y-m-d' ) );

            return $why
                ? 'There are no bookable times that day: ' . $why . '.'
                : 'There are no bookable times that day at all — suggest a different date or staff member.';
        }

        // "Y-m-d H:i:s" strings of one day compare in time order.
        $wanted = $wanted->format( 'Y-m-d H:i:s' );
        $before = null;
        $after  = null;
        foreach ( $day_times as $time ) {
            if ( $time < $wanted ) {
                $before = $time;
            } elseif ( $after === null ) {
                $after = $time;
            }
        }
        $nearest = array_values( array_filter( array( $before, $after ) ) );

        return 'Nearest bookable start times that day: ' . self::listTimes( $nearest ) . '. All bookable start times that day: ' . self::listTimes( $day_times )
            . '. Offer the customer the nearest ones, or others that fit what they asked for.';
    }
}
