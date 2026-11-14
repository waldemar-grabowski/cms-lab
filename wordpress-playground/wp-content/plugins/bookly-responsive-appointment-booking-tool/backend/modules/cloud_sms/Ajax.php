<?php
namespace Bookly\Backend\Modules\CloudSms;

use Bookly\Lib;

class Ajax extends Lib\Base\Ajax
{
    /**
     * @inheritDoc
     */
    protected static function permissions()
    {
        return array(
            'sendQueue' => array( 'supervisor', 'staff' ),
            'clearAttachments' => array( 'supervisor', 'staff' ),
        );
    }

    /**
     * Get SMS list.
     */
    public static function getSmsList()
    {
        $filter = self::parameter( 'filter' );
        $range = $filter['range'];
        if ($range === 'any') {
            $start_date = null;
            $end_date = null;
        } else {
            $dates = explode( ' - ', $range, 2 );
            $start_date = Lib\Utils\DateTime::applyTimeZoneOffset( $dates[0], 0 );
            $end_date = Lib\Utils\DateTime::applyTimeZoneOffset( date( 'Y-m-d', strtotime( '+1 day', strtotime( $dates[1] ) ) ), 0 );
        }

        $length = self::parameter( 'length' );
        $start = self::parameter( 'start' );

        $data = Lib\Cloud\API::getInstance()->getProduct( Lib\Cloud\Account::PRODUCT_SMS_NOTIFICATIONS )->getSmsList( $start, $length, compact( 'start_date', 'end_date' ) );
        $data['draw'] = (int) self::parameter( 'draw' );

        Lib\Utils\Tables::updateSettings( Lib\Utils\Tables::SMS_DETAILS, null, null, $filter );

        wp_send_json( $data );
    }

    /**
     * Get price-list.
     */
    public static function getPriceList()
    {
        wp_send_json( Lib\Cloud\API::getInstance()->getProduct( Lib\Cloud\Account::PRODUCT_SMS_NOTIFICATIONS )->getPriceList() );
    }

    /**
     * Send test SMS.
     */
    public static function sendTestSms()
    {
        $cloud = Lib\Cloud\API::getInstance();
        $response = array(
            'success' => $cloud->getProduct( Lib\Cloud\Account::PRODUCT_SMS_NOTIFICATIONS )->sendSms(
                self::parameter( 'phone_number' ),
                'Bookly test SMS.',
                'Bookly test SMS.',
                0
            ),
        );

        if ( $response['success'] ) {
            $response['message'] = __( 'SMS has been sent successfully.', 'bookly-responsive-appointment-booking-tool' );
        } else {
            $response['message'] = implode( ' ', $cloud->getErrors() );
        }

        wp_send_json( $response );
    }

    /**
     * Resend sms notification
     *
     * @return void
     */
    public static function resendSms()
    {
        $sms_list = Lib\Entities\SmsLog::query()->whereIn( 'ref_id', self::parameter( 'ids' ) )->find();
        if ( $sms_list ) {
            $has_errors = false;
            $cloud = Lib\Cloud\API::getInstance();
            foreach ( $sms_list as $sms ) {
                $response = array(
                    'success' => $cloud->getProduct( Lib\Cloud\Account::PRODUCT_SMS_NOTIFICATIONS )->sendSms(
                        $sms->getPhone(),
                        $sms->getMessage(),
                        $sms->getImpersonalMessage(),
                        $sms->getTypeId()
                    ),
                );

                if ( !$response['success'] ) {
                    $has_errors = true;
                }
            }
            $response['success'] = !$has_errors;
            if ( $has_errors ) {
                $response['message'] = implode( '<br/>', $cloud->getErrors() );
            } else {
                $response['message'] = __( 'SMS has been sent successfully.', 'bookly-responsive-appointment-booking-tool' );
            }

            wp_send_json( $response );
        }
    }

    /**
     * Get Sender ID countries.
     */
    public static function getSenderIdCountries()
    {
        $response = Lib\Cloud\API::getInstance()->getProduct( Lib\Cloud\Account::PRODUCT_SMS_NOTIFICATIONS )->getSenderIdCountries();

        // Pre-render the "{N} countries" label for the noreg group so the JS
        // doesn't have to handle plural rules per locale — _n() picks the
        // correct form for the current WP locale.
        if ( is_array( $response ) && isset( $response['list'] ) && is_array( $response['list'] ) ) {
            $noreg_count = 0;
            foreach ( $response['list'] as $country ) {
                if ( empty( $country['custom_request_procedure'] ) ) {
                    $noreg_count++;
                }
            }
            $response['noreg_label'] = sprintf( _n( '%d country', '%d countries', $noreg_count, 'bookly-responsive-appointment-booking-tool' ), $noreg_count );
        }

        wp_send_json( $response );
    }

