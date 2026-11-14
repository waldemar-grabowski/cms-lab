<?php
namespace Bookly\Frontend\Modules\Ai\BookingTools;

use Bookly\Lib;

/**
 * Not a tool itself - what the offer_choices cards show, decided here and nowhere else.
 *
 * build() makes the tool's result: the cards exactly as the customer will see them. The model
 * reads it, and the same stored result is what the widget shows (present()) - the customer
 * never sees what the model does not know. A card is {id, label, shown?, group?, time?}:
 * "shown" holds what goes under the label, by name and ready to show, in its order.
 *
 * Rules for "shown":
 * - a service always shows its duration and price;
 * - cards sharing a label (namesakes) are told apart - see tellNamesakesApart();
 * - namesakes still looking the same get their id;
 * - photos stay out: whether one shows is the form's setting, the widget's to apply.
 */
class ChoiceCards
{
    // Cards that show before the "+N" button (four rows of times).
    const FOLD = 12;

    /**
     * @param string  $type One of OfferChoices::TYPE_*
     * @param array[] $options From ChoiceOptions::find()
     * @param array   $arguments The model's: staff_id prices service cards, service_id staff cards
     * @return array The tool's result
     */
    public static function build( $type, array $options, array $arguments )
    {
        // An id that is no staff member / service is ignored: the cards show the price range
        // / no price rather than fail.
        $staff   = $type === OfferChoices::TYPE_SERVICE && ! empty( $arguments['staff_id'] ) ? Lib\Entities\Staff::find( (int) $arguments['staff_id'] ) : null;
        $service = $type === OfferChoices::TYPE_STAFF && ! empty( $arguments['service_id'] ) ? Lib\Entities\Service::find( (int) $arguments['service_id'] ) : null;

        $cards   = array();
        $details = array();
        foreach ( $options as $i => $option ) {
            $cards[ $i ] = $option;
            list( $shown, $details[ $i ] ) = self::details( $type, $option, $staff, $service );
            if ( $shown ) {
                $cards[ $i ]['shown'] = $shown;
            }
        }
        self::tellNamesakesApart( $cards, $details );
        self::numberLookalikes( $cards );

        $result = array( 'type' => $type, 'shown_as_buttons' => array_values( $cards ) );
        $price_is = self::priceIs( $type, $cards, $staff );
        if ( $price_is ) {
            $result['price_is'] = $price_is;
        }
        if ( count( $cards ) > self::FOLD ) {
            $result['visible']     = self::FOLD;
            $result['behind_more'] = count( $cards ) - self::FOLD;
        }

        return $result;
    }

    /**
     * The cards of a stored result (Ajax::aiPoll()), as the widget draws them: "shown"
     * joined into one line under the label, the photo looked up now.
     *
     * @param string|null $result The 'tool' message content OfferChoices::execute() returned
     * @return array|null {type, items: [{id, label, meta?, image?, group?, time?}], visible}, null when the call failed
     */
    public static function present( $result )
    {
        $data = json_decode( (string) $result, true );
        if ( empty( $data['type'] ) || empty( $data['shown_as_buttons'] ) || ! is_array( $data['shown_as_buttons'] ) ) {
            return null;
        }

        $items = array();
        foreach ( $data['shown_as_buttons'] as $card ) {
            if ( ! isset( $card['id'], $card['label'] ) ) {
                continue;
            }
            $item = array( 'id' => (string) $card['id'], 'label' => (string) $card['label'] );
            if ( ! empty( $card['shown'] ) && is_array( $card['shown'] ) ) {
                $item['meta'] = self::line( $card['shown'] );
            }
            $image = self::image( $data['type'], $card['id'] );
            if ( $image ) {
                $item['image'] = $image;
            }
            foreach ( array( 'group', 'time' ) as $key ) {
                if ( isset( $card[ $key ] ) && $card[ $key ] !== '' ) {
                    $item[ $key ] = (string) $card[ $key ];
                }
            }
            $items[] = $item;
        }
        if ( ! $items ) {
            return null;
        }

        return array(
            'type'    => (string) $data['type'],
            'items'   => $items,
            'visible' => isset( $data['visible'] ) ? (int) $data['visible'] : count( $items ),
        );
    }

    /**
     * What a card shows under its label always, and the details that could tell it apart
     * from a namesake - shown only when needed (tellNamesakesApart()).
     *
     * @param string                    $type
     * @param array                     $option
     * @param Lib\Entities\Staff|null   $staff For service cards: prices are this staff member's
     * @param Lib\Entities\Service|null $service For staff cards: their price for it
     * @return array [shown, details], both [name => value]
     */
    private static function details( $type, array $option, $staff, $service )
    {
        $shown   = array();
        $details = array();
        switch ( $type ) {
            case OfferChoices::TYPE_SERVICE:
                $entity            = Lib\Entities\Service::find( (int) $option['id'] );
                $shown['duration'] = Lib\Utils\DateTime::secondsToInterval( $entity->getDuration() );
                $shown['price']    = ServicePrice::formatted( $entity, $staff ? $staff->getId() : null );
                $category          = self::category( $entity );
                // Under a heading of its own category the heading already says it.
                if ( $category !== '' && ( ! isset( $option['group'] ) || $option['group'] !== $category ) ) {
                    $details['category'] = $category;
                }
                break;
            case OfferChoices::TYPE_STAFF:
                if ( $service ) {
                    $details['price'] = ServicePrice::formatted( $service, (int) $option['id'] );
                }
                $locations = self::staffLocations( $option['id'] );
                if ( $locations ) {
                    $details['locations'] = $locations;
                }
                break;
            case OfferChoices::TYPE_LOCATION:
                $address = self::address( $option['id'] );
                if ( $address !== '' ) {
                    $details['address'] = $address;
                }
                break;
        }

        return array( $shown, $details );
    }

