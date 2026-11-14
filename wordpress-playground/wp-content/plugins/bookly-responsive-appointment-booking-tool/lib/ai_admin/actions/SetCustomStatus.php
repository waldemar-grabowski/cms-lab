<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\Format;
use Bookly\Lib\AiAdmin\Services\ServiceAction;
use Bookly\Lib\AiAdmin\State;

/**
 * set_custom_status — create an own appointment status or change its Free/Busy (Custom Statuses
 * add-on, assets/ai-setup/actions/set_custom_status.yaml). The name of an existing status is not
 * changed: renaming rebuilds the slug and leaves the old one in options and filters.
 */
class SetCustomStatus extends ServiceAction implements ActionInterface
{
    /** Slugs of the statuses Bookly has itself: an own status must not take one. */
    const SYSTEM_SLUGS = 'pending,approved,cancelled,rejected,waitlisted,done,mixed';

    protected function name()
    {
        return 'set_custom_status';
    }

    /**
     * Own statuses after the earlier items: id|"$N" => array( id, slug, name, busy, position,
     * color, appointments ), in the order of the Order dialog.
     *
     * @param State $state
     * @return array
     */
    public static function statuses( State $state )
    {
        if ( $state->has( 'list:custom_statuses' ) ) {
            return $state->get( 'list:custom_statuses' );
        }
        $list = (array) Lib\Proxy\CustomStatuses::getStatusesList();
        $counts = array();
        if ( $list ) {
            $slugs = array();
            foreach ( $list as $row ) {
                $slugs[] = $row['slug'];
            }
            $rows = Lib\Entities\CustomerAppointment::query()
                ->select( 'status, COUNT(*) AS cnt' )
                ->whereIn( 'status', $slugs )
                ->groupBy( 'status' )
                ->fetchArray();
            foreach ( $rows as $row ) {
                $counts[ $row['status'] ] = (int) $row['cnt'];
            }
        }
        $statuses = array();
        foreach ( $list as $row ) {
            $row['appointments'] = isset( $counts[ $row['slug'] ] ) ? $counts[ $row['slug'] ] : 0;
            $statuses[ $row['id'] ] = $row;
        }

        return $statuses;
    }

    /**
     * Whether $id is an existing own status or "$N" of one created earlier.
     *
     * @param mixed $id
     * @param State $state
     * @return bool
     */
    public static function statusExists( $id, State $state )
    {
        $statuses = self::statuses( $state );

        return $state->isNew( $id ) === 'custom_status' || ( is_numeric( $id ) && isset( $statuses[ (int) $id ] ) );
    }

    /**
     * @param mixed $id
     * @return int|string int for an existing status, "$N" as is
     */
    public static function statusId( $id )
    {
        return is_string( $id ) && $id !== '' && $id[0] === '$' ? $id : (int) $id;
    }

