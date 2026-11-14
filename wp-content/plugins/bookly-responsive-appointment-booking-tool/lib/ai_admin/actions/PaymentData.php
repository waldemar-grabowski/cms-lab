<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Lib;
use Bookly\Lib\Entities\Payment;

/**
 * A payment as its dialog shows it — the shape get_payment returns and the payment actions read
 * (complete_payment, set_payment_adjustment, remove_payment_adjustment).
 */
abstract class PaymentData
{
    /**
     * Money rounded as the columns keep it (DECIMAL(10,2)).
     *
     * @param mixed $value
     * @return float
     */
    public static function money( $value )
    {
        return round( (float) $value, 2 );
    }

    /**
     * Whether a number has at most 2 decimals.
     *
     * @param mixed $value
     * @return bool
     */
    public static function isMoney( $value )
    {
        return ( is_int( $value ) || is_float( $value ) ) && is_finite( $value ) && abs( $value * 100 - round( $value * 100 ) ) < 1e-6;
    }

    /**
     * @param Payment $payment
     * @return array adjustments as stored, re-indexed
     */
    public static function adjustments( Payment $payment )
    {
        $list = $payment->getDetailsData()->getValue( 'adjustments', array() );
        $adjustments = array();
        foreach ( is_array( $list ) ? array_values( $list ) : array() as $item ) {
            $adjustments[] = array(
                'reason' => isset( $item['reason'] ) ? (string) $item['reason'] : '',
                'amount' => self::money( isset( $item['amount'] ) ? $item['amount'] : 0 ),
                'tax'    => self::money( isset( $item['tax'] ) ? $item['tax'] : 0 ),
            );
        }

        return $adjustments;
    }

    /**
     * Tax mode the payment was taken with (its own snapshot, not the current option).
     *
     * @param Payment $payment
     * @return string included|excluded
     */
    public static function taxInPrice( Payment $payment )
    {
        $data = $payment->getDetailsData()->getData();

        return isset( $data['tax_in_price'] ) && $data['tax_in_price'] === 'excluded' ? 'excluded' : 'included';
    }

    /**
     * @param Payment $payment
     * @return float
     */
    public static function due( Payment $payment )
    {
        return max( 0, self::money( $payment->getTotal() - $payment->getPaid() - $payment->getChildPaid() ) );
    }

    /**
     * Whether the dialog would show Refund (as getPaymentDetails() decides).
     *
     * @param Payment $payment
     * @return bool
     */
    public static function refundable( Payment $payment )
    {
        if ( $payment->getStatus() !== Payment::STATUS_COMPLETED || ! $payment->getRefId() ) {
            return false;
        }
        if ( $payment->getType() === Payment::TYPE_CLOUD_STRIPE ) {
            return Lib\Cloud\API::getInstance()->account->productActive( Lib\Cloud\Account::PRODUCT_STRIPE );
        }

        return Lib\Payment\Proxy\Shared::getGatewayForRefund( null, $payment ) !== null;
    }

    /**
     * Whether the dialog shows Complete payment (Dialog.svelte:160-163 with PaymentControls.svelte:85).
     *
     * @param Payment $payment
     * @return bool
     */
    public static function canComplete( Payment $payment )
    {
        if ( $payment->getStatus() === Payment::STATUS_REFUNDED ) {
            return false;
        }
        $owed  = $payment->getTotal() > $payment->getPaid() + $payment->getChildPaid();
        $block = Lib\Config::proActive() || $owed || self::refundable( $payment );

        return $block && ( $owed || $payment->getStatus() !== Payment::STATUS_COMPLETED );
    }

    /**
     * Whether the dialog shows the Tax field of an adjustment.
     *
     * @param Payment $payment
     * @return bool
     */
    public static function showTaxField( Payment $payment )
    {
        return Lib\Config::taxesActive() || $payment->getTax() > 0;
    }

