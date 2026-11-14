<?php
namespace Bookly\Backend\Modules\AiAdmin;

use Bookly\Backend\Components\Dialogs\BookingWizard;

/**
 * The booking wizard's slot search for find_free_slots. A subclass only to reach the protected
 * search: it is never registered as an Ajax handler (init() is not called), so it adds no endpoint.
 */
class WizardSlots extends BookingWizard\Ajax
{
    /**
     * @param array $params see BookingWizard\Ajax::findSlots()
     * @return array|null
     */
    public static function find( array $params )
    {
        return self::findSlots( $params );
    }
}
