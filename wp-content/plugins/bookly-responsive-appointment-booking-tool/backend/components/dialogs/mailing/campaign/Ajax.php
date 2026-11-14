<?php
namespace Bookly\Backend\Components\Dialogs\Mailing\Campaign;

use Bookly\Lib;
use Bookly\Lib\Entities\MailingCampaign;
use Bookly\Lib\Entities\MailingList;
use Bookly\Lib\Entities\MailingListRecipient;
use Bookly\Lib\Entities\MailingQueue;
use Bookly\Lib\Mailing\Audience;

class Ajax extends Lib\Base\Ajax
{
    /** Recipients shown in the preview of an audience */
    const PREVIEW_SIZE = 50;

    /**
     * Number of recipients of every preset with its default limits.
     */
    public static function getAudienceCounts()
    {
        wp_send_json_success( Audience::presetCounts() );
    }

    /**
     * Recipients of an audience: their number, the first of them, and the one with
     * the longest name, whose message is the longest to send.
     * A campaign with a saved list goes to that list.
     */
    public static function getAudienceRecipients()
    {
        $campaign_id = (int) self::parameter( 'campaign_id' );
        $audience = Audience::normalize( self::parameter( 'audience' ) );
        $list_id = self::listOf( $campaign_id ?: (int) self::parameter( 'source_id' ) );

        // What the rule or the filter finds by itself, to count the ones added by hand.
        $found = array();
        if ( $list_id && ( ! $audience || $audience['type'] === Audience::TYPE_CUSTOM ) ) {
            $recipients = MailingListRecipient::query()->select( 'name, phone' )->where( 'mailing_list_id', $list_id )->sortBy( 'name' )->fetchArray();
            $found = $recipients;
        } elseif ( $audience ) {
            $recipients = Audience::recipients( $audience );
            if ( $audience['type'] === Audience::TYPE_PRESET ) {
                $found = $audience['add'] ? Audience::recipients( array( 'add' => array() ) + $audience ) : $recipients;
            } else {
                $found = $recipients;
                $recipients = self::withPasted( $recipients, self::parameter( 'pasted', '' ) );
            }
        } else {
            $recipients = array();
        }

        // Added and removed in the dialog but not saved yet.
        $kept = Audience::adjust( $recipients, self::addedPeople(), self::removedPhones() );
        $found_phones = array();
        foreach ( $found as $recipient ) {
            $found_phones[ $recipient['phone'] ] = true;
        }
        $extra = 0;
        $longest = null;
        foreach ( $kept as $recipient ) {
            if ( ! isset( $found_phones[ $recipient['phone'] ] ) ) {
                ++$extra;
            }
            if ( $longest === null || strlen( $recipient['name'] ) > strlen( $longest['name'] ) ) {
                $longest = $recipient;
            }
        }

        wp_send_json_success( array(
            'count' => count( $kept ),
            'preview' => array_slice( $kept, 0, self::PREVIEW_SIZE ),
            'longest' => $longest,
            'extra' => $extra,
        ) );
    }

    /**
     * Data of a campaign for its form.
     */
    public static function getCampaignData()
    {
        $campaign = MailingCampaign::find( (int) self::parameter( 'campaign_id' ) );
        if ( ! $campaign ) {
            wp_send_json_error();
        }
        $audience = Audience::normalize( $campaign->getAudience() );
        if ( ! $audience ) {
            // A campaign made before audiences existed: its list, without a filter to apply again.
            $audience = array( 'type' => Audience::TYPE_CUSTOM, 'filter' => null );
        }

        // Once queued, the recipients are fixed; until then they are counted as of now.
        $results = self::results( $campaign->getId() );
        $queued = $results['queued'] + $results['sent'] + $results['failed'];
        if ( $queued ) {
            $recipients = $queued;
        } elseif ( $audience['type'] === Audience::TYPE_PRESET ) {
            $recipients = count( Audience::recipients( $audience ) );
        } else {
            $recipients = $campaign->getMailingListId() ? MailingListRecipient::query()->where( 'mailing_list_id', $campaign->getMailingListId() )->count() : 0;
        }

        wp_send_json_success( array(
            'id' => (int) $campaign->getId(),
            'name' => $campaign->getName(),
            'text' => $campaign->getText(),
            'state' => $campaign->getState(),
            'send_at' => $campaign->getSendAt(),
            'audience' => $audience,
            'recipients' => $recipients,
            'results' => $results,
        ) );
    }

