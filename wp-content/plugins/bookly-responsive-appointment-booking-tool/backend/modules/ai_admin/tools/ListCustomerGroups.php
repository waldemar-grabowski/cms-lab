<?php
namespace Bookly\Backend\Modules\AiAdmin\Tools;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\Actions\SetCustomerGroup;

/**
 * list_customer_groups() — customer groups and the settings for customers without a group, in the
 * shape set_customer_group takes, with what the group dialog offers on this site.
 */
class ListCustomerGroups extends Tool
{
    const NAME = 'list_customer_groups';

    public function getName()
    {
        return self::NAME;
    }

    protected function description()
    {
        return 'Customer groups and the settings for customers without a group, in the shape set_customer_group takes.';
    }

    protected function parameters()
    {
        return array( 'type' => 'object', 'properties' => array() );
    }

    public function execute( array $arguments )
    {
        if ( ! Access::allowed( array( 'admin' ) ) ) {
            return self::json( array( 'error' => 'forbidden' ) );
        }
        if ( ! Lib\Config::proActive() ) {
            return self::json( array( 'unavailable' => 'pro' ) );
        }
        if ( ! Lib\Config::customerGroupsActive() ) {
            return self::json( array( 'unavailable' => 'customer-groups' ) );
        }

        return self::json( array(
            'groups' => array_values( SetCustomerGroup::groups() ),
            'general_settings' => SetCustomerGroup::general(),
            'allowed' => array(
                'appointment_status' => SetCustomerGroup::statuses(),
                'gateways' => array_keys( Lib\Utils\Common::getGateways() ),
            ),
        ) );
    }
}
