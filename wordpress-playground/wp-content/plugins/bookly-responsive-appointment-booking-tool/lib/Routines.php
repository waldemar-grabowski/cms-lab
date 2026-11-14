<?php
namespace Bookly\Lib;

use Bookly\Lib\Cloud\Account;
use Bookly\Lib\Entities\AiConversation;
use Bookly\Lib\Entities\AiJob;
use Bookly\Lib\Entities\Appointment;
use Bookly\Lib\Entities\CustomerAppointment;
use Bookly\Lib\Entities\Log;
use Bookly\Lib\Entities\MailingCampaign;
use Bookly\Lib\Entities\MailingListRecipient;
use Bookly\Lib\Entities\MailingQueue;
use Bookly\Lib\Entities\NotificationQueue;
use Bookly\Lib\Entities\Payment;
use Bookly\Lib\Entities\SmsLog;
use Bookly\Frontend\Modules\Ai;
use Bookly\Frontend\Modules\Payment\Request as PaymentRequest;

abstract class Routines
{
    /**
     * Init routines.
     */
    public static function init()
    {
        // Register daily routine.
        add_action( 'bookly_daily_routine', array( __CLASS__, 'doDailyRoutine' ), 10, 0 );

        // Register hourly routine.
        add_action( 'bookly_hourly_routine', array( __CLASS__, 'doRoutine' ), 10, 0 );

        // Schedule daily routine.
        if ( ! wp_next_scheduled( 'bookly_daily_routine' ) ) {
            wp_schedule_event( current_time( 'timestamp' ), 'daily', 'bookly_daily_routine' );
        }

        // Schedule hourly routine.
        if ( ! wp_next_scheduled( 'bookly_hourly_routine' ) ) {
            wp_schedule_event( current_time( 'timestamp' ), 'hourly', 'bookly_hourly_routine' );
        }
    }

    public static function doRoutine()
    {
        $transient_name = 'bookly_do_routine';
        $lock = (int) get_transient( $transient_name );
        if ( $lock + 120 < time() ) {
            set_transient( $transient_name, time(), 120 );
            set_time_limit( 120 );
            // Email and SMS notifications routine.
            Notifications\Routine::sendNotifications();
            // Handle outdated unpaid payments
            self::handleUnpaidPayments();
            // Let add-ons do their hourly routines.
            Proxy\Shared::doHourlyRoutine();
            // Handle mailing campaigns
            self::mailing();
            // Handle expired sessions
            self::clearSessions();
            // Handle expired logs
            self::clearLogs();
            // Watchdog for stuck AI worker jobs — catches a job that reached
            // status='running' and then stalled (heartbeat_at goes stale,
            // e.g. host killed the php-fpm process mid-loop).
            self::handleAiJobsWatchdog();
        }

        self::doDailyRoutine();
    }

    public static function doDailyRoutine()
    {
        $transient_name = 'bookly_do_daily_routine';
        $lock = (int) get_transient( $transient_name );
        if ( $lock + DAY_IN_SECONDS <= time() ) {
            set_transient( $transient_name, time(), DAY_IN_SECONDS );
            // Daily info routine.
            self::handleDailyInfo();
            // Cloud routine.
            self::loadCloudInfo();
            // Statistics routine.
            self::sendDailyStatistics();
            UsageStats::send();
            // Calculate goal by number of customer appointments achieved
            self::calculateGoalOfCA();
            self::clearNotificationQueue();
            self::clearSmsLog();
            self::clearAiConversations();
            // Let add-ons do their daily routines.
            Proxy\Shared::doDailyRoutine();
        }
    }

