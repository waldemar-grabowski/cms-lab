<?php
namespace Bookly\Frontend\Modules\Ai;

use Bookly\Lib;

/**
 * What the business offers, given to the model with the system prompt instead of being asked
 * for tool by tool: services with prices and extras, who provides each of them, locations.
 *
 * Without it a conversation would spend its first two or three rounds asking for them tool
 * by tool, and put the same volume into the history anyway.
 *
 * How a booking is paid is deliberately not here: it depends on the service, the staff
 * member and the customer's group together (Checkout::getGateways()), and is only known
 * once create_booking has found the customer - Concepts and create_booking's result say so.
 *
 * Built once per conversation and kept on it (forConversation()): the system prompt and the
 * tool schemas stay the same for the whole conversation, which Cloud's prompt cache needs.
 *
 * Sent as data in the site's "catalog"; Cloud's concept texts say what its fields mean.
 */
class Catalog
{
    // About 5k tokens. Past it the model gets every service's id and title and the details
    // of as many as fit, and get_services for the rest (Tools::all() registers it only then).
    // A catalog that big is rare; most sites stay far below.
    const MAX_LENGTH = 20000;

    /** @var array|null */
    private static $data;

    /**
     * The catalog as it goes to Bookly Cloud in the site's "catalog" (SystemPrompt::site()):
     * services - in full while they fit MAX_LENGTH, past it the rest with id, title and category_id only
     * (the "catalog.complete" fact of Concepts::facts() says so) - staff members and
     * locations. How to read it is Cloud's concept text; this is data only.
     *
     * @return array empty when the business has no bookable service
     */
    public static function payload()
    {
        $data = self::data();
        if ( ! $data['services'] ) {
            return array();
        }

        $catalog = array();
        list( $catalog['services'] ) = self::fit( $data['services'] );
        if ( ! empty( $data['categories'] ) ) {
            $catalog['categories'] = $data['categories'];
        }
        $catalog['staff'] = $data['staff'];
        if ( $data['locations'] ) {
            $catalog['locations'] = $data['locations'];
        }

        return $catalog;
    }

    /**
     * Take data() from the conversation, or build it on the conversation's first turn and
     * keep it there: one conversation, one catalog. Building it prices every service with
     * every staff member, and each customer message is a new worker request that would
     * build it again. An edit the owner makes meanwhile reaches the next conversation; so
     * does a plugin update (AiConversation::getSnapshotArray()).
     *
     * @param Lib\Entities\AiConversation $conversation
     */
    public static function forConversation( Lib\Entities\AiConversation $conversation )
    {
        $snapshot = $conversation->getSnapshotArray();
        if ( isset( $snapshot['catalog'] ) ) {
            self::$data = $snapshot['catalog'];

            return;
        }

        $conversation->setSnapshotValue( 'catalog', self::data() );
    }

    /**
     * JSON as the model reads it: names as they are, not \u-escaped - a Cyrillic letter
     * would take six characters of the catalog's length and of the model's tokens.
     *
     * @param mixed $value
     * @return string
     */
    public static function json( $value )
    {
        return wp_json_encode( $value, 256 );
    }

    /**
     * Whether the catalog lists some services with id and title only - get_services is
     * there for their details (Tools::all()).
     *
     * @return bool
     */
    public static function truncated()
    {
        $data = self::data();
        list( , $shown ) = self::fit( $data['services'] );

        return $shown < count( $data['services'] );
    }