    /**
     * Create or update a campaign. A custom audience is saved as a fixed list.
     */
    public static function saveCampaign()
    {
        $campaign = new MailingCampaign();
        $id = (int) self::parameter( 'id' );
        if ( $id && ! $campaign->load( $id ) ) {
            wp_send_json_error();
        }
        if ( $campaign->getId() && $campaign->getState() !== MailingCampaign::STATE_PENDING ) {
            // What has started sending is a record of what was sent.
            wp_send_json_error( array( 'message' => __( 'This campaign has already started and cannot be changed.', 'bookly-responsive-appointment-booking-tool' ) ) );
        }
        $audience = Audience::normalize( self::parameter( 'audience' ) );
        if ( ! $audience ) {
            wp_send_json_error();
        }

        $added = self::addedPeople();
        $removed = self::removedPhones();
        $old_list_id = $campaign->getMailingListId();
        if ( $audience['type'] === Audience::TYPE_PRESET ) {
            // The changes by hand join those already kept with the rule; the last one about a phone wins.
            $audience['add'] = Audience::adjust( $audience['add'], $added, $removed );
            $added_phones = array();
            foreach ( $added as $person ) {
                $added_phones[] = $person['phone'];
            }
            $audience['exclude'] = array_values( array_diff( array_unique( array_merge( $audience['exclude'], $removed ) ), $added_phones ) );
            $campaign->setMailingListId( null );
        } elseif ( ! ( $old_list_id || self::parameter( 'source_id' ) ) ) {
            $campaign->setMailingListId( self::createList(
                self::parameter( 'name' ),
                self::withPasted( Audience::recipients( $audience ), self::parameter( 'pasted', '' ) )
            ) );
        } elseif ( ! $old_list_id ) {
            // A duplicate gets its own copy of the source list.
            $campaign->setMailingListId( self::copyList( self::listOf( (int) self::parameter( 'source_id' ) ), self::parameter( 'name' ) ) );
        }
        if ( $audience['type'] === Audience::TYPE_CUSTOM && self::parameter( 'legacy' ) ) {
            // A list without a filter stays one.
            $audience = null;
        }

        $mode = self::parameter( 'mode' );
        $campaign
            ->setName( self::parameter( 'name' ) )
            ->setText( self::parameter( 'text' ) )
            ->setAudience( $audience ? json_encode( $audience ) : null )
            ->setState( MailingCampaign::STATE_PENDING )
            ->setSendAt( $mode === 'at' ? self::parameter( 'send_at' ) : ( $mode === 'now' ? current_time( 'mysql' ) : null ) )
            ->save();

        if ( $old_list_id && $old_list_id != $campaign->getMailingListId() ) {
            MailingList::releaseUnused( $old_list_id );
        }
        if ( ( $added || $removed ) && $campaign->getMailingListId() ) {
            if ( MailingCampaign::query()->where( 'mailing_list_id', $campaign->getMailingListId() )->count() > 1 ) {
                // A list shared with other campaigns is copied first, so that they keep theirs.
                $campaign->setMailingListId( self::copyList( $campaign->getMailingListId(), $campaign->getName() ) )->save();
            }
            $list_id = $campaign->getMailingListId();
            if ( $removed ) {
                MailingListRecipient::query()->delete()->where( 'mailing_list_id', $list_id )->whereIn( 'phone', $removed )->execute();
            }
            if ( $added ) {
                $present = MailingListRecipient::query()->where( 'mailing_list_id', $list_id )->fetchCol( 'phone' );
                foreach ( Audience::adjust( array(), $added, $present ) as $person ) {
                    $recipient = new MailingListRecipient();
                    $recipient->setMailingListId( $list_id )->setName( $person['name'] )->setPhone( $person['phone'] )->save();
                }
            }
        }
        if ( $mode === 'now' ) {
            Lib\Routines::mailing();
        }

        wp_send_json_success( array( 'id' => (int) $campaign->getId() ) );
    }

    public static function startCampaign()
    {
        $ids = self::parameter( 'ids' );
        if ( $ids ) {
            MailingCampaign::query()
                ->update()
                ->set( 'send_at', current_time( 'mysql' ) )
                ->whereIn( 'id', array_map( 'intval', (array) $ids ) )
                ->where( 'state', MailingCampaign::STATE_PENDING )
                ->execute();
        }

        Lib\Routines::mailing();

        wp_send_json_success();
    }