    /**
     * Namesakes (cards sharing a label, found across the whole call) get the details that tell
     * them apart. A detail one of them needs shows on every card of its group that has it, the
     * whole list when there are no groups: a card without it then means it has none, not
     * "nothing to tell apart here" - "Clipper cut" with no category next to "Haircut · Women"
     * would read as a cut for everyone. Groups with no namesakes stay as they are.
     *
     * @param array[] $cards
     * @param array[] $details From details(), by the same keys
     */
    private static function tellNamesakesApart( array &$cards, array $details )
    {
        $ids_by_label = array();
        foreach ( $cards as $card ) {
            $ids_by_label[ $card['label'] ][ $card['id'] ] = true;
        }

        $needed = array();
        foreach ( $cards as $i => $card ) {
            if ( count( $ids_by_label[ $card['label'] ] ) > 1 ) {
                foreach ( $details[ $i ] as $name => $value ) {
                    $needed[ self::group( $card ) ][ $name ] = true;
                }
            }
        }

        foreach ( $cards as $i => $card ) {
            foreach ( $details[ $i ] as $name => $value ) {
                if ( isset( $needed[ self::group( $card ) ][ $name ] ) ) {
                    $cards[ $i ]['shown'][ $name ] = $value;
                }
            }
        }
    }

    /**
     * Namesakes nothing tells apart get their id: two identical cards would be a guess either way.
     *
     * @param array[] $cards
     */
    private static function numberLookalikes( array &$cards )
    {
        $ids_by_look = array();
        foreach ( $cards as $card ) {
            $ids_by_look[ self::look( $card ) ][ $card['id'] ] = true;
        }
        foreach ( $cards as $i => $card ) {
            if ( count( $ids_by_look[ self::look( $card ) ] ) > 1 ) {
                $cards[ $i ]['shown']['id_on_card'] = 'ID ' . $card['id'];
            }
        }
    }

    /**
     * Whose price the cards show, for the model to tell the customer right.
     *
     * @param string                  $type
     * @param array[]                 $cards
     * @param Lib\Entities\Staff|null $staff
     * @return string|null "staff_base" - a staff member's base price, "range" - lowest-highest across staff
     */
    private static function priceIs( $type, array $cards, $staff )
    {
        if ( $type === OfferChoices::TYPE_SERVICE ) {
            return $staff ? 'staff_base' : 'range';
        }
        foreach ( $cards as $card ) {
            if ( isset( $card['shown']['price'] ) ) {
                return 'staff_base';
            }
        }

        return null;
    }

    /**
     * "1 h · $40.00 · Women" - the values in their order, a list's items too.
     *
     * @param array $shown
     * @return string
     */
    private static function line( array $shown )
    {
        $parts = array();
        foreach ( $shown as $value ) {
            foreach ( (array) $value as $part ) {
                $parts[] = (string) $part;
            }
        }

        return implode( ' · ', $parts );
    }

    /**
     * @param array $card
     * @return string Its label and what shows under it
     */
    private static function look( array $card )
    {
        return $card['label'] . "\n" . ( isset( $card['shown'] ) ? self::line( $card['shown'] ) : '' );
    }

    /**
     * @param array $card
     * @return string Its group's title, '' with no groups
     */
    private static function group( array $card )
    {
        return isset( $card['group'] ) ? $card['group'] : '';
    }

    /**
     * @param string     $type
     * @param string|int $id
     * @return string The service's or staff member's photo, '' when none
     */
    private static function image( $type, $id )
    {
        $entity = null;
        if ( $type === OfferChoices::TYPE_SERVICE ) {
            $entity = Lib\Entities\Service::find( (int) $id );
        } elseif ( $type === OfferChoices::TYPE_STAFF ) {
            $entity = Lib\Entities\Staff::find( (int) $id );
        }

        return $entity ? (string) $entity->getImageUrl( 'thumbnail' ) : '';
    }

    /**
     * @param Lib\Entities\Service $service
     * @return string Its category's name, translated; '' when it has none
     */
    private static function category( Lib\Entities\Service $service )
    {
        $category = $service->getCategoryId() ? Lib\Entities\Category::find( $service->getCategoryId() ) : null;

        return $category ? $category->getTranslatedName() : '';
    }

    /**
     * @param string|int $staff_id
     * @return string[] Names of the locations the staff member works at, translated
     */
    private static function staffLocations( $staff_id )
    {
        $names = array();
        // null with the Locations add-on off
        foreach ( Lib\Proxy\Locations::findByStaffId( (int) $staff_id ) ?: array() as $location ) {
            $names[] = Lib\Utils\Common::getTranslatedString( 'location_' . $location->getId(), $location->getName() );
        }

        return $names;
    }

    /**
     * @param string|int $location_id
     * @return string The location's info in one line, as the catalog gives it; '' when none
     */
    private static function address( $location_id )
    {
        foreach ( Lib\Proxy\Locations::getAll() ?: array() as $location ) {
            if ( $location['id'] == $location_id ) {
                return wp_strip_all_tags( Lib\Utils\Common::getTranslatedString( 'location_' . $location['id'] . '_info', $location['info'] ), true );
            }
        }

        return '';
    }
}
