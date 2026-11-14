<?php
namespace Bookly\Backend\Components\Dialogs\Queue;

use Bookly\Lib;
use Bookly\Lib\Entities\Notification;

class NotificationList
{
    protected $list = array();

    /**
     * @param Notification $notification
     * @param string|array $message
     * @param string $address
     * @param array $queue_data
     * @param \Bookly\Lib\Notifications\Assets\Base\Attachments[] $attachments
     * @param string|null $impersonal
     * @param string $subject
     * @param array $headers
     * @return void
     */
    public function add( Notification $notification, $message, $address, $queue_data = array(), $attachments = array(), $impersonal = null, $subject = null, $headers = array() )
    {
        $this->list[] = array(
            'data' => $queue_data,
            'gateway' => $notification->getGateway(),
            'name' => $notification->getName(),
            'address' => $address,
            'subject' => $subject,
            'message' => $message,
            'headers' => $headers,
            'type_id' => $notification->getTypeId(),
            'impersonal' => $impersonal,
            'attachments' => $attachments,
        );
    }

    /**
     * @return array
     */
    public function getList()
    {
        return $this->list;
    }

    /**
     * Send all collected notifications immediately, bypassing the persistent queue.
     * Same dispatch as Routine::sendNotificationsAssociatedWithQueue, but operating
     * on the in-memory list within the current request — no DB record, no dialog.
     * The list items hold fully rendered messages, so sending is mechanical.
     *
     * @return void
     */
    public function send()
    {
        $cloud = Lib\Cloud\API::getInstance();
        $fs = Lib\Utils\Common::getFilesystem();
        foreach ( $this->list as $notification ) {
            $gateway = $notification['gateway'];
            if ( $gateway === 'sms' ) {
                $cloud->getProduct( Lib\Cloud\Account::PRODUCT_SMS_NOTIFICATIONS )->sendSms( $notification['address'], $notification['message'], $notification['impersonal'], $notification['type_id'] );
            } elseif ( $gateway === 'email' ) {
                Lib\Utils\Mail::send( $notification['address'], $notification['subject'], $notification['message'], $notification['headers'], $notification['attachments'], $notification['type_id'] );
            } elseif ( $gateway === 'voice' ) {
                $cloud->getProduct( Lib\Cloud\Account::PRODUCT_VOICE )->call( $notification['address'], $notification['message'], $notification['impersonal'] );
            } elseif ( $gateway === 'whatsapp' ) {
                $cloud->getProduct( Lib\Cloud\Account::PRODUCT_WHATSAPP )->send( $notification['address'], $notification['message'] );
            }
            // Attachment files are transient (see Attachments::createFor) — remove them
            // once the message is sent, as the queue dispatch does.
            foreach ( $notification['attachments'] as $file ) {
                $fs->delete( $file, false, 'f' );
            }
        }
    }

    /**
     * @return array
     */
    public function getInfo()
    {
        $list = array();
        foreach ( $this->list as $notification ) {
            $list[] = array(
                'gateway' => $notification['gateway'],
                'user_name' => $notification['data']['name'],
                'address' => $notification['address'],
                'title' => $notification['name'],
            );
        }

        return $list;
    }
}