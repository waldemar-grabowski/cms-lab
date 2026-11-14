<?php
namespace Bookly\Backend\Modules\AiAdmin\Tools;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Actions\SetCustomStatus;
use Bookly\Lib\AiAdmin\State;

/**
 * list_custom_statuses() — own appointment statuses of the Custom Statuses add-on in the order of
 * the Order dialog, with the number of appointments that have each; the shape set_custom_status
 * and sync_custom_statuses_order take.
 */
class ListCustomStatuses extends Tool
{
    const NAME = 'list_custom_statuses';

    public function getName()
    {
        return self::NAME;
    }

    public function available()
    {
        return Lib\Config::customStatusesActive();
    }

    protected function description()
    {
        return 'Own appointment statuses: id, slug (what options refer to), name, busy (1 holds a place in the time slot, 0 releases it), position, color, number of appointments with the status.';
    }

    protected function parameters()
    {
        return array( 'type' => 'object', 'properties' => array() );
    }

    public function execute( array $arguments )
    {
        return self::json( array_values( SetCustomStatus::statuses( new State() ) ) );
    }
}
