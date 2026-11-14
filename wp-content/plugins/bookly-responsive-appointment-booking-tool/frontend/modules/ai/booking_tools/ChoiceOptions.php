<?php
namespace Bookly\Frontend\Modules\Ai\BookingTools;

use Bookly\Frontend\Modules\Ai;
use Bookly\Lib;

/**
 * Not a tool itself - which options an offer_choices call shows: the ids the model passed,
 * turned into real services, staff members, locations or bookable start times. An id that is
 * no such thing is dropped, never shown: a card always shows something real.
 *
 * An option is {id, label, group?, time?}.
 */
class ChoiceOptions
{
    /**
     * @param string $type One of OfferChoices::TYPE_*
     * @param array  $arguments The model's arguments: ids, or groups of them
     * @return array[]|string The options in the order to show, or an error for the model
     */
    public static function find( $type, array $arguments )
    {
        $groups = isset( $arguments['groups'] ) && is_array( $arguments['groups'] ) ? $arguments['groups'] : array();
        if ( $groups && $type !== OfferChoices::TYPE_SLOT ) {
            return self::grouped( $type, $groups, $arguments );
        }
        $ids = isset( $arguments['ids'] ) && is_array( $arguments['ids'] ) ? $arguments['ids'] : array();

        return self::forIds( $type, $ids, $arguments );
    }

    /**
     * Each group as its ids alone would show, under its title. An option may be in more than
     * one group. A group left with no valid id is dropped.
     *
     * @param string  $type
     * @param array[] $groups [{title, ids}]
     * @param array   $arguments
     * @return array[]|string
     */
    private static function grouped( $type, array $groups, array $arguments )
    {
        $options = array();
        foreach ( $groups as $group ) {
            if ( ! is_array( $group ) || ! isset( $group['title'], $group['ids'] ) || ! is_array( $group['ids'] ) ) {
                continue;
            }
            $found = self::forIds( $type, $group['ids'], $arguments );
            if ( is_string( $found ) ) {
                return $found;
            }
            foreach ( $found as $option ) {
                $option['group'] = trim( (string) $group['title'] );
                $options[] = $option;
            }
        }

        return $options;
    }

    /**
     * @param string $type
     * @param array  $ids
     * @param array  $arguments
     * @return array[]|string
     */
    private static function forIds( $type, array $ids, array $arguments )
    {
        switch ( $type ) {
            case OfferChoices::TYPE_SERVICE:
                $options = self::services( $ids );
                break;
            case OfferChoices::TYPE_STAFF:
                $options = self::staff( $ids );
                break;
            case OfferChoices::TYPE_LOCATION:
                $options = self::locations( $ids );
                break;
            case OfferChoices::TYPE_SLOT:
                $options = self::slots( $ids, $arguments );
                break;
            default:
                return 'Error: unknown type "' . $type . '".';
        }

        return is_string( $options ) ? $options : self::unique( $options );
    }

    /**
     * The same id twice would be the same card twice - the first stays. Options that only
     * share a label all stay: they are different services or people, ChoiceCards tells them
     * apart.
     *
     * @param array[] $options
     * @return array[]
     */
    private static function unique( array $options )
    {
        $unique = array();
        foreach ( $options as $option ) {
            if ( ! isset( $unique[ $option['id'] ] ) ) {
                $unique[ $option['id'] ] = $option;
            }
        }

        return array_values( $unique );
    }

    /**
     * Same visibility rules as GetServices: nothing the model could not have listed.
     *
     * @param array $ids
     * @return array[]
     */
    private static function services( array $ids )
    {
        $options = array();
        foreach ( $ids as $id ) {
            $service = Lib\Entities\Service::find( (int) $id );
            if ( $service && $service->getType() === Lib\Entities\Service::TYPE_SIMPLE && $service->getVisibility() === Lib\Entities\Service::VISIBILITY_PUBLIC ) {
                $options[] = array( 'id' => (string) $service->getId(), 'label' => $service->getTitle() );
            }
        }

        return $options;
    }

    /**
     * @param array $ids
     * @return array[]
     */
    private static function staff( array $ids )
    {
        $options = array();
        foreach ( $ids as $id ) {
            $staff = Lib\Entities\Staff::find( (int) $id );
            if ( $staff && $staff->getVisibility() === 'public' ) {
                $options[] = array( 'id' => (string) $staff->getId(), 'label' => $staff->getFullName() );
            }
        }

        return $options;
    }

