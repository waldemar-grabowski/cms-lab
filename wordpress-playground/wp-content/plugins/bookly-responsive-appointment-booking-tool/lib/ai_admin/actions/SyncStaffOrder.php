<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\Format;
use Bookly\Lib\AiAdmin\StaffData;
use Bookly\Lib\AiAdmin\State;

/**
 * sync_staff_order — the order of staff members in the booking forms and admin lists
 * (Staff Members → Order…) (assets/ai-setup/actions/sync_staff_order.yaml).
 */
class SyncStaffOrder implements ActionInterface
{
    /**
     * @inheritDoc
     */
    public function validate( array $args, State $state )
    {
        if ( $error = StaffData::forbidden( 'field:staff.position' ) ) {
            return array( $error );
        }
        $ids = isset( $args['staff_ids'] ) && is_array( $args['staff_ids'] ) ? array_values( $args['staff_ids'] ) : array();
        if ( ! $ids ) {
            return array( 'staff_ids: every staff member of list_staff in the new order' );
        }
        $errors  = array();
        $current = StaffData::order( $state );
        foreach ( $ids as $id ) {
            if ( ! StaffData::exists( $id, $state ) ) {
                $errors[] = 'staff_not_found: ' . wp_json_encode( $id );
            }
        }
        $given = array_map( 'strval', $ids );
        $all   = array_map( 'strval', $current );
        if ( ! $errors && ( count( array_unique( $given ) ) !== count( $given ) || array_diff( $all, $given ) || array_diff( $given, $all ) ) ) {
            $missing  = array_diff( $all, $given );
            $errors[] = 'not_full_list: staff_ids is every staff member exactly once' . ( $missing ? ' — missing ' . implode( ', ', $missing ) : '' );
        }
        if ( ! $errors && $given === $all ) {
            $errors[] = 'nothing_to_change: this is the current order';
        }
        $state->set( 'staff:order', $ids );

        return $errors;
    }

    /**
     * @inheritDoc
     */
    public function preview( array $args, State $state )
    {
        $before = StaffData::order( $state );
        $after  = array_values( $args['staff_ids'] );
        $rule   = Access::rule( 'field:staff.position' );
        $state->set( 'staff:order', $after );

        return array(
            'rows'     => array( array(
                'label'  => Format::label( $rule, 'Order' ),
                'before' => self::text( $before, $state ),
                'after'  => self::text( $after, $state ),
                'link'   => Format::link( $rule ),
            ) ),
            'warnings' => array( __( 'The AI chat lists staff members by name and does not follow this order.', 'bookly-responsive-appointment-booking-tool' ) ),
        );
    }

    /**
     * @inheritDoc
     */
    public function apply( array $args )
    {
        $snapshot = array( 'positions' => array() );
        foreach ( Lib\Entities\Staff::query()->select( 'id, position' )->fetchArray() as $row ) {
            $snapshot['positions'][ $row['id'] ] = $row['position'];
        }
        // As the "Staff members order" dialog saves it: position = index in the list.
        foreach ( array_values( $args['staff_ids'] ) as $position => $staff_id ) {
            if ( isset( $snapshot['positions'][ $staff_id ] ) && (int) $snapshot['positions'][ $staff_id ] !== $position ) {
                Lib\Entities\Staff::query()->update()->set( 'position', $position )->where( 'id', $staff_id )->execute();
            }
        }

        return $snapshot;
    }

    /**
     * @inheritDoc
     */
    public function undo( array $snapshot )
    {
        if ( empty( $snapshot['positions'] ) ) {
            return;
        }
        foreach ( $snapshot['positions'] as $staff_id => $position ) {
            Lib\Entities\Staff::query()->update()->set( 'position', $position )->where( 'id', $staff_id )->execute();
        }
    }

    /**
     * Numbered names, archived ones marked.
     *
     * @param array $ids
     * @param State $state
     * @return string
     */
    protected static function text( array $ids, State $state )
    {
        $names = array();
        foreach ( $ids as $i => $id ) {
            $profile = StaffData::profile( $id, $state );
            $names[] = ( $i + 1 ) . '. ' . $profile['full_name'] . ( $profile['visibility'] === 'archive' ? ' (' . __( 'Archived', 'bookly-responsive-appointment-booking-tool' ) . ')' : '' );
        }

        return implode( ', ', $names );
    }
}
