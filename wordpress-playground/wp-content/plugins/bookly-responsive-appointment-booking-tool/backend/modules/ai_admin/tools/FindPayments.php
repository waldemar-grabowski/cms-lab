<?php
namespace Bookly\Backend\Modules\AiAdmin\Tools;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Actions\PaymentData;
use Bookly\Lib\Entities\Payment;

/**
 * find_payments(filters) — main payments as the Payments page lists them, newest first, with the
 * page's filters and search.
 */
class FindPayments extends Tool
{
    const NAME = 'find_payments';
    const MAX  = 50;

    public function getName()
    {
        return self::NAME;
    }

    protected function description()
    {
        return 'Payments as the Payments page lists them, newest first, with its filters.';
    }

    protected function parameters()
    {
        $statuses = array( Payment::STATUS_COMPLETED, Payment::STATUS_PENDING, Payment::STATUS_REJECTED, Payment::STATUS_REFUNDED );

        return array(
            'type'       => 'object',
            'properties' => array(
                'search'       => array( 'type' => 'string' ),
                'customer_id'  => array( 'type' => 'integer' ),
                'staff_id'     => array( 'type' => 'integer' ),
                'service_id'   => array( 'type' => 'integer' ),
                'status'       => array( 'type' => 'array', 'items' => array( 'type' => 'string', 'enum' => $statuses ) ),
                'type'         => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
                'created_from' => array( 'type' => 'string' ),
                'created_to'   => array( 'type' => 'string' ),
                'limit'        => array( 'type' => 'integer' ),
            ),
        );
    }

    public function execute( array $arguments )
    {
        if ( ! Lib\AiAdmin\Access::allowed( array( 'admin' ) ) ) {
            return self::json( array( 'error' => 'only a Bookly administrator can read payments' ) );
        }
        $query = Payment::query( 'p' )
            ->select( 'p.id' )
            ->leftJoin( 'CustomerAppointment', 'ca', 'ca.payment_id = p.id' )
            ->leftJoin( 'Customer', 'c', 'c.id = ca.customer_id' )
            ->leftJoin( 'Appointment', 'a', 'a.id = ca.appointment_id' )
            ->leftJoin( 'Service', 's', 's.id = COALESCE(ca.compound_service_id, ca.collaborative_service_id, a.service_id)' )
            ->leftJoin( 'Staff', 'st', 'st.id = a.staff_id' )
            ->leftJoin( 'Customer', 'pc', 'pc.id = p.customer_id' )
            ->where( 'p.parent_id', null )
            ->groupBy( 'p.id' );

        if ( ! empty( $arguments['search'] ) && is_string( $arguments['search'] ) ) {
            $like = '%' . $arguments['search'] . '%';
            $query->whereRaw(
                'p.id LIKE %s OR pc.full_name LIKE %s OR pc.email LIKE %s OR pc.phone LIKE %s OR c.full_name LIKE %s OR st.full_name LIKE %s OR s.title LIKE %s OR p.details LIKE %s OR p.total LIKE %s OR (p.paid + p.child_paid) LIKE %s',
                array( $like, $like, $like, $like, $like, $like, $like, $like, $like, $like )
            );
        }
        if ( ! empty( $arguments['customer_id'] ) ) {
            $query->where( 'p.customer_id', (int) $arguments['customer_id'] );
        }
        if ( ! empty( $arguments['staff_id'] ) ) {
            $query->where( 'st.id', (int) $arguments['staff_id'] );
        }
        if ( ! empty( $arguments['service_id'] ) ) {
            $query->where( 's.id', (int) $arguments['service_id'] );
        }
        if ( ! empty( $arguments['status'] ) && is_array( $arguments['status'] ) ) {
            $query->whereIn( 'p.status', array_values( $arguments['status'] ) );
        }
        if ( ! empty( $arguments['type'] ) && is_array( $arguments['type'] ) ) {
            $query->whereIn( 'p.type', array_values( $arguments['type'] ) );
        }
        foreach ( array( 'created_from' => '>=', 'created_to' => '<' ) as $key => $op ) {
            if ( ! empty( $arguments[ $key ] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $arguments[ $key ] ) ) {
                $date = $key === 'created_to' ? date( 'Y-m-d', strtotime( '+1 day', strtotime( $arguments[ $key ] ) ) ) : $arguments[ $key ];
                $query->whereRaw( 'p.created_at ' . $op . ' %s', array( $date ) );
            }
        }

        $ids   = $query->sortBy( 'p.created_at' )->order( 'DESC' )->fetchCol( 'p.id' );
        $limit = isset( $arguments['limit'] ) ? max( 1, min( self::MAX, (int) $arguments['limit'] ) ) : 20;
        $rows  = array();
        foreach ( array_slice( $ids, 0, $limit ) as $id ) {
            $payment = Payment::find( $id );
            if ( $payment ) {
                $rows[] = array(
                    'id'         => (int) $payment->getId(),
                    'created_at' => substr( (string) $payment->getCreatedAt(), 0, 16 ),
                    'type'       => $payment->getType(),
                    'customer'   => PaymentData::customer( $payment ),
                    'status'     => $payment->getStatus(),
                    'total'      => PaymentData::money( $payment->getTotal() ),
                    'paid'       => PaymentData::money( $payment->getPaid() ),
                    'child_paid' => PaymentData::money( $payment->getChildPaid() ),
                    'due'        => PaymentData::due( $payment ),
                );
            }
        }

        return self::json( array( 'total_found' => count( $ids ), 'returned' => count( $rows ), 'payments' => $rows ) );
    }
}