    /**
     * The locations the catalog lists, named as it names them (Locations::entries()) - not
     * one no bookable staff member works at.
     *
     * @param array $ids
     * @return array[]
     */
    private static function locations( array $ids )
    {
        if ( ! Ai\Concepts::forSite()->has( Ai\Concepts::LOCATION ) ) {
            return array();
        }
        $names = array();
        foreach ( Locations::entries() as $location ) {
            $names[ (int) $location['id'] ] = $location['name'];
        }

        $options = array();
        foreach ( $ids as $id ) {
            if ( isset( $names[ (int) $id ] ) ) {
                $options[] = array( 'id' => (string) (int) $id, 'label' => $names[ (int) $id ] );
            }
        }

        return $options;
    }

    /**
     * Start times the slot engine offers with this staff member and service (and location),
     * in the business's own format, as get_available_slots lists them. Over several days a
     * time's label carries its date (it is what a click sends) and the time goes under a
     * heading of that date; on one day the date is already in the text, "10:00" alone is what
     * the customer would type. create_booking still checks the time before it books it.
     *
     * @param array $ids "Y-m-d H:i:s" or "Y-m-d H:i"
     * @param array $arguments service_id and staff_id, location_id if any
     * @return array[]|string
     */
    private static function slots( array $ids, array $arguments )
    {
        $service     = isset( $arguments['service_id'] ) ? Lib\Entities\Service::find( (int) $arguments['service_id'] ) : null;
        $staff_id    = isset( $arguments['staff_id'] ) ? (int) $arguments['staff_id'] : 0;
        $location_id = isset( $arguments['location_id'] ) ? (int) $arguments['location_id'] : 0;
        if ( ! $service || ! $staff_id ) {
            return 'Error: for "slot" pass service_id and staff_id - the start times are checked against them. Nothing was shown.';
        }

        $times = self::bookableTimes( $ids, $service, $staff_id, $location_id );
        // With custom settings per location a staff member's services and schedule are per
        // location: without one there are no times at all.
        if ( ! $times && ! $location_id && Lib\Proxy\Locations::servicesPerLocationAllowed() && Lib\Proxy\Locations::findByStaffId( $staff_id ) ) {
            return 'Error: for "slot" pass location_id - the location these start times were found at in get_available_slots. If the customer has not chosen a location yet, ask them. Nothing was shown.';
        }

        $days = array_unique( array_map( function ( $time ) {
            return substr( $time, 0, 10 );
        }, $times ) );

        $options = array();
        foreach ( $times as $time ) {
            if ( count( $days ) > 1 ) {
                $options[] = array(
                    'id'    => $time,
                    'label' => Lib\Utils\DateTime::formatDateTime( $time ),
                    'group' => Lib\Utils\DateTime::formatDate( $time ),
                    'time'  => Lib\Utils\DateTime::formatTime( $time ),
                );
            } else {
                $options[] = array( 'id' => $time, 'label' => Lib\Utils\DateTime::formatTime( $time ) );
            }
        }

        return $options;
    }

    /**
     * The ones among $ids the slot engine offers that day - checked without extras, which can
     * only take times away, and for the fewest persons the staff member takes (a group service
     * has no times for one; times with room for more are among these).
     *
     * @param array                $ids
     * @param Lib\Entities\Service $service
     * @param int                  $staff_id
     * @param int                  $location_id 0 when none
     * @return string[] "Y-m-d H:i:s"
     */
    private static function bookableTimes( array $ids, Lib\Entities\Service $service, $staff_id, $location_id )
    {
        list( $min_persons ) = SlotLookup::personsRange( $service, array( $staff_id ), $location_id );

        $by_day = array();
        $times  = array();
        foreach ( $ids as $id ) {
            $id    = trim( (string) $id );
            $point = \DateTime::createFromFormat( 'Y-m-d H:i:s', $id ) ?: \DateTime::createFromFormat( 'Y-m-d H:i', $id );
            if ( ! $point ) {
                continue;
            }
            $day = $point->format( 'Y-m-d' );
            if ( ! isset( $by_day[ $day ] ) ) {
                $by_day[ $day ] = SlotLookup::slotsForDay( $service, $staff_id, $day, $location_id ?: null, array(), $min_persons );
            }
            if ( in_array( $point->format( 'Y-m-d H:i:s' ), $by_day[ $day ], true ) ) {
                $times[] = $point->format( 'Y-m-d H:i:s' );
            }
        }

        return $times;
    }
}
