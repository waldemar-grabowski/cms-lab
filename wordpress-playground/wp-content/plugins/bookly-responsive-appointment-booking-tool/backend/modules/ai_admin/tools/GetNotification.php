<?php
namespace Bookly\Backend\Modules\AiAdmin\Tools;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Actions\NotificationData;
use Bookly\Lib\Entities\Notification;

/**
 * get_notification(notification_id) — one notification in the shape set_notification takes.
 */
class GetNotification extends Tool
{
    const NAME = 'get_notification';

    public function getName()
    {
        return self::NAME;
    }

    protected function description()
    {
        return 'One notification in the shape set_notification takes: name, active, subject, message, recipients, attachments, settings.';
    }

    protected function parameters()
    {
        return array(
            'type'       => 'object',
            'properties' => array( 'notification_id' => array( 'type' => 'integer' ) ),
            'required'   => array( 'notification_id' ),
        );
    }

    public function execute( array $arguments )
    {
        if ( ! Lib\AiAdmin\Access::allowed( array( 'admin' ) ) ) {
            return self::json( array( 'error' => 'only a Bookly administrator can read notifications' ) );
        }
        $id = isset( $arguments['notification_id'] ) ? (int) $arguments['notification_id'] : 0;
        $notification = new Notification();
        if ( ! $id || ! $notification->load( $id ) ) {
            return self::json( array( 'not_found' => $id ) );
        }
        $record = NotificationData::record( $notification );
        if ( ! NotificationData::listed( $record ) ) {
            return self::json( array( 'not_found' => $id ) );
        }

        return self::json( NotificationData::data( $record ) );
    }
}
