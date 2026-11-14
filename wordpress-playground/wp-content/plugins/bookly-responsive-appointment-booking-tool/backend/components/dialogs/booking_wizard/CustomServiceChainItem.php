<?php
namespace Bookly\Backend\Components\Dialogs\BookingWizard;

use Bookly\Lib;

/**
 * Chain item for a custom service (service_id = null).
 *
 * The slot engine resolves durations and providers through getService()/getSubServices(),
 * which load the service from the database — impossible for a custom service that only
 * exists as user input. This subclass carries a synthetic (unsaved) Service entity with
 * the entered duration instead, which routes the search into the engine's custom-service
 * branch (see Finder::_prepareStaffData) with regular schedules and bookings applied.
 */
class CustomServiceChainItem extends Lib\ChainItem
{
    /** @var Lib\Entities\Service synthetic service, not persisted */
    protected $custom_service;

    /**
     * @param Lib\Entities\Service $service
     * @return $this
     */
    public function setCustomService( Lib\Entities\Service $service )
    {
        $this->custom_service = $service;

        return $this;
    }

    /**
     * @return Lib\Entities\Service
     */
    public function getService()
    {
        return $this->custom_service;
    }

    /**
     * @return Lib\Entities\Service[]
     */
    public function getSubServices()
    {
        return array( $this->custom_service );
    }
}