    /**
     * Handle outdated payments
     */
    public static function handleUnpaidPayments()
    {
        $payment_ids = array();
        $timeout = (int) get_option( 'bookly_cloud_stripe_timeout' );
        if ( $timeout ) {
            // Get list of outdated unpaid Cloud Stripe payments
            $payment_ids = Payment::query()
                ->where( 'type', Payment::TYPE_CLOUD_STRIPE )
                ->where( 'status', Payment::STATUS_PENDING )
                ->whereLt( 'created_at', date_create( current_time( 'mysql' ) )->modify( sprintf( '- %s seconds', $timeout ) )->format( 'Y-m-d H:i:s' ) )
                ->fetchCol( 'id' );
        }

        // Mark unpaid appointments as rejected.
        $payment_ids = \Bookly\Lib\Payment\Proxy\Shared::prepareOutdatedUnpaidPayments( $payment_ids );
        if ( $payment_ids ) {
            // Complete payments paid in the payment system but not confirmed yet
            foreach ( Payment::query()->whereIn( 'id', $payment_ids )->find() as $payment ) {
                $gateway = PaymentRequest::getInstance()->getGatewayByName( $payment->getType() );
                if ( $gateway instanceof Base\Gateway ) {
                    $gateway->setPayment( $payment )->retrieveOutdated();
                }
            }
            $payment_ids = Payment::query()->whereIn( 'id', $payment_ids )->where( 'status', Payment::STATUS_PENDING )->fetchCol( 'id' );
        }
        if ( ! empty( $payment_ids ) ) {
            Proxy\Shared::unpaidPayments( $payment_ids );
            Payment::query()
                ->update()
                ->set( 'status', Payment::STATUS_REJECTED )
                ->whereIn( 'id', $payment_ids )
                ->execute();
            Utils\Log::put( Utils\Log::ACTION_DEBUG, 'Payments', null, json_encode( $payment_ids ), null, 'Handle outdated unpaid payments (set REJECTED status)' );
            CustomerAppointment::query()
                ->update()
                ->set( 'status', CustomerAppointment::STATUS_REJECTED )
                ->set( 'status_changed_at', current_time( 'mysql' ) )
                ->whereIn( 'payment_id', $payment_ids )
                ->execute();
            $affected_appointments = Appointment::query( 'a' )
                ->leftJoin( 'CustomerAppointment', 'ca', 'a.id = ca.appointment_id' )
                ->whereNot( 'a.start_date', null )
                ->whereIn( 'ca.payment_id', $payment_ids )
                ->fetchCol( 'a.id' );
            // Reject recurring appointments when customer pay only for first one.
            $series = CustomerAppointment::query()
                ->whereIn( 'payment_id', $payment_ids )
                ->whereNot( 'series_id', null )
                ->fetchCol( 'series_id' );
            if ( ! empty( $series ) ) {
                CustomerAppointment::query()
                    ->update()
                    ->set( 'status', CustomerAppointment::STATUS_REJECTED )
                    ->set( 'status_changed_at', current_time( 'mysql' ) )
                    ->whereIn( 'series_id', $series )
                    ->execute();
                $affected_appointments = array_merge( $affected_appointments, Appointment::query( 'a' )
                    ->leftJoin( 'CustomerAppointment', 'ca', 'a.id = ca.appointment_id' )
                    ->whereNot( 'a.start_date', null )
                    ->whereIn( 'ca.series_id', $series )
                    ->fetchCol( 'a.id' ) );
            }

            /** @var Appointment $appointment */
            $affected_appointments = array_unique( $affected_appointments );
            Utils\Log::put( Utils\Log::ACTION_DEBUG, 'Appointments', null, json_encode( $affected_appointments ), null, 'Handle outdated unpaid payments (affected appointments)' );
            foreach ( $affected_appointments as $appointment_id ) {
                $appointment = Appointment::find( $appointment_id );
                Proxy\Shared::syncOnlineMeeting( array(), $appointment );
                Utils\Common::syncWithCalendars( $appointment );
            }
        }
    }

    public static function handleAiJobsWatchdog()
    {
        $stale_before = date_create( current_time( 'mysql' ) )->modify( '-5 minutes' )->format( 'Y-m-d H:i:s' );

        /** @var AiJob[] $stuck */
        $stuck = AiJob::query()
            ->where( 'status', AiJob::STATUS_RUNNING )
            ->whereLt( 'heartbeat_at', $stale_before )
            ->find();

        foreach ( $stuck as $job ) {
            if ( $job->getAttempts() >= 3 ) {
                $job->setStatus( AiJob::STATUS_FAILED )->save();
                $conversation = AiConversation::find( $job->getConversationId() );
                if ( $conversation ) {
                    $conversation->setStatus( AiConversation::STATUS_ERROR )->save();
                }
                continue;
            }

            $job->setStatus( AiJob::STATUS_QUEUED )->save();
            $conversation = AiConversation::find( $job->getConversationId() );
            if ( $conversation && $conversation->getAgent() === AiConversation::AGENT_ADMIN ) {
                \Bookly\Backend\Modules\AiAdmin\Ajax::spawnWorker( $job );
            } else {
                Ai\Ajax::spawnWorker( $job );
            }
        }
    }