    /**
     * Customer of the payment as the Payments page names them.
     *
     * @param Payment $payment
     * @return string
     */
    public static function customer( Payment $payment )
    {
        $name = '';
        if ( $payment->getCustomerId() ) {
            $name = (string) Lib\Entities\Customer::query()->select( 'full_name' )->where( 'id', $payment->getCustomerId() )->fetchVar( 'full_name' );
        }
        if ( $name === '' ) {
            $name = (string) Lib\Entities\CustomerAppointment::query( 'ca' )
                ->select( 'c.full_name' )
                ->leftJoin( 'Customer', 'c', 'c.id = ca.customer_id' )
                ->where( 'ca.payment_id', $payment->getId() )
                ->fetchVar( 'full_name' );
        }

        return $name;
    }

    /**
     * Appointments paid by the payment.
     *
     * @param Payment $payment
     * @return array
     */
    public static function appointments( Payment $payment )
    {
        $rows = Lib\Entities\CustomerAppointment::query( 'ca' )
            ->select( 'ca.id AS customer_appointment_id, ca.appointment_id, ca.status, a.start_date, s.title AS service' )
            ->leftJoin( 'Appointment', 'a', 'a.id = ca.appointment_id' )
            ->leftJoin( 'Service', 's', 's.id = COALESCE(ca.compound_service_id, ca.collaborative_service_id, a.service_id)' )
            ->where( 'ca.payment_id', $payment->getId() )
            ->sortBy( 'a.start_date' )
            ->fetchArray();
        $list = array();
        foreach ( $rows as $row ) {
            $list[] = array(
                'appointment_id'          => (int) $row['appointment_id'],
                'customer_appointment_id' => (int) $row['customer_appointment_id'],
                'service'                 => (string) $row['service'],
                'start'                   => $row['start_date'] ? substr( $row['start_date'], 0, 16 ) : null,
                'status'                  => $row['status'],
            );
        }

        return $list;
    }

    /**
     * What the order holds that a manual completion does not issue (get_payment.order_has).
     *
     * @param Payment $payment
     * @return array
     */
    public static function orderHas( Payment $payment )
    {
        $data = $payment->getDetailsData()->getData();
        $has  = array( 'coupon' => (bool) $payment->getCouponId(), 'gift_card_purchase' => false, 'event_ticket' => false, 'package' => false );
        foreach ( isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array() as $item ) {
            $type = isset( $item['type'] ) ? $item['type'] : '';
            if ( $type === Payment::ITEM_GIFT_CARD ) {
                $has['gift_card_purchase'] = true;
            } elseif ( $type === Payment::ITEM_EVENT_ATTENDEE ) {
                $has['event_ticket'] = true;
            } elseif ( $type === Payment::ITEM_PACKAGE ) {
                $has['package'] = true;
            }
        }

        return $has;
    }

    /**
     * The whole payment for the model.
     *
     * @param Payment $payment
     * @return array
     */
    public static function data( Payment $payment )
    {
        $adjustments = array();
        foreach ( self::adjustments( $payment ) as $index => $item ) {
            $adjustments[] = array( 'index' => $index ) + $item;
        }

        return array(
            'id'             => (int) $payment->getId(),
            'created_at'     => substr( (string) $payment->getCreatedAt(), 0, 16 ),
            'type'           => $payment->getType(),
            'status'         => $payment->getStatus(),
            'customer'       => self::customer( $payment ),
            'paid_type'      => $payment->getPaidType(),
            'total'          => self::money( $payment->getTotal() ),
            'tax'            => self::money( $payment->getTax() ),
            'tax_in_price'   => self::taxInPrice( $payment ),
            'paid'           => self::money( $payment->getPaid() ),
            'child_paid'     => self::money( $payment->getChildPaid() ),
            'due'            => self::due( $payment ),
            'parent_id'      => $payment->getParentId() ? (int) $payment->getParentId() : null,
            'refundable'     => self::refundable( $payment ),
            'can_complete'   => self::canComplete( $payment ),
            'show_tax_field' => self::showTaxField( $payment ),
            'pro'            => (bool) Lib\Config::proActive(),
            'adjustments'    => $adjustments,
            'appointments'   => self::appointments( $payment ),
            'order_has'      => self::orderHas( $payment ),
        );
    }

