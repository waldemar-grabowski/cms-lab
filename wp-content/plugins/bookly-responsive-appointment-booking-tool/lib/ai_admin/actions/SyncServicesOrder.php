<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Services\ServiceAction;
use Bookly\Lib\AiAdmin\Services\ServiceData;
use Bookly\Lib\AiAdmin\State;

/**
 * sync_services_order — order of services in the booking form and lists, the full list of the
 * Order dialog (assets/ai-setup/actions/sync_services_order.yaml).
 */
class SyncServicesOrder extends ServiceAction implements ActionInterface
{
    protected function name()
    {
        return 'sync_services_order';
    }

    /**
     * Services of the Order dialog after the earlier items, in their current order.
     *
     * @param State $state
     * @return array of int|string
     */
    protected function currentOrder( State $state )
    {
        if ( $state->has( 'order:services' ) ) {
            return $state->get( 'order:services' );
        }
        $order = ServiceData::orderableIds();
        for ( $item = 1; $item < self::item( $state ); $item++ ) {
            if ( $state->isNew( '$' . $item ) === 'service' ) {
                $order[] = '$' . $item;
            }
        }

        return $order;
    }

    /**
     * @inheritDoc
     */
    public function validate( array $args, State $state )
    {
        if ( $error = $this->forbidden() ) {
            return array( $error );
        }
        $ids = isset( $args['service_ids'] ) && is_array( $args['service_ids'] ) ? array_map( array( __CLASS__, 'id' ), $args['service_ids'] ) : array();
        $errors = array();
        foreach ( $ids as $id ) {
            if ( ! self::exists( $id, $state ) ) {
                $errors[] = 'service_not_found: ' . wp_json_encode( $id );
            }
        }
        if ( $errors ) {
            return $errors;
        }
        $expected = $this->currentOrder( $state );
        $missing = array_diff( $expected, $ids );
        $extra = array_diff( $ids, $expected );
        if ( $missing || $extra || count( $ids ) !== count( array_unique( $ids ) ) ) {
            return array( 'not_full_list: give every service of list_services exactly once'
                . ( $missing ? '; missing ' . implode( ', ', $missing ) : '' )
                . ( $extra ? '; not in the Order dialog ' . implode( ', ', $extra ) : '' ) );
        }
        $state->set( 'order:services', $ids );

        return array();
    }

    /**
     * @inheritDoc
     */
    public function preview( array $args, State $state )
    {
        $before = $this->currentOrder( $state );
        $after = array_map( array( __CLASS__, 'id' ), $args['service_ids'] );
        $rows = array();
        foreach ( $after as $position => $id ) {
            $old = array_search( $id, $before, true );
            if ( $old !== $position ) {
                $rows[] = array(
                    'label' => self::title( $state, $id ),
                    'before' => '#' . ( $old + 1 ),
                    'after' => '#' . ( $position + 1 ),
                    'link' => add_query_arg( array( 'page' => 'bookly-services' ), admin_url( 'admin.php' ) ),
                );
            }
        }
        $state->set( 'order:services', $after );

        return array( 'rows' => $rows, 'warnings' => array() );
    }

    /**
     * @inheritDoc
     */
    public function apply( array $args )
    {
        $snapshot = array();
        foreach ( array_values( $args['service_ids'] ) as $position => $service_id ) {
            $snapshot[ (int) $service_id ] = (int) Lib\Entities\Service::query()->where( 'id', $service_id )->fetchVar( 'position' );
            Lib\Entities\Service::query()
                ->update()
                ->set( 'position', $position )
                ->where( 'id', $service_id )
                ->whereNot( 'position', $position )
                ->execute();
        }

        return $snapshot;
    }

    /**
     * @inheritDoc
     */
    public function undo( array $snapshot )
    {
        foreach ( $snapshot as $service_id => $position ) {
            Lib\Entities\Service::query()->update()->set( 'position', $position )->where( 'id', $service_id )->execute();
        }
    }
}