    /**
     * Get Sender IDs list.
     */
    public static function getSenderIdsList()
    {
        wp_send_json( Lib\Cloud\API::getInstance()->getProduct( Lib\Cloud\Account::PRODUCT_SMS_NOTIFICATIONS )->getSenderIdsList() );
    }

    /**
     * Request new Sender ID.
     */
    public static function requestSenderId()
    {
        $cloud = Lib\Cloud\API::getInstance();
        $result = $cloud->getProduct( Lib\Cloud\Account::PRODUCT_SMS_NOTIFICATIONS )->requestSenderId( self::parameter( 'sender_id' ), self::parameter( 'country', '' ), self::parameter( 'document_code', '' ) );
        if ( $result === false ) {
            wp_send_json_error( array( 'message' => current( $cloud->getErrors() ) ) );
        } else {
            wp_send_json_success( array( 'request_id' => $result['request_id'] ) );
        }
    }

    /**
     * Cancel request for Sender ID.
     */
    public static function cancelSenderId()
    {
        $cloud = Lib\Cloud\API::getInstance();
        $result = $cloud->getProduct( Lib\Cloud\Account::PRODUCT_SMS_NOTIFICATIONS )->cancelSenderId( self::parameter( 'ids', array() ) );
        if ( $result === false ) {
            wp_send_json_error( array( 'message' => current( $cloud->getErrors() ) ) );
        } else {
            wp_send_json_success();
        }
    }

    /**
     * Delete notification.
     */
    public static function deleteNotification()
    {
        Lib\Entities\Notification::query()
            ->delete()
            ->where( 'id', self::parameter( 'id' ) )
            ->execute();

        wp_send_json_success();
    }

    /**
     * Get data for notification list.
     */
    public static function getNotifications()
    {
        $types = Lib\Entities\Notification::getTypes( self::parameter( 'gateway' ) );

        $notifications = Lib\Entities\Notification::query()
            ->select( 'id, name, active, type' )
            ->where( 'gateway', self::parameter( 'gateway' ) )
            ->whereIn( 'type', $types )
            ->fetchArray();

        foreach ( $notifications as &$notification ) {
            $notification['order'] = array_search( $notification['type'], $types );
            $notification['icon'] = Lib\Entities\Notification::getIcon( $notification['type'] );
            $notification['title'] = Lib\Entities\Notification::getTitle( $notification['type'] );
        }

        wp_send_json_success( $notifications );
    }

    /**
     * Activate/Suspend notification.
     */
    public static function setNotificationsState()
    {
        Lib\Entities\Notification::query()
            ->update()
            ->set( 'active', (int) self::parameter( 'active' ) )
            ->whereIn( 'id', self::parameter( 'ids' ) )
            ->execute();

        wp_send_json_success();
    }

    /**
     * Remove notification(s).
     */
    public static function deleteNotifications()
    {
        $notifications = array_map( 'intval', self::parameter( 'notifications', array() ) );
        Lib\Entities\Notification::query()->delete()->whereIn( 'id', $notifications )->execute();
        wp_send_json_success();
    }

    public static function saveAdministratorPhone()
    {
        update_option( 'bookly_sms_administrator_phone', self::parameter( 'bookly_sms_administrator_phone' ) );
        wp_send_json_success();
    }

    /**
     * Send queue
     */
    public static function sendQueue()
    {
        Lib\Notifications\Routine::sendNotificationsAssociatedWithQueue( self::parameter( 'notifications', array() ), self::parameter( 'type', 'all' ), self::parameter( 'token' ) );
        wp_send_json_success();
    }

    /**
     * Delete attachments files
     */
    public static function clearAttachments()
    {
        /** @var Lib\Entities\NotificationQueue $queue */
        $queue = Lib\Entities\NotificationQueue::query()->where( 'token', self::parameter( 'token' ) )->where( 'sent', 0 )->findOne();
        if ( $queue ) {
            $queue_data = json_decode( $queue->getData(), true );
            Lib\Notifications\Routine::deleteNotificationAttachmentFiles( $queue_data );
            $queue->setSent( 1 )->save();
        }

        wp_send_json_success();
    }

