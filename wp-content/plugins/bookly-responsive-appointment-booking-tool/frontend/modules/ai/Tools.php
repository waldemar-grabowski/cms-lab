<?php
namespace Bookly\Frontend\Modules\Ai;

use Bookly\Lib;

class Tools
{
    /**
     * @param Lib\Entities\AiConversation|null $conversation The chat the tool call belongs to.
     *   create_booking needs it to park its priced draft somewhere the browser's own request
     *   can pick it up; every other tool ignores it. Omitted when this is
     *   called just to collect the schemas for the /complete payload.
     * @return BookingTools\ToolInterface[] keyed by tool name
     */
    public static function all( $conversation = null )
    {
        $tools = array(
            new BookingTools\GetAvailableSlots(),
            new BookingTools\CheckAvailability(),
            new BookingTools\CreateBooking( $conversation ),
            new BookingTools\OfferChoices(),
            new BookingTools\GetBusinessHours(),
        );

        // Only while the catalog lists some services without details.
        if ( Catalog::truncated() ) {
            $tools[] = new BookingTools\GetServices();
        }

        $by_name = array();
        foreach ( $tools as $tool ) {
            $by_name[ $tool->getName() ] = $tool;
        }

        return $by_name;
    }
}
