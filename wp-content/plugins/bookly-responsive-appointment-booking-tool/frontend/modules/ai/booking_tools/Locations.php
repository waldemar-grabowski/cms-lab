<?php
namespace Bookly\Frontend\Modules\Ai\BookingTools;

use Bookly\Lib;

/**
 * Not a tool itself - the locations of a booking as the chat knows them: which ones the
 * catalog lists, the description of the location_id argument, and the error for a staff
 * member asked for at a location they do not work at.
 */
class Locations
{
    // Which location to book is said by Concepts (Location): the model asks when the staff
    // member works at more than one. bookly_app_required_location is an appearance setting
    // of the step-by-step form alone.
    const ARGUMENT = 'Location id, from the catalog: the location of the booking. Omit for a staff member with no locations.';

    /**
     * A staff member asked for at a location they do not work at has no times there at all,
     * and "nothing until <the end of the booking window>" reads like a fully booked calendar.
     * Said plainly instead, with the way out.
     *
     * @param Lib\Entities\Staff $staff
     * @param int               $location_id 0 when none
     * @return string|null Error for the model, null when fine
     */
    public static function checkStaff( Lib\Entities\Staff $staff, $location_id )
    {
        if ( ! $location_id ) {
            return null;
        }
        $locations = Lib\Proxy\Locations::findByStaffId( $staff->getId() );
        if ( $locations === null ) {
            return null;
        }
        foreach ( $locations as $location ) {
            // Loose: Location::getId() comes back as a string.
            if ( $location->getId() == $location_id ) {
                return null;
            }
        }

        return 'Error: ' . $staff->getFullName() . ' does not work at this location, so there are no times with them there. '
            . 'Unless the customer asked for this location, call again without location_id; otherwise offer the staff members who work at it.';
    }

    /**
     * Locations as the catalog (Ai\Catalog) lists them, and the location choice cards.
     *
     * Only the ones the booking form offers (Lib\Config::getCaSeSt()): a location no
     * bookable staff member works at has no slots for anyone, and a model that sees it as
     * the business's only location passes it everywhere and finds nothing.
     *
     * @param string $search
     * @return array[]
     */
    public static function entries( $search = '' )
    {
        $locations = Lib\Proxy\Locations::getAll() ?: array();
        $casest = ServicePrice::caSeSt();

        $list = array();
        foreach ( $locations as $location ) {
            if ( ! isset( $casest['locations'][ $location['id'] ] ) ) {
                continue;
            }
            $name = Lib\Utils\Common::getTranslatedString( 'location_' . $location['id'], $location['name'] );
            if ( $search !== '' && stripos( $name, $search ) === false ) {
                continue;
            }
            $list[] = array(
                'id'   => (int) $location['id'],
                'name' => $name,
                'info' => Lib\Utils\Common::getTranslatedString( 'location_' . $location['id'] . '_info', $location['info'] ),
            );
        }

        return $list;
    }
}
