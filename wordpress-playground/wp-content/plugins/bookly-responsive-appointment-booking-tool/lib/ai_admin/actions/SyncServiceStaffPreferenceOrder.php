<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\Format;
use Bookly\Lib\AiAdmin\Services\ServiceAction;
use Bookly\Lib\AiAdmin\Services\ServiceData;
use Bookly\Lib\AiAdmin\State;

/**
 * sync_service_staff_preference_order — order in which Providers of a simple service get a booking
 * for "Any" under the "Specified order" rule (Pro,
 * assets/ai-setup/actions/sync_service_staff_preference_order.yaml).
 */
class SyncServiceStaffPreferenceOrder extends ServiceAction implements ActionInterface
{
    protected function name()
    {
        return 'sync_service_staff_preference_order';
    }

    /**
     * @inheritDoc
     */
    public function validate( array $args, State $state )
    {
        if ( $error = $this->forbidden() ) {
            return array( $error );
        }
        $id = isset( $args['service_id'] ) ? self::id( $args['service_id'] ) : 0;
        if ( ! self::exists( $id, $state ) ) {
            return array( 'service_not_found: ' . wp_json_encode( isset( $args['service_id'] ) ? $args['service_id'] : null ) );
        }
        if ( ! Lib\Config::proActive() ) {
            return array( 'addon_inactive: the order of Providers needs the pro add-on' );
        }
        $rule = Access::rule( 'field:service.staff_preference_order' );
        if ( ! $rule || empty( $rule['edit'] ) || ! Access::allowed( $rule['edit'] ) ) {
            return array( 'forbidden: the order of Providers cannot be changed by the assistant' );
        }
        if ( self::current( $state, $id, 'type' ) !== Lib\Entities\Service::TYPE_SIMPLE ) {
            return array( 'not_simple: only a simple service has the "Specified order" rule' );
        }
        if ( self::current( $state, $id, 'staff_preference' ) !== Lib\Entities\Service::PREFERRED_ORDER ) {
            return array( 'rule_not_order: set staff_preference to "order" first (set_service)' );
        }
        $ids = isset( $args['staff_ids'] ) && is_array( $args['staff_ids'] ) ? array_map( 'intval', $args['staff_ids'] ) : array();
        $providers = self::current( $state, $id, 'staff_ids' );
        if ( ! $ids || count( $ids ) !== count( array_unique( $ids ) ) || array_diff( $ids, $providers ) || array_diff( $providers, $ids ) ) {
            return array( 'not_all_providers: give every Provider of the service exactly once: ' . implode( ', ', $providers ) );
        }
        self::setCurrent( $state, $id, 'preference_order', $ids );

        return array();
    }

    /**
     * @inheritDoc
     */
    public function preview( array $args, State $state )
    {
        $id = self::id( $args['service_id'] );
        $before = self::current( $state, $id, 'preference_order' );
        $after = array_map( 'intval', $args['staff_ids'] );
        $rule = Access::rule( 'field:service.staff_preference_order' );
        $rows = array( array(
            'label' => self::rowLabel( $state, $id, Format::label( $rule, 'staff_preference_order' ) ),
            'before' => $this->numbered( $before ),
            'after' => $this->numbered( $after ),
            'link' => Format::link( $rule ),
        ) );
        self::setCurrent( $state, $id, 'preference_order', $after );

        return array( 'rows' => $rows, 'warnings' => array() );
    }

    /**
     * @param int[] $ids
     * @return string
     */
    protected function numbered( array $ids )
    {
        $names = array();
        foreach ( array_values( $ids ) as $i => $id ) {
            $names[] = ( $i + 1 ) . '. ' . self::staffNames( array( $id ) );
        }

        return implode( ', ', $names );
    }

    /**
     * @inheritDoc
     */
    public function apply( array $args )
    {
        $service_id = (int) $args['service_id'];
        $old = (array) Lib\Proxy\Pro::getServiceStaffPreferenceOrders( $service_id );
        // The list of the dialog: the given Providers, then the other non-archived staff in their current order.
        $staff = ServiceData::staff();
        $others = array();
        foreach ( array_keys( $old ) as $staff_id ) {
            if ( isset( $staff[ $staff_id ] ) && $staff[ $staff_id ]['visibility'] !== 'archive' ) {
                $others[] = $staff_id;
            }
        }
        foreach ( $staff as $staff_id => $row ) {
            if ( $row['visibility'] !== 'archive' && ! in_array( $staff_id, $others, true ) ) {
                $others[] = $staff_id;
            }
        }
        $list = array_map( 'intval', $args['staff_ids'] );
        foreach ( $others as $staff_id ) {
            if ( ! in_array( $staff_id, $list, true ) ) {
                $list[] = $staff_id;
            }
        }
        $positions = array_flip( $list );
        Lib\Proxy\Pro::setServiceStaffPreferenceOrders( $service_id, $positions, array() );

        return array( 'service_id' => $service_id, 'positions' => $old, 'created' => array_values( array_diff( $list, array_keys( $old ) ) ) );
    }

    /**
     * @inheritDoc
     */
    public function undo( array $snapshot )
    {
        Lib\Proxy\Pro::setServiceStaffPreferenceOrders( $snapshot['service_id'], $snapshot['positions'], $snapshot['created'] );
    }
}