    /**
     * @inheritDoc
     */
    public function validate( array $args, State $state )
    {
        if ( $error = $this->forbidden() ) {
            return array( $error );
        }
        if ( ! Lib\Config::customStatusesActive() ) {
            return array( 'addon_inactive: own statuses need Bookly Pro and the Custom Statuses add-on' );
        }
        foreach ( array_keys( $args ) as $key ) {
            if ( ! in_array( $key, array( 'status_id', 'name', 'busy' ), true ) ) {
                return array( 'arguments: unknown argument ' . $key . ' — the action takes status_id, name, busy' );
            }
        }
        $statuses = self::statuses( $state );
        $new = ! isset( $args['status_id'] );
        $errors = array();
        if ( ! $new ) {
            if ( ! self::statusExists( $args['status_id'], $state ) ) {
                return array( 'status_not_found: ' . wp_json_encode( $args['status_id'] ) . ' — ids are in list_custom_statuses' );
            }
            if ( array_key_exists( 'name', $args ) ) {
                $errors[] = 'name_on_existing: the name of an existing status is not changed by the assistant — the owner renames it in Settings → Custom Statuses';
            }
        } else {
            $name = isset( $args['name'] ) && is_string( $args['name'] ) ? trim( $args['name'] ) : '';
            if ( $name === '' ) {
                $errors[] = 'name_required: a new status needs a name';
            } elseif ( mb_strlen( $name ) > 255 ) {
                $errors[] = 'name_too_long: the name is at most 255 characters';
            } else {
                $base = sanitize_key( preg_replace( '/\s+/', '-', $name ) );
                if ( in_array( $base, explode( ',', self::SYSTEM_SLUGS ), true ) ) {
                    $errors[] = 'name_gives_system_slug: "' . $name . '" is the name of a status Bookly already has — use that one, or pick another name (e.g. "' . $name . ' by client")';
                }
                foreach ( $statuses as $status ) {
                    if ( mb_strtolower( trim( $status['name'] ) ) === mb_strtolower( $name ) ) {
                        $errors[] = 'name_duplicate: the own status "' . $status['name'] . '" ('
                            . ( is_int( $status['id'] ) ? 'id ' . $status['id'] : 'created by item ' . substr( $status['id'], 1 ) . ' of this proposal' )
                            . ') already has this name';
                        break;
                    }
                }
            }
        }
        if ( array_key_exists( 'busy', $args ) && ! in_array( $args['busy'], array( 0, 1, '0', '1' ), true ) ) {
            $errors[] = 'busy_value: busy is 1 (Busy) or 0 (Free)';
        } elseif ( ! $new && ! $errors ) {
            $id = self::statusId( $args['status_id'] );
            if ( ! array_key_exists( 'busy', $args ) || (int) $args['busy'] === (int) $statuses[ $id ]['busy'] ) {
                $errors[] = 'nothing_to_change: the status is already ' . ( (int) $statuses[ $id ]['busy'] ? 'Busy' : 'Free' );
            }
        }
        if ( ! $errors ) {
            $this->record( $args, $state );
        }

        return $errors;
    }

    /**
     * @param array $args
     * @param State $state
     */
    protected function record( array $args, State $state )
    {
        $statuses = self::statuses( $state );
        if ( isset( $args['status_id'] ) ) {
            $id = self::statusId( $args['status_id'] );
        } else {
            $id = self::newRef( $state );
            $state->created( 'custom_status' );
            $statuses[ $id ] = array( 'id' => $id, 'slug' => '', 'name' => trim( $args['name'] ), 'busy' => 1, 'position' => count( $statuses ), 'color' => null, 'appointments' => 0 );
        }
        if ( array_key_exists( 'busy', $args ) ) {
            $statuses[ $id ]['busy'] = (int) $args['busy'];
        }
        $state->set( 'list:custom_statuses', $statuses );
    }

    /**
     * @inheritDoc
     */
    public function preview( array $args, State $state )
    {
        $statuses = self::statuses( $state );
        $new = ! isset( $args['status_id'] );
        $status = $new ? null : $statuses[ self::statusId( $args['status_id'] ) ];
        $busy_rule = Access::rule( 'field:custom_status.busy' );
        $object = Format::object(
            'custom_status:' . ( $new ? self::newRef( $state ) : self::statusId( $args['status_id'] ) ),
            $new ? trim( $args['name'] ) : $status['name'],
            __( 'New status', 'bookly-responsive-appointment-booking-tool' ),
            $new
        );
        $busy = array_key_exists( 'busy', $args ) ? (int) $args['busy'] : ( $new ? 1 : (int) $status['busy'] );
        $rows = array();
        $warnings = array();
        if ( $new ) {
            $rule = Access::rule( 'field:custom_status.name' );
            $rows[] = array( 'label' => Format::rowLabel( $object, Format::label( $rule, 'name' ), array( 'name' => true ) ), 'before' => '—', 'after' => trim( $args['name'] ), 'link' => Format::link( $rule ) );
            $rows[] = array( 'label' => Format::rowLabel( $object, Format::label( $busy_rule, 'busy' ) ), 'before' => '—', 'after' => Format::value( $busy_rule, $busy ), 'link' => Format::link( $busy_rule ) );
            $warnings[] = __( 'Nothing uses this status yet — to give it to new bookings, use it in Appointments settings (default status, successful payment status, automatic change).', 'bookly-responsive-appointment-booking-tool' );
            $warnings[] = __( 'The new status goes last in the list and gets a random colour; the colour shows in the calendar when Coloring mode is By status (Settings → Calendar).', 'bookly-responsive-appointment-booking-tool' );
        } else {
            $rows[] = array(
                'label' => Format::rowLabel( $object, Format::label( $busy_rule, 'busy' ) ),
                'before' => Format::value( $busy_rule, (int) $status['busy'] ),
                'after' => Format::value( $busy_rule, $busy ),
                'link' => Format::link( $busy_rule ),
            );
            if ( $status['appointments'] > 0 ) {
                $warnings[] = $busy
                    ? sprintf( __( '%d appointments with this status start holding a place — some times may no longer be offered; existing overbookings stay.', 'bookly-responsive-appointment-booking-tool' ), $status['appointments'] )
                    : sprintf( __( '%d appointments with this status stop holding a place — those times become bookable again.', 'bookly-responsive-appointment-booking-tool' ), $status['appointments'] );
            }
        }
        $this->record( $args, $state );

        return array( 'rows' => $rows, 'warnings' => array_merge( $warnings, $this->actionWarnings( $state ) ) );
    }

