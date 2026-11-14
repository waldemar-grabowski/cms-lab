<?php
namespace Bookly\Backend\Modules\AiAdmin\Tools;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Actions\NotificationData;
use Bookly\Lib\Entities\Notification;

/**
 * list_notifications(gateway) — notifications of one channel as its table lists them, and what the
 * dialog of that channel offers on this site.
 */
class ListNotifications extends Tool
{
    const NAME = 'list_notifications';

    public function getName()
    {
        return self::NAME;
    }

    protected function description()
    {
        return 'Notifications of one channel (email, sms, voice, whatsapp): id, name, active, type, title, kind; and the types, statuses and attachments the dialog offers.';
    }

    protected function parameters()
    {
        return array(
            'type'       => 'object',
            'properties' => array(
                'gateway' => array( 'type' => 'string', 'enum' => NotificationData::gateways(), 'default' => 'email' ),
            ),
        );
    }

    public function execute( array $arguments )
    {
        if ( ! Lib\AiAdmin\Access::allowed( array( 'admin' ) ) ) {
            return self::json( array( 'error' => 'only a Bookly administrator can read notifications' ) );
        }
        $gateway = isset( $arguments['gateway'] ) ? $arguments['gateway'] : 'email';
        if ( ! in_array( $gateway, NotificationData::gateways(), true ) ) {
            return self::json( array( 'error' => 'gateway is email, sms, voice or whatsapp' ) );
        }
        $types = Notification::getTypes( $gateway );
        $rows  = Notification::query()
            ->select( 'id, name, active, type' )
            ->where( 'gateway', $gateway )
            ->whereIn( 'type', $types )
            ->sortBy( 'id' )
            ->fetchArray();
        // The table orders by the position of the type in the list of types.
        usort( $rows, function ( $a, $b ) use ( $types ) {
            $pa = array_search( $a['type'], $types );
            $pb = array_search( $b['type'], $types );

            return $pa === $pb ? (int) $a['id'] - (int) $b['id'] : $pa - $pb;
        } );
        $notifications = array();
        foreach ( $rows as $row ) {
            $notifications[] = array(
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'active' => (int) $row['active'],
                'type' => $row['type'],
                'title' => Notification::getTitle( $row['type'] ),
                'kind' => NotificationData::kind( $row['type'] ),
            );
        }

        return self::json( array(
            'gateway' => $gateway,
            'notifications' => $notifications,
            'allowed' => array(
                'types' => array_values( NotificationData::allowedTypes( $gateway ) ),
                'statuses' => NotificationData::statuses(),
                'invoices' => $gateway === 'email' && Lib\Config::invoicesActive(),
            ),
        ) );
    }
}