    /**
     * The services, staff and locations the catalog lists - Concepts decides from the same
     * data which concepts this business has. Read once per request, or from the
     * conversation (forConversation()): the prompt and every tool schema ask for it.
     *
     * @return array [ 'services' => array[], 'categories' => array[], 'staff' => array[], 'locations' => array[] ]
     */
    public static function data()
    {
        if ( self::$data !== null ) {
            return self::$data;
        }

        self::$data = array( 'services' => array(), 'categories' => array(), 'staff' => array(), 'locations' => array() );

        /** @var Lib\Entities\Service[] $services */
        $services = Lib\Entities\Service::query( 's' )
            ->where( 's.type', Lib\Entities\Service::TYPE_SIMPLE )
            ->where( 's.visibility', Lib\Entities\Service::VISIBILITY_PUBLIC )
            ->sortBy( 's.title' )
            ->find();

        // Only what can be booked: a service with no staff member to provide it is not offered,
        // as the booking form does not offer it either (both go by caSeSt()).
        $by_staff = array();
        foreach ( $services as $i => $service ) {
            $by_staff[ $i ] = BookingTools\ServicePrice::byStaff( $service );
            if ( ! $by_staff[ $i ] ) {
                unset( $services[ $i ] );
            }
        }

        if ( ! $services ) {
            return self::$data;
        }

        // A category tells apart services of one name - a car size, a men's and a women's
        // haircut. One category for all of them tells nothing: then none is listed.
        $category_ids = array();
        foreach ( $services as $service ) {
            if ( $service->getCategoryId() ) {
                $category_ids[ $service->getCategoryId() ] = true;
            }
        }
        // By id, with the names listed once, like staff and locations.
        $categories = array();
        if ( count( $category_ids ) > 1 ) {
            /** @var Lib\Entities\Category $category */
            foreach ( Lib\Entities\Category::query()->whereIn( 'id', array_keys( $category_ids ) )->sortBy( 'position, id' )->find() as $category ) {
                $categories[ $category->getId() ] = true;
                self::$data['categories'][] = array( 'id' => (int) $category->getId(), 'name' => $category->getTranslatedName() );
            }
        }

        $staff = array();
        foreach ( $services as $i => $service ) {
            $entry = BookingTools\GetServices::describe( $service );
            if ( $service->getCategoryId() && isset( $categories[ $service->getCategoryId() ] ) ) {
                // Right after the title: it tells apart services of one title.
                $entry = array_slice( $entry, 0, 2, true ) + array( 'category_id' => (int) $service->getCategoryId() ) + $entry;
            }
            self::$data['services'][] = $entry;
            foreach ( $by_staff[ $i ] as $staff_id => $item ) {
                $staff[ $staff_id ] = $item['full_name'];
            }
        }

        self::$data['locations'] = Lib\Config::locationsActive() ? BookingTools\Locations::entries() : array();
        self::$data['staff'] = self::staff( $staff, (bool) self::$data['locations'] );

        return self::$data;
    }

    /**
     * All services in full while the catalog fits MAX_LENGTH; past it, as many in full as fit
     * and the rest with id, title and category_id only.
     *
     * @param array[] $entries
     * @return array [ entries to show, how many of them are in full ]
     */
    private static function fit( array $entries )
    {
        if ( strlen( self::json( $entries ) ) <= self::MAX_LENGTH ) {
            return array( $entries, count( $entries ) );
        }

        $short = array();
        foreach ( $entries as $entry ) {
            $item = array( 'id' => $entry['id'], 'title' => $entry['title'] );
            // Without it services of one name would be the same line.
            if ( isset( $entry['category_id'] ) ) {
                $item['category_id'] = $entry['category_id'];
            }
            $short[] = $item;
        }

        $length = strlen( self::json( $short ) );
        $shown = 0;
        foreach ( $entries as $i => $entry ) {
            $length += strlen( self::json( $entry ) ) - strlen( self::json( $short[ $i ] ) );
            if ( $length > self::MAX_LENGTH ) {
                break;
            }
            $short[ $i ] = $entry;
            $shown++;
        }

        return array( $short, $shown );
    }

    /**
     * @param array $staff [ staff_id => full_name ]
     * @param bool  $with_locations Whether the catalog lists any locations to point to
     * @return array[]
     */
    private static function staff( array $staff, $with_locations )
    {
        asort( $staff );

        $list = array();
        foreach ( $staff as $staff_id => $full_name ) {
            $member = array( 'id' => (int) $staff_id, 'full_name' => $full_name );

            // Null with the addon off. An empty list is a staff member tied to no location:
            // bookable without one, not at any listed location.
            $locations = $with_locations ? Lib\Proxy\Locations::findByStaffId( $staff_id ) : null;
            if ( $locations !== null ) {
                $member['location_ids'] = array();
                foreach ( $locations as $location ) {
                    $member['location_ids'][] = (int) $location->getId();
                }
            }

            $list[] = $member;
        }

        return $list;
    }
}
