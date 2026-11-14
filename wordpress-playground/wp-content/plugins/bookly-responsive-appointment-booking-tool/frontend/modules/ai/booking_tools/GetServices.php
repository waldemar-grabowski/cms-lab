<?php
namespace Bookly\Frontend\Modules\Ai\BookingTools;

use Bookly\Frontend\Modules\Ai;
use Bookly\Lib;

class GetServices implements ToolInterface
{
    /**
     * @inheritDoc
     */
    public function getName()
    {
        return 'get_services';
    }

    /**
     * @inheritDoc
     *
     * Registered only when the catalog is cut short (Catalog::truncated()): then some
     * services are listed with id and title only, and the model, which matches the
     * customer's words against those titles itself, asks for the details of the ones it
     * picked. A search by title would only make it guess a substring of a title it has
     * already read.
     *
     * Every get_* tool takes a required argument for a Cloud-side bug: a tool called with
     * a genuinely empty arguments object ("{}") gets echoed back into the next /complete
     * call's history as "arguments":[] (PHP's own empty-array/empty-object ambiguity
     * survives Cloud's own JSON round-trip, however this plugin encodes its payload), and
     * Anthropic then rejects that call with "tool_use.input: Input should be an object".
     * Here the ids are needed anyway. Flagged separately for bookly-cloud.
     */
    public function getSchema()
    {
        return array(
            'name'        => $this->getName(),
            'description' => 'Details of the services the catalog lists with id and title only - the same fields as the other services have there.',
            'parameters'  => array(
                'type'       => 'object',
                'properties' => array(
                    'ids' => array(
                        'type'        => 'array',
                        'items'       => array( 'type' => 'integer' ),
                        'description' => 'Ids of those services, from the catalog.',
                    ),
                ),
                'required'   => array( 'ids' ),
            ),
        );
    }

    /**
     * @inheritDoc
     */
    public function execute( array $arguments )
    {
        $ids = isset( $arguments['ids'] ) && is_array( $arguments['ids'] ) ? array_filter( array_map( 'intval', $arguments['ids'] ) ) : array();
        if ( ! $ids ) {
            return 'Error: pass the ids of the services, from the catalog.';
        }

        /** @var Lib\Entities\Service[] $services */
        $services = Lib\Entities\Service::query( 's' )
            ->where( 's.type', Lib\Entities\Service::TYPE_SIMPLE )
            ->where( 's.visibility', Lib\Entities\Service::VISIBILITY_PUBLIC )
            ->whereIn( 's.id', $ids )
            ->sortBy( 's.title' )
            ->find();

        if ( ! $services ) {
            return 'Error: none of these ids is a service in the catalog.';
        }

        $list = array();
        foreach ( $services as $service ) {
            $list[] = self::describe( $service );
        }

        return Ai\Catalog::json( $list ) . OfferChoices::hint( OfferChoices::TYPE_SERVICE, count( $list ) );
    }

    /**
     * One service as get_services lists it - and the catalog (Ai\Catalog) too.
     *
     * @param Lib\Entities\Service $service
     * @return array
     */
    public static function describe( Lib\Entities\Service $service )
    {
        $by_staff = ServicePrice::byStaff( $service );
        $prices   = wp_list_pluck( $by_staff, 'price' );

        $entry = array(
            'id'               => (int) $service->getId(),
            'title'            => $service->getTitle(),
            'duration_minutes' => (int) round( $service->getDuration() / 60 ),
            'price'            => ServicePrice::formatRange( $prices, $service->getPrice() ),
        );

        // Only when it matters: one price for everyone is already in 'price'. Staff id =>
        // price, the names are in the catalog's staff list - with sixty staff members on a
        // service, repeating them was most of the catalog.
        if ( $prices && min( $prices ) != max( $prices ) ) {
            $price_by_staff = array();
            foreach ( $by_staff as $staff_id => $staff ) {
                $price_by_staff[ (string) $staff_id ] = Lib\Utils\Price::format( $staff['price'] );
            }
            $entry['price_by_staff'] = (object) $price_by_staff;
        }

        // Persons one booking may be for, when more than one (SlotLookup::personsRange()).
        if ( Lib\Config::groupBookingActive() ) {
            $persons = SlotLookup::personsRange( $service, array_keys( $by_staff ) );
            if ( $persons[1] > 1 ) {
                $entry['persons'] = array( (int) $persons[0], (int) $persons[1] );
            }
        }

        if ( Lib\Config::serviceExtrasActive() ) {
            $extras = array();
            foreach ( Lib\Proxy\ServiceExtras::findByServiceId( $service->getId() ) ?: array() as $extra ) {
                if ( $extra->getMaxQuantity() <= 0 ) {
                    // Same "hidden" convention the booking widget itself
                    // uses (no dedicated active/visibility column exists
                    // on ServiceExtra — see bookly-addon-service-extras'
                    // own booking-step code).
                    continue;
                }
                $extras[] = array(
                    'id'               => (int) $extra->getId(),
                    'title'            => $extra->getTranslatedTitle(),
                    'price'            => Lib\Utils\Price::format( $extra->getPrice() ),
                    'duration_minutes' => (int) round( $extra->getDuration() / 60 ),
                    'min_quantity'     => (int) max( 0, $extra->getMinQuantity() ),
                    'max_quantity'     => (int) $extra->getMaxQuantity(),
                );
            }
            if ( $extras ) {
                $entry['extras'] = $extras;
            }
        }

        // The staff members who provide it - price_by_staff lists them already, when there.
        if ( ! isset( $entry['price_by_staff'] ) ) {
            $entry['staff_ids'] = array_map( 'intval', array_keys( $by_staff ) );
        }

        return $entry;
    }
}
