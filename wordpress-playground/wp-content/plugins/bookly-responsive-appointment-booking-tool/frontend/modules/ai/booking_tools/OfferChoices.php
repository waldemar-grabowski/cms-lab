<?php
namespace Bookly\Frontend\Modules\Ai\BookingTools;

use Bookly\Frontend\Modules\Ai;

/**
 * The model calls this to show the options it offers as clickable cards under its reply:
 * services, staff members, locations or start times. A click sends the card's label as the
 * customer's next message, with a note of its id.
 *
 * - which ids are real options: ChoiceOptions;
 * - what each card shows - the result the model reads and the widget draws: ChoiceCards;
 * - clicks and answered cards in the conversation: ChoicePicks.
 *
 * When the model calls nothing but this tool alongside its text, the turn ends right after
 * it (Ajax::aiWorker()): there is nothing left for the model to say.
 */
class OfferChoices implements ToolInterface
{
    const NAME = 'offer_choices';

    const TYPE_SERVICE  = 'service';
    const TYPE_STAFF    = 'staff';
    const TYPE_LOCATION = 'location';
    const TYPE_SLOT     = 'slot';

    /**
     * @inheritDoc
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * @inheritDoc
     */
    public function getSchema()
    {
        $locations = Ai\Concepts::forSite()->has( Ai\Concepts::LOCATION );
        $types = array( self::TYPE_SERVICE, self::TYPE_STAFF, self::TYPE_SLOT );
        if ( $locations ) {
            $types[] = self::TYPE_LOCATION;
        }

        $description = implode( ' ', array(
            'Show services, staff members, ' . ( $locations ? 'locations ' : '' ) . 'or start times as clickable choice cards under your reply.',
            'Call it in the same reply as your text whenever that text would name two or more of them — whether answering a question about them or asking the customer to pick one — with exactly the ones you mean.',
            'The cards show them, so do not list them in your text — write just a lead-in or the question.',
            'A click sends the option\'s label back as the customer\'s next message, with a note of the id they picked.',
            'When the customer names one in their own words and you cannot tell for sure which one they mean - two share that name, or the words fit more than one - do not pick one yourself: show exactly the ones that fit and ask them to tap the one they mean.',
            'The result is what the customer now sees, one card per entry of "shown_as_buttons": its label (for "slot" over several days, its time under a date heading), under it the "shown" values in their order joined by " · " (a list\'s items too).',
            '"price_is": "staff_base" - the given staff member\'s base price, "range" - lowest–highest across staff members; the final price can depend on location, time and persons - take it from get_available_slots or check_availability.',
            'With "behind_more", only the first "visible" cards show, the rest behind a "+N" button the customer can tap.',
            'The customer sees that button - do not mention it in your reply; it is for answering questions like "show the rest".',
        ) );

        $properties = array(
            'type'       => array(
                'type'        => 'string',
                'enum'        => $types,
                'description' => 'What the options are.',
            ),
            'ids'        => array(
                'type'        => 'array',
                'items'       => array( 'type' => 'string' ),
                'description' => 'Ids from the catalog, written as strings ("62"), or for "slot" start times from get_available_slots with their date (YYYY-MM-DD HH:MM:SS). In the order to show.',
            ),
            'groups'     => array(
                'type'        => 'array',
                'items'       => array(
                    'type'       => 'object',
                    'properties' => array(
                        'title' => array( 'type' => 'string' ),
                        'ids'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
                    ),
                    'required'   => array( 'title', 'ids' ),
                ),
                'description' => 'Instead of ids, to show the options in groups - by category, by price, any the customer asks for: each group\'s title as the customer will see it, and its ids in the order to show. All the groups go in this one call. Not for "slot": times are grouped by date anyway.',
            ),
            'staff_id'   => array(
                'type'        => 'integer',
                'description' => 'For "service": the staff member these services are for, when the customer has chosen or asked about one - the cards then show that staff member\'s prices; omit otherwise, the cards then show each service\'s price range. For "slot": the staff member of these start times, required.',
            ),
            'service_id' => array(
                'type'        => 'integer',
                'description' => 'For "staff": the service the customer has chosen, if any - staff members sharing a name are then told apart by their price for it. For "slot": the service of these start times, required.',
            ),
        );
        if ( $locations ) {
            $properties['location_id'] = array(
                'type'        => 'integer',
                'description' => 'For "slot": the location_id these start times were found with in get_available_slots; omit if there was none.',
            );
        }

        return array(
            'name'        => $this->getName(),
            'description' => $description,
            'parameters'  => array(
                'type'       => 'object',
                'properties' => $properties,
                'required'   => array( 'type' ),
            ),
        );
    }

    /**
     * @inheritDoc
     */
    public function execute( array $arguments )
    {
        $type = isset( $arguments['type'] ) ? (string) $arguments['type'] : '';

        $options = ChoiceOptions::find( $type, $arguments );
        if ( is_string( $options ) ) {
            return $options;
        }
        if ( ! $options ) {
            return $type === self::TYPE_SLOT
                ? 'Error: none of these start times can be booked with this staff member, nothing was shown. Take start times from get_available_slots.'
                : 'Error: none of the ids is valid for type "' . $type . '", nothing was shown.';
        }

        return Ai\Catalog::json( ChoiceCards::build( $type, $options, $arguments ) );
    }

    /**
     * Appended to a get_* result that lists more than one thing. The tool description says
     * the same, but a model that has just read a list tends to answer with a list - and the
     * result is the last thing it reads before it answers.
     *
     * @param string $type One of self::TYPE_*
     * @param int    $count How many options the result lists
     * @return string
     */
    public static function hint( $type, $count )
    {
        return $count > 1
            ? ' Whenever your reply names two or more of these, show them by calling ' . self::NAME . ' (type "' . $type . '") with their ids instead of listing them in your text.'
            : '';
    }
}