    /**
     * @inheritDoc
     */
    public function apply( array $args )
    {
        $snapshot = array( 'created' => null, 'id' => null, 'before' => null, 'after' => null );
        $fields = array();
        if ( isset( $args['status_id'] ) ) {
            $fields['id'] = (int) $args['status_id'];
            foreach ( (array) Lib\Proxy\CustomStatuses::getStatusesList() as $row ) {
                if ( $row['id'] === $fields['id'] ) {
                    $snapshot['before'] = $row['busy'];
                }
            }
        } else {
            $fields['name'] = trim( $args['name'] );
        }
        if ( array_key_exists( 'busy', $args ) ) {
            $fields['busy'] = (int) $args['busy'];
        }
        $id = Lib\Proxy\CustomStatuses::saveStatus( $fields );
        $snapshot['id'] = $id;
        if ( ! isset( $args['status_id'] ) ) {
            $snapshot['created'] = $id;
        }
        foreach ( (array) Lib\Proxy\CustomStatuses::getStatusesList() as $row ) {
            if ( $row['id'] === $id ) {
                $snapshot['after'] = $row['busy'];
                $snapshot['slug'] = $row['slug'];
            }
        }
        $snapshot['created_id'] = $snapshot['created'];

        return $snapshot;
    }

    /**
     * @inheritDoc
     */
    public function undo( array $snapshot )
    {
        if ( ! Lib\Config::customStatusesActive() || ! $snapshot['id'] ) {
            return;
        }
        $current = null;
        foreach ( (array) Lib\Proxy\CustomStatuses::getStatusesList() as $row ) {
            if ( $row['id'] === $snapshot['id'] ) {
                $current = $row;
            }
        }
        if ( ! $current ) {
            return;
        }
        if ( $snapshot['created'] ) {
            // Delete the created status only while nothing refers to it: no appointment has it and
            // no option points at it.
            $used = Lib\Entities\CustomerAppointment::query()->where( 'status', $current['slug'] )->count();
            foreach ( array( 'bookly_appointment_default_status', 'bookly_successful_payment_appointment_status', 'bookly_auto_change_status_from', 'bookly_auto_change_status_to' ) as $option ) {
                if ( get_option( $option ) === $current['slug'] ) {
                    $used = true;
                }
            }
            if ( ! $used ) {
                Lib\Proxy\CustomStatuses::deleteStatus( $snapshot['created'] );
            }

            return;
        }
        // Restore Free/Busy only when it still has the value the assistant wrote.
        if ( $snapshot['before'] !== null && $current['busy'] === $snapshot['after'] ) {
            Lib\Proxy\CustomStatuses::saveStatus( array( 'id' => $snapshot['id'], 'busy' => $snapshot['before'] ) );
        }
    }
}
