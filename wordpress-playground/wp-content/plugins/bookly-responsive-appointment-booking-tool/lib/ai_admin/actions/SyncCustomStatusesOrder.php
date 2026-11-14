<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\Format;
use Bookly\Lib\AiAdmin\Services\ServiceAction;
use Bookly\Lib\AiAdmin\State;

/**
 * sync_custom_statuses_order — the order of own statuses in status lists (Settings → Custom
 * Statuses → Order…) (assets/ai-setup/actions/sync_custom_statuses_order.yaml).
 */
class SyncCustomStatusesOrder extends ServiceAction implements ActionInterface
{
    protected function name()
    {
        return 'sync_custom_statuses_order';
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
        $ids = isset( $args['status_ids'] ) && is_array( $args['status_ids'] ) ? array_values( $args['status_ids'] ) : array();
        if ( ! $ids ) {
            return array( 'not_full_list: status_ids is every own status of list_custom_statuses in the new order' );
        }
        $errors = array();
        foreach ( $ids as $id ) {
            if ( ! SetCustomStatus::statusExists( $id, $state ) ) {
                $errors[] = 'status_not_found: ' . wp_json_encode( $id );
            }
        }
        $given = array_map( 'strval', $ids );
        $all = array_map( 'strval', array_keys( SetCustomStatus::statuses( $state ) ) );
        if ( ! $errors && ( count( array_unique( $given ) ) !== count( $given ) || array_diff( $all, $given ) || array_diff( $given, $all ) ) ) {
            $missing = array_diff( $all, $given );
            $errors[] = 'not_full_list: status_ids is every own status exactly once' . ( $missing ? ' — missing ' . implode( ', ', $missing ) : '' );
        }
        if ( ! $errors && $given === $all ) {
            $errors[] = 'nothing_to_change: this is the current order';
        }
        if ( ! $errors ) {
            $this->record( $ids, $state );
        }

        return $errors;
    }

    /**
     * The statuses of the state in the new order.
     *
     * @param array $ids
     * @param State $state
     */
    protected function record( array $ids, State $state )
    {
        $statuses = SetCustomStatus::statuses( $state );
        $ordered = array();
        foreach ( $ids as $position => $id ) {
            $id = SetCustomStatus::statusId( $id );
            $ordered[ $id ] = array_merge( $statuses[ $id ], array( 'position' => $position ) );
        }
        $state->set( 'list:custom_statuses', $ordered );
    }

    /**
     * @inheritDoc
     */
    public function preview( array $args, State $state )
    {
        $statuses = SetCustomStatus::statuses( $state );
        $after = array_values( $args['status_ids'] );
        $rule = Access::rule( 'field:custom_status.position' );
        $this->record( $after, $state );

        return array(
            'rows' => array( array(
                'label' => Format::label( $rule, 'Order' ),
                'before' => self::text( array_keys( $statuses ), $statuses ),
                'after' => self::text( $after, $statuses ),
                'link' => Format::link( $rule ),
            ) ),
            'warnings' => $this->actionWarnings( $state ),
        );
    }

    /**
     * @inheritDoc
     */
    public function apply( array $args )
    {
        $snapshot = array( 'order' => array() );
        foreach ( (array) Lib\Proxy\CustomStatuses::getStatusesList() as $row ) {
            $snapshot['order'][] = $row['id'];
        }
        Lib\Proxy\CustomStatuses::setStatusesPosition( array_map( 'intval', array_values( $args['status_ids'] ) ) );

        return $snapshot;
    }

    /**
     * @inheritDoc
     */
    public function undo( array $snapshot )
    {
        if ( $snapshot['order'] && Lib\Config::customStatusesActive() ) {
            Lib\Proxy\CustomStatuses::setStatusesPosition( $snapshot['order'] );
        }
    }

    /**
     * Numbered names.
     *
     * @param array $ids
     * @param array $statuses
     * @return string
     */
    protected static function text( array $ids, array $statuses )
    {
        $names = array();
        foreach ( array_values( $ids ) as $i => $id ) {
            $id = SetCustomStatus::statusId( $id );
            $names[] = ( $i + 1 ) . '. ' . ( isset( $statuses[ $id ] ) ? $statuses[ $id ]['name'] : '#' . $id );
        }

        return implode( ', ', $names );
    }
}