    /**
     * Stop a campaign: what is not sent yet will not be.
     */
    public static function cancelCampaign()
    {
        $campaign_id = (int) self::parameter( 'id' );

        MailingQueue::query()
            ->delete()
            ->where( 'campaign_id', $campaign_id )
            ->where( 'sent', MailingQueue::SENT_NO )
            ->execute();

        MailingCampaign::query()
            ->update()
            ->set( 'state', MailingCampaign::STATE_CANCELED )
            ->where( 'id', $campaign_id )
            ->execute();

        wp_send_json_success();
    }

    /**
     * Sent and failed messages of a campaign.
     *
     * @param int $campaign_id
     * @return array
     */
    protected static function results( $campaign_id )
    {
        $rows = MailingQueue::query()
            ->select( 'sent, COUNT(*) AS cnt' )
            ->where( 'campaign_id', $campaign_id )
            ->groupBy( 'sent' )
            ->fetchArray();
        $result = array( 'queued' => 0, 'sent' => 0, 'failed' => 0 );
        foreach ( $rows as $row ) {
            switch ( (int) $row['sent'] ) {
                case MailingQueue::SENT_NO:
                    $result['queued'] = (int) $row['cnt'];
                    break;
                case MailingQueue::SENT_YES:
                    $result['sent'] = (int) $row['cnt'];
                    break;
                case MailingQueue::SENT_FAILED:
                    $result['failed'] = (int) $row['cnt'];
                    break;
            }
        }

        return $result;
    }

    /**
     * @param int $campaign_id
     * @return int|null
     */
    protected static function listOf( $campaign_id )
    {
        $campaign = $campaign_id ? MailingCampaign::find( $campaign_id ) : null;

        return $campaign ? $campaign->getMailingListId() : null;
    }

    /**
     * @param string $name
     * @param array $recipients List of array( 'name' => ..., 'phone' => ... ).
     * @return int
     */
    protected static function createList( $name, array $recipients )
    {
        global $wpdb;

        $list = new MailingList();
        $list->setName( $name )->save();
        $now = current_time( 'mysql' );
        foreach ( array_chunk( $recipients, 500 ) as $chunk ) {
            $values = array();
            foreach ( $chunk as $recipient ) {
                $values[] = $wpdb->prepare( '(%d, %s, %s, %s)', $list->getId(), $recipient['name'], $recipient['phone'], $now );
            }
            $wpdb->query( 'INSERT INTO `' . MailingListRecipient::getTableName() . '` (mailing_list_id, name, phone, created_at) VALUES ' . implode( ',', $values ) );
        }

        return $list->getId();
    }

    /**
     * @param int $list_id
     * @param string $name
     * @return int Id of the copy.
     */
    protected static function copyList( $list_id, $name )
    {
        global $wpdb;

        $list = new MailingList();
        $list->setName( $name )->save();
        if ( $list_id ) {
            $wpdb->query( $wpdb->prepare(
                'INSERT INTO `' . MailingListRecipient::getTableName() . '` (mailing_list_id, name, phone, created_at)
                 SELECT %d, name, phone, %s FROM `' . MailingListRecipient::getTableName() . '` WHERE mailing_list_id = %d',
                $list->getId(), current_time( 'mysql' ), $list_id
            ) );
        }

        return $list->getId();
    }

    /**
     * Phones removed from a saved list in the dialog.
     *
     * @return string[]
     */
    protected static function removedPhones()
    {
        return Audience::phones( self::parameter( 'removed', array() ) );
    }

    /**
     * People added to the recipients in the dialog.
     *
     * @return array
     */
    protected static function addedPeople()
    {
        return Audience::people( self::parameter( 'added', array() ) );
    }

    /**
     * Add numbers typed by hand to the recipients, skipping the ones already there.
     *
     * @param array $recipients
     * @param string $pasted One number per line.
     * @return array
     */
    protected static function withPasted( array $recipients, $pasted )
    {
        $seen = array();
        foreach ( $recipients as $recipient ) {
            $seen[ $recipient['phone'] ] = true;
        }
        foreach ( preg_split( '/[\r\n,;]+/', (string) $pasted ) as $line ) {
            $phone = preg_replace( '/[^\d+]/', '', $line );
            if ( strlen( $phone ) >= 7 && ! isset( $seen[ $phone ] ) ) {
                $seen[ $phone ] = true;
                $recipients[] = array( 'name' => '', 'phone' => $phone );
            }
        }

        return $recipients;
    }
}