    /**
     * Daily info routine.
     */
    public static function handleDailyInfo()
    {
        $date = Entities\News::query( 'n' )
            ->select( 'MAX(updated_at) AS max_date' )
            ->fetchRow();

        $data = API::getInfo( $date['max_date'] );

        if ( is_array( $data ) ) {
            // The catalogue is not read from here. Hub still serves it under the same
            // `plugins` key, but for installations too old to know about Cloud — this
            // one takes it from Cloud, in a richer shape, and taking both would make the
            // product name change once a day: Hub carries the marketplace listing, Cloud
            // the clean name and our own artwork.
            //
            // News stay: they are a Hub mailing and have no Cloud counterpart.
            if ( isset( $data['news'] ) ) {
                foreach ( $data['news'] as $incoming_news ) {
                    $news = new Entities\News();
                    $news->loadBy( array( 'news_id' => $incoming_news['id'] ) );
                    if ( $news->isLoaded() && ! $incoming_news['published'] ) {
                        $news->delete();
                    } else {
                        $news
                            ->setNewsId( $incoming_news['id'] )
                            ->setTitle( $incoming_news['title'] )
                            ->setText( $incoming_news['text'] )
                            ->setMediaType( $incoming_news['mediaType'] )
                            ->setMediaUrl( $incoming_news['mediaUrl'] )
                            ->setButtonText( $incoming_news['buttonText'] )
                            ->setButtonUrl( $incoming_news['buttonUrl'] )
                            ->setSeen( $news->isLoaded() ? $news->getSeen() : 0 )
                            ->setCreatedAt( $incoming_news['createdAt'] )
                            ->setUpdatedAt( $incoming_news['updatedAt'] )
                            ->save();
                    }
                }
            }
        }
    }

    /**
     * Load Bookly Cloud products, promotions, etc.
     */
    public static function loadCloudInfo()
    {
        Cloud\API::getInstance()->general->loadInfo();
    }

    /**
     * Statistics routine.
     */
    public static function sendDailyStatistics()
    {
        if ( get_option( 'bookly_gen_collect_stats' ) ) {
            API::sendStats();
        }
    }

    public static function calculateGoalOfCA()
    {
        /** @global \wpdb $wpdb */
        global $wpdb;

        $ca_count = get_option( 'bookly_сa_count' );
        $quantity = Entities\CustomerAppointment::query()->count();
        if ( $quantity ) {
            $log10 = (int) log10( $quantity );
            $current = $log10 > 0 ? pow( 10, $log10 ) : 0;
        } else {
            $current = 0;
        }

        if ( $ca_count != $current ) {
            // New goal by number of customer appointments achieved,
            // corresponding hide until values are reset to show call to rate Bookly on WP
            $wpdb->query( $wpdb->prepare(
                'UPDATE `' . $wpdb->usermeta . '` SET `meta_value` = %d WHERE `meta_key` = \'bookly_notice_rate_on_wp_hide_until\' AND meta_value != 0',
                time()
            ) );

            update_option( 'bookly_сa_count', $current );
        }
    }

    protected static function clearNotificationQueue()
    {
        $list = NotificationQueue::query()->whereRaw( 'created_at < DATE(NOW() - INTERVAL 2 DAY)', array() )->find();
        foreach ( $list as $notification ) {
            $notification->delete();
        }
    }

    protected static function clearSmsLog()
    {
        SmsLog::query()->delete()
            ->whereRaw( 'created_at < DATE(NOW() - INTERVAL 1 MONTH)', array() )
            ->execute();
    }

    protected static function clearAiConversations()
    {
        AiConversation::query()->delete()
            ->whereRaw( 'created_at < DATE(NOW() - INTERVAL 1 MONTH)', array() )
            ->execute();
    }