    /**
     * Get campaigns with their audience and results
     */
    public static function getCampaignList()
    {
        global $wpdb;

        $columns = Lib\Utils\Tables::filterColumns( self::parameter( 'columns' ), Lib\Utils\Tables::SMS_MAILING_CAMPAIGNS );
        $order = self::parameter( 'order', array() );
        $filter = self::parameter( 'filter' );
        $length = self::parameter( 'length' );
        $start = self::parameter( 'start' );

        $queue = Lib\Entities\MailingQueue::getTableName();
        $query = Lib\Entities\MailingCampaign::query( 'mc' )
            ->select( 'mc.id, mc.name, mc.state, mc.send_at, mc.created_at, mc.audience, mc.mailing_list_id,
                (SELECT COUNT(*) FROM `' . $queue . '` q WHERE q.campaign_id = mc.id) AS queued,
                (SELECT COUNT(*) FROM `' . $queue . '` q WHERE q.campaign_id = mc.id AND q.sent = ' . Lib\Entities\MailingQueue::SENT_YES . ') AS sent,
                (SELECT COUNT(*) FROM `' . $queue . '` q WHERE q.campaign_id = mc.id AND q.sent = ' . Lib\Entities\MailingQueue::SENT_FAILED . ') AS failed' );

        foreach ( $order as $sort_by ) {
            $query->sortBy( str_replace( '.', '_', $columns[ $sort_by['column'] ]['data'] ) )
                ->order( $sort_by['dir'] == 'desc' ? Lib\Query::ORDER_DESCENDING : Lib\Query::ORDER_ASCENDING );
        }

        $total = $filtered = $query->count();

        if ( isset( $filter['search'] ) && $filter['search'] != '' ) {
            $fields = array();
            foreach ( $columns as $column ) {
                switch ( $column['data'] ) {
                    case 'name':
                    case 'id':
                        $fields[] = $column['data'];
                        break;
                }
            }
            $search_columns = array();
            foreach ( $fields as $field ) {
                $search_columns[] = $field . ' LIKE "%%%s%"';
            }
            if ( ! empty( $search_columns ) ) {
                $query->whereRaw( implode( ' OR ', $search_columns ), array_fill( 0, count( $search_columns ), $wpdb->esc_like( $filter['search'] ) ) );
                $filtered = $query->count();
            }
        }

        if ( $length !== null && $start !== null ) {
            $query->limit( $length )->offset( $start );
        }

        $data = $query->fetchArray();
        foreach ( $data as &$row ) {
            // Once queued, the recipients are fixed; until then a preset is counted as of now.
            $audience = Lib\Mailing\Audience::normalize( $row['audience'] );
            $row['audience'] = $audience && $audience['type'] === Lib\Mailing\Audience::TYPE_PRESET ? $audience['key'] : Lib\Mailing\Audience::TYPE_CUSTOM;
            if ( $row['queued'] > 0 ) {
                $row['recipients'] = (int) $row['queued'];
            } elseif ( $audience && $audience['type'] === Lib\Mailing\Audience::TYPE_PRESET ) {
                $row['recipients'] = count( Lib\Mailing\Audience::recipients( $audience ) );
            } else {
                $row['recipients'] = $row['mailing_list_id'] ? Lib\Entities\MailingListRecipient::query()->where( 'mailing_list_id', $row['mailing_list_id'] )->count() : 0;
            }
            unset( $row['mailing_list_id'], $row['queued'] );
        }
        unset( $row );

        unset( $filter['search'] );

        Lib\Utils\Tables::updateSettings( Lib\Utils\Tables::SMS_MAILING_CAMPAIGNS, $columns, $order, $filter );

        wp_send_json( array(
            'draw' => ( int ) self::parameter( 'draw' ),
            'data' => $data,
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
        ) );
    }

    /**
     * Delete campaigns
     */
    public static function deleteCampaigns()
    {
        $ids = array_map( 'intval', self::parameter( 'ids', array() ) );
        $list_ids = Lib\Entities\MailingCampaign::query()->whereIn( 'id', $ids )->whereNot( 'mailing_list_id', null )->fetchCol( 'mailing_list_id' );
        Lib\Entities\MailingCampaign::query()->delete()->whereIn( 'id', $ids )->execute();
        // What a deleted campaign has not sent yet is not sent.
        Lib\Entities\MailingQueue::query()
            ->delete()
            ->whereIn( 'campaign_id', $ids )
            ->where( 'sent', Lib\Entities\MailingQueue::SENT_NO )
            ->execute();
        foreach ( array_unique( $list_ids ) as $list_id ) {
            Lib\Entities\MailingList::releaseUnused( $list_id );
        }

        wp_send_json_success();
    }
}