<?php
namespace Bookly\Frontend\Modules\Ai\BookingTools;

use Bookly\Lib;

/**
 * Not a tool itself — shared extras-argument parsing for
 * CheckAvailability/CreateBooking. Converts the JSON-Schema-friendly
 * [{"extra_id":.., "quantity":..}, ...] shape the model sends into the
 * internal [extra_id => quantity] shape Lib\Utils\Appointment::save()/
 * checkTime() and Lib\Proxy\ServiceExtras actually expect (confirmed against
 * the real booking-form JS and Cart.php — extras are never a boolean
 * attach, always a per-extra quantity within that extra's own
 * min/max_quantity range), validating each extra actually belongs to the
 * given service along the way.
 */
class ExtrasInput
{
    /**
     * @param int   $service_id
     * @param array $items [{"extra_id":int, "quantity":int}, ...] as decoded from the tool call arguments
     * @return array [extra_id => quantity], quantity=0 entries omitted
     * @throws \Exception on anything invalid — caller catches and turns it into an "Error: ..." tool result
     */
    public static function parse( $service_id, array $items )
    {
        if ( ! $items ) {
            return array();
        }

        if ( ! Lib\Config::serviceExtrasActive() ) {
            throw new \Exception( 'this booking system takes no extras. Call again without them.' );
        }

        $allowed = array();
        foreach ( Lib\Proxy\ServiceExtras::findByServiceId( $service_id ) ?: array() as $extra ) {
            if ( $extra->getMaxQuantity() > 0 ) {
                $allowed[ $extra->getId() ] = $extra;
            }
        }

        $extras = array();
        foreach ( $items as $item ) {
            $extra_id = isset( $item['extra_id'] ) ? (int) $item['extra_id'] : 0;
            $quantity = isset( $item['quantity'] ) ? (int) $item['quantity'] : 0;

            if ( ! isset( $allowed[ $extra_id ] ) ) {
                throw new \Exception( 'extra_id ' . $extra_id . ' is not one of this service\'s extras. Use its extras list in the catalog.' );
            }

            $extra = $allowed[ $extra_id ];
            $min = max( 0, $extra->getMinQuantity() );
            if ( $quantity < $min || $quantity > $extra->getMaxQuantity() ) {
                throw new \Exception( 'quantity for extra "' . $extra->getTranslatedTitle() . '" must be between ' . $min . ' and ' . $extra->getMaxQuantity() . '.' );
            }

            if ( $quantity > 0 ) {
                $extras[ $extra_id ] = $quantity;
            }
        }

        return $extras;
    }

    /**
     * Extra duration (seconds) to add to the service's own duration when
     * reporting a slot's real end time to the customer — mirrors how
     * Lib\Slots\Finder/Lib\Cart compute the effective occupied interval.
     * Lib\Utils\Appointment::save()/checkTime() already add this internally
     * from the same $extras map for their own persistence/validation, so
     * this is only needed for what THIS tool reports back to the model —
     * never pass an already-extended end_date into save()/checkTime()
     * themselves, that would double-count it.
     *
     * @param array $extras [extra_id => quantity]
     * @return int
     */
    public static function totalDuration( array $extras )
    {
        if ( ! $extras || ! Lib\Config::serviceExtrasActive() || ! Lib\Proxy\ServiceExtras::considerDuration() ) {
            return 0;
        }

        return (int) Lib\Proxy\ServiceExtras::getTotalDuration( $extras );
    }
}