    public static function mailing()
    {
        $cloud = Cloud\API::getInstance();
        if ( $cloud->getToken() ) {
            global $wpdb;
            /** @var MailingCampaign $mc */
            while ( $mc = MailingCampaign::query()
                ->where( 'state', MailingCampaign::STATE_PENDING )
                ->whereLte( 'send_at', current_time( 'mysql' ) )
                ->findOne() ) {
                $mc->setState( MailingCampaign::STATE_IN_PROGRESS )->save();
                $audience = Mailing\Audience::normalize( $mc->getAudience() );
                if ( $audience && $audience['type'] === Mailing\Audience::TYPE_PRESET ) {
                    // A preset audience is resolved at the moment of sending.
                    $now = current_time( 'mysql' );
                    foreach ( array_chunk( Mailing\Audience::recipients( $audience ), 500 ) as $chunk ) {
                        $values = array();
                        foreach ( $chunk as $recipient ) {
                            $values[] = $wpdb->prepare( '(%s, %s, %s, %d, %d, %s)', $recipient['phone'], $recipient['name'], $mc->getText(), MailingQueue::SENT_NO, $mc->getId(), $now );
                        }
                        $wpdb->query( 'INSERT INTO `' . MailingQueue::getTableName() . '` (phone, name, text, sent, campaign_id, created_at) VALUES ' . implode( ',', $values ) );
                    }
                } else {
                    $query = 'INSERT INTO `' . MailingQueue::getTableName() . '` (phone, name, text, sent, campaign_id, created_at)
                              SELECT mlr.phone, mlr.name, %s, 0, %d, %s
                                FROM `' . MailingListRecipient::getTableName() . '` AS mlr
                               WHERE mlr.mailing_list_id = %s';
                    $wpdb->query( $wpdb->prepare( $query, $mc->getText(), $mc->getId(), current_time( 'mysql' ), $mc->getMailingListId() ) );
                }
            }

            $sms_sender = $cloud->getProduct( Account::PRODUCT_SMS_NOTIFICATIONS );
            if ( get_transient( 'bookly_mailing_out_of_credits' ) ) {
                // Paused for lack of credits: a send attempt would only leave one more
                // undelivered SMS behind, so the balance is asked first.
                $cloud->account->loadProfile();
                if ( $cloud->account->getBalance() <= 0 && ! $cloud->account->autoRechargeEnabled() ) {
                    return;
                }
                delete_transient( 'bookly_mailing_out_of_credits' );
            }
            /** @var MailingQueue $sms */
            while ( $sms = MailingQueue::query()->where( 'sent', MailingQueue::SENT_NO )->sortBy( 'id' )->findOne() ) {
                // Taken before sending, so that a parallel run does not send it twice.
                $sms->setSent( MailingQueue::SENT_YES )->save();

                $codes = new Notifications\Assets\Mailing\Codes( $sms );
                $message = $codes->replaceForSms( $sms->getText() );
                if ( ! $sms_sender->sendSms( $sms->getPhone(), $message['personal'], $message['impersonal'], 60 ) ) {
                    if ( $sms_sender->outOfCredits() ) {
                        // Pause: the rest waits for the balance to be topped up and goes out on a later run.
                        $sms->setSent( MailingQueue::SENT_NO )->save();
                        set_transient( 'bookly_mailing_out_of_credits', 1, WEEK_IN_SECONDS );
                        break;
                    }
                    $sms->setSent( MailingQueue::SENT_FAILED )->save();
                }
            }

            // A campaign is completed once nothing of it is left to send.
            $wpdb->query( $wpdb->prepare(
                'UPDATE `' . MailingCampaign::getTableName() . '` mc SET mc.state = %s
                  WHERE mc.state = %s AND NOT EXISTS (SELECT 1 FROM `' . MailingQueue::getTableName() . '` q WHERE q.campaign_id = mc.id AND q.sent = %d)',
                MailingCampaign::STATE_COMPLETED, MailingCampaign::STATE_IN_PROGRESS, MailingQueue::SENT_NO
            ) );
        }
    }

    public static function clearSessions()
    {
        FormSession::clearExpiredSessions();
    }

    public static function clearLogs()
    {
        Log::query()->delete()
            ->whereRaw( 'created_at < DATE(NOW() - INTERVAL %s DAY)', array( get_option( 'bookly_logs_expire', 30 ) ) )
            ->execute();

        foreach ( Utils\Log::getTypes() as $type ) {
            $option_value = get_option( $type );
            if ( $option_value && ( date_create( $option_value ) < date_create() ) ) {
                update_option( $type, '0' );
            }
        }
    }
}