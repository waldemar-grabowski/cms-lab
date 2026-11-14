<?php
namespace Bookly\Frontend\Modules\Ai\BookingTools;

use Bookly\Frontend\Modules\Ai;
use Bookly\Lib;

class CheckAvailability implements ToolInterface
{
    /**
     * @inheritDoc
     */
    public function getName()
    {
        return 'check_availability';
    }

    /**
     * @inheritDoc
     */
    public function getSchema()
    {
        $properties = array(
            'service_id' => array( 'type' => 'integer', 'description' => 'Service id, from the catalog.' ),
            'staff_id' => array( 'type' => 'integer', 'description' => 'Staff member id, from the catalog.' ),
            'start_date' => array( 'type' => 'string', 'description' => 'Requested start date/time, format "YYYY-MM-DD HH:MM:SS" (24-hour).' ),
        );

        // Only where the business has locations (Concepts).
        if ( Ai\Concepts::forSite()->has( Ai\Concepts::LOCATION ) ) {
            $properties['location_id'] = array( 'type' => 'integer', 'description' => Locations::ARGUMENT );
        }

        if ( Ai\Concepts::forSite()->has( Ai\Concepts::EXTRA ) ) {
            $properties['extras'] = array(
                'type' => 'array',
                'description' => 'The extras the customer chose. Omit or pass an empty array if none.',
                'items' => array(
                    'type' => 'object',
                    'properties' => array(
                        'extra_id' => array( 'type' => 'integer', 'description' => 'Extra id, from the service\'s extras list.' ),
                        'quantity' => array( 'type' => 'integer', 'description' => 'How many of this extra.' ),
                    ),
                    'required' => array( 'extra_id', 'quantity' ),
                ),
            );
        }

        if ( Ai\Concepts::forSite()->has( Ai\Concepts::GROUP ) ) {
            $properties['persons'] = array( 'type' => 'integer', 'description' => SlotLookup::PERSONS_ARGUMENT );
        }

        return array(
            'name' => $this->getName(),
            'description' => 'Check whether a start time the customer named themselves can be booked for a service with a staff member, and what it costs. Not needed for a start time get_available_slots listed, nor before create_booking, which checks the start time itself. Do not tell the customer a time of their own is free unless this tool confirmed it.',
            'parameters' => array(
                'type' => 'object',
                'properties' => $properties,
                'required' => array( 'service_id', 'staff_id', 'start_date' ),
            ),
        );
    }

    /**
     * @inheritDoc
     */
    public function execute( array $arguments )
    {
        $service_id  = isset( $arguments['service_id'] ) ? (int) $arguments['service_id'] : 0;
        $staff_id    = isset( $arguments['staff_id'] ) ? (int) $arguments['staff_id'] : 0;
        $start_date  = isset( $arguments['start_date'] ) ? trim( (string) $arguments['start_date'] ) : '';
        $location_id = isset( $arguments['location_id'] ) ? (int) $arguments['location_id'] : 0;
        $extras_arg  = isset( $arguments['extras'] ) && is_array( $arguments['extras'] ) ? $arguments['extras'] : array();

        $service = Lib\Entities\Service::find( $service_id );
        if ( ! $service || $service->getType() !== Lib\Entities\Service::TYPE_SIMPLE || $service->getVisibility() !== Lib\Entities\Service::VISIBILITY_PUBLIC ) {
            return 'Error: unknown service_id. Use a service id from the catalog.';
        }

        $staff = Lib\Entities\Staff::find( $staff_id );
        if ( ! $staff || $staff->getVisibility() !== 'public' ) {
            return 'Error: unknown staff_id. Use a staff member id from the catalog.';
        }

        if ( $location_id && ! Lib\Proxy\Locations::findById( $location_id ) ) {
            return 'Error: unknown location_id. Use a location id from the catalog.';
        }
        $not_there = Locations::checkStaff( $staff, $location_id );
        if ( $not_there !== null ) {
            return $not_there;
        }

        $link = new Lib\Entities\StaffService();
        $link->loadBy( array( 'staff_id' => $staff_id, 'service_id' => $service_id ) );
        if ( ! $link->isLoaded() ) {
            return 'Error: this staff member does not provide this service. The catalog lists the ones who do in the service\'s staff_ids or price_by_staff.';
        }

        try {
            $extras = ExtrasInput::parse( $service_id, $extras_arg );
        } catch ( \Exception $e ) {
            return 'Error: ' . $e->getMessage();
        }

        $nop = isset( $arguments['persons'] ) ? max( 1, (int) $arguments['persons'] ) : 1;
        $wrong_persons = SlotLookup::checkPersons( $service, $staff_id, $location_id, $nop );
        if ( $wrong_persons !== null ) {
            return $wrong_persons;
        }

        $start = \DateTime::createFromFormat( 'Y-m-d H:i:s', $start_date );
        if ( ! $start ) {
            return 'Error: start_date must be in the format YYYY-MM-DD HH:MM:SS.';
        }

        // SlotLookup rejects "too soon" slots as well, but can't tell the model why.
        $min_prior = (int) Lib\Proxy\Pro::getMinimumTimePriorBooking( $service_id );
        if ( $min_prior > 0 && Lib\Slots\DatePoint::now()->gte( Lib\Slots\DatePoint::fromStr( $start_date )->modify( -$min_prior ) ) ) {
            return 'Not available: this slot is too soon — this service requires at least ' . SlotLookup::describeNotice( $min_prior ) . ' advance notice. Find a later time with get_available_slots.';
        }

        $day_times = array();
        if ( ! SlotLookup::isBookable( $service, $staff_id, $start, $location_id ?: null, $extras, $day_times, $nop ) ) {
            return 'Not available: ' . $staff->getFullName() . ' cannot take "' . $service->getTitle() . '" at ' . $start_date . '. '
                . SlotLookup::describeAlternatives( $day_times, $start, $service );
        }

        $extras_duration = ExtrasInput::totalDuration( $extras );
        $display_end = ( clone $start )->modify( '+' . ( $service->getDuration() + $extras_duration ) . ' seconds' )->format( 'Y-m-d H:i:s' );
        $total_price = Lib\Utils\Price::format( ServicePrice::forBooking( $service, $staff_id, $location_id, $start_date, $extras, $nop ) );

        return 'Available: ' . $staff->getFullName() . ' can perform "' . $service->getTitle() . '"'
            . ( $extras ? ' with the requested extras' : '' ) . ( $nop > 1 ? ' for ' . $nop . ' people' : '' ) . ' starting ' . $start_date
            . ' (ends ' . $display_end . '), total price ' . $total_price . '.';
    }
}
