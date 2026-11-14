<?php
namespace Bookly\Frontend\Modules\Ai\BookingTools;

use Bookly\Lib;

/**
 * Not a tool itself — what a service costs, as the customer will be charged
 * for it. A staff member can have a price of their own for a service
 * (bookly_staff_services.price), which is what the cart charges; the
 * service's own price is only the fallback. Everything the chat quotes goes
 * through Lib\CartItem, the same code that prices the checkout, so the number
 * the model says is the number the customer pays.
 */
class ServicePrice
{
    /**
     * One booking: service with this staff member, plus extras.
     *
     * @param Lib\Entities\Service $service
     * @param int                  $staff_id
     * @param int|null             $location_id
     * @param string|null          $start_date YYYY-MM-DD HH:MM:SS; null when no time is picked yet
     * @param array                $extras [extra_id => quantity]
     * @param int                  $nop Persons the booking is for: the price is for all of them
     * @return float
     */
    public static function forBooking( Lib\Entities\Service $service, $staff_id, $location_id = null, $start_date = null, array $extras = array(), $nop = 1 )
    {
        $location_id = $location_id ? (int) $location_id : null;

        $cart_item = new Lib\CartItem();
        $cart_item
            ->setType( Lib\CartItem::TYPE_APPOINTMENT )
            ->setServiceId( $service->getId() )
            ->setStaffIds( array( (int) $staff_id ) )
            ->setLocationId( $location_id )
            ->setNumberOfPersons( $nop )
            ->setUnits( 1 )
            ->setExtras( $extras );
        if ( $start_date ) {
            // With a time, special-hours pricing applies to it as it will at checkout.
            $cart_item->setSlots( array( array( $service->getId(), (int) $staff_id, $start_date, $location_id ) ) );
        }

        return (float) $cart_item->getServicePrice( $nop );
    }

    /**
     * What each staff member who provides the service charges for it. The
     * staff are the ones the booking form offers for it (Lib\Config::getCaSeSt),
     * so the chat and the form never disagree on who can be booked.
     *
     * @param Lib\Entities\Service $service
     * @return array [staff_id => ['full_name' => string, 'price' => float]]
     */
    public static function byStaff( Lib\Entities\Service $service )
    {
        $casest = self::caSeSt();
        $prices = array();
        foreach ( $casest['staff'] as $staff_id => $staff ) {
            if ( isset( $staff['services'][ $service->getId() ] ) ) {
                $prices[ $staff_id ] = array(
                    'full_name' => $staff['name'],
                    'price' => self::forBooking( $service, $staff_id ),
                );
            }
        }
        uasort( $prices, function ( $a, $b ) {
            return strcasecmp( $a['full_name'], $b['full_name'] );
        } );

        return $prices;
    }

    /**
     * Lib\Config::getCaSeSt() reads the whole catalog and has no cache of its
     * own, while the catalog asks for every service in one call.
     *
     * @return array
     */
    public static function caSeSt()
    {
        static $casest;
        if ( $casest === null ) {
            $casest = Lib\Config::getCaSeSt();
        }

        return $casest;
    }

    /**
     * The service's price for this staff member, or - with no staff member
     * chosen - "from-to" across everyone who provides it, formatted. The
     * service's own price only when nobody provides it.
     *
     * @param Lib\Entities\Service $service
     * @param int|null             $staff_id
     * @return string
     */
    public static function formatted( Lib\Entities\Service $service, $staff_id = null )
    {
        if ( $staff_id ) {
            return Lib\Utils\Price::format( self::forBooking( $service, $staff_id ) );
        }

        return self::formatRange( wp_list_pluck( self::byStaff( $service ), 'price' ), $service->getPrice() );
    }

    /**
     * @param float[] $prices
     * @param float   $fallback When $prices is empty
     * @return string
     */
    public static function formatRange( array $prices, $fallback )
    {
        if ( ! $prices ) {
            return Lib\Utils\Price::format( $fallback );
        }

        $min = min( $prices );
        $max = max( $prices );

        return $min == $max
            ? Lib\Utils\Price::format( $min )
            : Lib\Utils\Price::format( $min ) . '–' . Lib\Utils\Price::format( $max );
    }
}