    /**
     * The payment's numbers after the earlier items of the proposal (from Payment when none).
     *
     * @param Payment $payment
     * @param \Bookly\Lib\AiAdmin\State $state
     * @return array array( status, total, tax, paid, child_paid, adjustments )
     */
    public static function state( Payment $payment, $state )
    {
        $ref = 'payment:' . $payment->getId();
        if ( ! $state->has( $ref ) ) {
            $state->set( $ref, self::snapshot( $payment ) );
        }

        return $state->get( $ref );
    }

    /**
     * @param Payment $payment
     * @return array
     */
    public static function snapshot( Payment $payment )
    {
        return array(
            'status'      => $payment->getStatus(),
            'total'       => self::money( $payment->getTotal() ),
            'tax'         => self::money( $payment->getTax() ),
            'paid'        => self::money( $payment->getPaid() ),
            'child_paid'  => self::money( $payment->getChildPaid() ),
            'adjustments' => self::adjustments( $payment ),
        );
    }

    /**
     * Total and tax with an adjustment taken out (-1) or put in (+1), as the dialog counts them.
     *
     * @param array  $numbers total, tax
     * @param array  $adjustment amount, tax
     * @param int    $sign
     * @param string $tax_in_price
     * @return array
     */
    public static function shift( array $numbers, array $adjustment, $sign, $tax_in_price )
    {
        $numbers['total'] = self::money( $numbers['total'] + $sign * ( $adjustment['amount'] + ( $tax_in_price === 'excluded' ? $adjustment['tax'] : 0 ) ) );
        $numbers['tax']   = self::money( $numbers['tax'] + $sign * $adjustment['tax'] );

        return $numbers;
    }

    /**
     * Common checks of the payment actions: rights, existence, main payment.
     *
     * @param mixed $payment_id
     * @return array array( Payment|null, string[] errors )
     */
    public static function find( $payment_id )
    {
        if ( ! \Bookly\Lib\AiAdmin\Access::allowed( array( 'admin' ) ) ) {
            return array( null, array( 'forbidden: only a Bookly administrator can change payments' ) );
        }
        if ( ! is_int( $payment_id ) && ! ( is_string( $payment_id ) && ctype_digit( $payment_id ) ) ) {
            return array( null, array( 'payment_not_found: payment_id is a number from find_payments' ) );
        }
        $payment = Payment::find( (int) $payment_id );
        if ( ! $payment ) {
            return array( null, array( 'payment_not_found: there is no payment ' . $payment_id . ' — ids are in find_payments' ) );
        }
        if ( $payment->getParentId() ) {
            return array( null, array( 'child_payment: payment ' . $payment_id . ' is an additional payment made by a link to payment ' . $payment->getParentId() . '; work with the main payment' ) );
        }

        return array( $payment, array() );
    }

    /**
     * Payment as the card shows it in a row label.
     *
     * @param Payment $payment
     * @return string
     */
    public static function title( Payment $payment )
    {
        $customer = self::customer( $payment );

        return sprintf( __( 'Payment #%d', 'bookly-responsive-appointment-booking-tool' ), $payment->getId() )
            . ( $customer !== '' ? ' · ' . $customer : '' )
            . ' · ' . Payment::typeToString( $payment->getType() );
    }

    /**
     * Amounts as the card shows them.
     *
     * @param float $total
     * @param float $paid
     * @param float $child_paid
     * @return string
     */
    public static function paidText( $total, $paid, $child_paid )
    {
        $text = sprintf( __( '%s of %s', 'bookly-responsive-appointment-booking-tool' ), Lib\Utils\Price::format( $paid + $child_paid ), Lib\Utils\Price::format( $total ) );
        $due  = max( 0, round( $total - $paid - $child_paid, 2 ) );
        if ( $due > 0 ) {
            $text .= ', ' . sprintf( __( 'due %s', 'bookly-responsive-appointment-booking-tool' ), Lib\Utils\Price::format( $due ) );
        }

        return $text;
    }
}
