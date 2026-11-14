<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\Format;
use Bookly\Lib\AiAdmin\State;
use Bookly\Lib\Entities\Notification;

/**
 * set_notifications_state — enable or disable notifications of one channel at once, as Enable /
 * Disable of the notifications table (assets/ai-setup/actions/set_notifications_state.yaml).
 */
class SetNotificationsState implements ActionInterface
{
    /**
     * @param array $args
     * @return string
     */
    protected static function gateway( array $args )
    {
        return isset( $args['gateway'] ) ? $args['gateway'] : 'email';
    }

    /**
     * @param array $args
     * @return int[]
     */
    protected static function ids( array $args )
    {
        return array_map( 'intval', $args['notification_ids'] );
    }

    /**
     * @inheritDoc
     */
    public function validate( array $args, State $state )
    {
        if ( ! Access::allowed( array( 'admin' ) ) ) {
            return array( 'forbidden: the current user is not a Bookly administrator' );
        }
        $errors = array();
        foreach ( array_keys( $args ) as $key ) {
            if ( ! in_array( $key, array( 'gateway', 'notification_ids', 'active' ), true ) ) {
                $errors[] = 'arguments: unknown argument ' . $key . ' — the action takes gateway, notification_ids, active';
            }
        }
        $gateway = self::gateway( $args );
        if ( ! in_array( $gateway, NotificationData::gateways(), true ) ) {
            $errors[] = 'arguments: gateway is email, sms, voice or whatsapp';
        }
        $ids = isset( $args['notification_ids'] ) ? $args['notification_ids'] : null;
        if ( ! is_array( $ids ) || ! $ids || count( array_filter( $ids, array( '\Bookly\Lib\AiAdmin\Actions\SetNotification', 'isId' ) ) ) !== count( $ids )
            || count( array_unique( array_map( 'intval', $ids ) ) ) !== count( $ids ) ) {
            $errors[] = 'arguments: notification_ids is a non-empty list of distinct ids from list_notifications';
        }
        if ( ! isset( $args['active'] ) || ! in_array( $args['active'], array( 0, 1, '0', '1' ), true ) ) {
            $errors[] = 'arguments: active is 1 (Enabled) or 0 (Disabled)';
        }
        if ( $errors ) {
            return $errors;
        }
        if ( $error = NotificationData::channelError( $gateway ) ) {
            return array( $error );
        }
        $active = (int) $args['active'];
        $all    = NotificationData::all( $state );
        $same   = array();
        foreach ( self::ids( $args ) as $id ) {
            if ( ! isset( $all[ $id ] ) ) {
                $errors[] = 'notification_not_found: ' . $id . ' — ids are in list_notifications (gateway ' . $gateway . ')';
            } elseif ( $all[ $id ]['gateway'] !== $gateway ) {
                $errors[] = 'notification_not_found: ' . $id . ' is a ' . $all[ $id ]['gateway'] . ' notification, not ' . $gateway . ' — notifications of two channels are two calls';
            } elseif ( ! NotificationData::listed( $all[ $id ] ) ) {
                $errors[] = 'notification_not_found: ' . $id . ' — its type is not offered for ' . $gateway . ' notifications on this site';
            } elseif ( $all[ $id ]['active'] === $active ) {
                $same[] = $id;
            } elseif ( $active && $gateway === 'whatsapp' ) {
                $status = NotificationData::templateStatus( $all[ $id ] );
                if ( $status !== 'APPROVED' ) {
                    $errors[] = 'whatsapp_template_not_approved: ' . $id . ' — its template ' . $all[ $id ]['settings']['whatsapp']['template']
                        . ' (' . $all[ $id ]['settings']['whatsapp']['language'] . ') is ' . ( $status === null ? 'not readable from Bookly Cloud' : $status )
                        . ' — Meta delivers only APPROVED templates; pick another template with set_notification';
                }
            }
        }
        if ( $same ) {
            $errors[] = 'already_in_state: ' . implode( ', ', $same ) . ' — already ' . ( $active ? 'Enabled' : 'Disabled' );
        }
        if ( ! $errors ) {
            foreach ( self::ids( $args ) as $id ) {
                $record = $all[ $id ];
                $record['active'] = $active;
                NotificationData::put( $state, $id, $record );
            }
        }

        return $errors;
    }

    /**
     * @inheritDoc
     */
    public function preview( array $args, State $state )
    {
        $gateway  = self::gateway( $args );
        $active   = (int) $args['active'];
        $link     = NotificationData::link( $gateway );
        $rows     = array();
        $warnings = array();
        $changes  = array();
        foreach ( self::ids( $args ) as $id ) {
            $all    = NotificationData::all( $state );
            $before = $all[ $id ];
            $after  = $before;
            $after['active'] = $active;
            NotificationData::put( $state, $id, $after );
            $changes[] = array( $before, $after );
            $object = Format::object( 'notification:' . $id, $before['name'] . ' (' . Notification::getTitle( $before['type'] ) . ')', '', false );
            $rows[] = array(
                'label' => Format::rowLabel( $object, __( 'State', 'bookly-responsive-appointment-booking-tool' ) ),
                'before' => CloudAccount::onOff( $before['active'] ),
                'after' => CloudAccount::onOff( $active ),
                'link' => $link,
            );
        }
        foreach ( $changes as $change ) {
            list( $before, $after ) = $change;
            $who = NotificationData::recipients( $after );
            if ( $active ) {
                if ( $who ) {
                    $warnings[] = sprintf( __( '%1$s starts sending to %2$s %3$s.', 'bookly-responsive-appointment-booking-tool' ), NotificationData::nameText( $after ), implode( ', ', $who ), NotificationData::whenText( $after ) );
                }
            } else {
                $warnings[] = sprintf( __( '%1$s stops sending: %2$s no longer receive it %3$s.', 'bookly-responsive-appointment-booking-tool' ), NotificationData::nameText( $after ), $who ? implode( ', ', $who ) : '—', NotificationData::whenText( $after ) );
            }
            $warnings = array_merge( $warnings, NotificationData::consequences( $before, $after, $state, false ) );
        }
        if ( ! $active ) {
            $warnings[] = __( 'Messages already sent or queued are not recalled: a disabled notification only stops later ones; the list of what a scheduled one has already sent stays.', 'bookly-responsive-appointment-booking-tool' );
        }

        return array( 'rows' => $rows, 'warnings' => $warnings );
    }

    /**
     * @inheritDoc
     */
    public function apply( array $args )
    {
        $ids    = self::ids( $args );
        $active = (int) $args['active'];
        $before = array();
        foreach ( Notification::query()->select( 'id, active' )->whereIn( 'id', $ids )->fetchArray() as $row ) {
            $before[ (int) $row['id'] ] = (int) $row['active'];
        }
        Notification::query()
            ->update()
            ->set( 'active', $active )
            ->whereIn( 'id', $ids )
            ->execute();

        return array( 'before' => $before, 'active' => $active );
    }

    /**
     * Return the previous state of the ids still in the state the action set.
     *
     * @inheritDoc
     */
    public function undo( array $snapshot )
    {
        foreach ( $snapshot['before'] as $id => $active ) {
            Notification::query()
                ->update()
                ->set( 'active', (int) $active )
                ->where( 'id', (int) $id )
                ->where( 'active', (int) $snapshot['active'] )
                ->execute();
        }
    }
}
