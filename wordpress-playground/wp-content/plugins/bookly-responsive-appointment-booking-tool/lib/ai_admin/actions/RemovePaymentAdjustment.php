<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\State;
use Bookly\Lib\Entities\Payment;

/**
 * remove_payment_adjustment — delete a manual adjustment of a payment by its index, as the trash
 * button of the adjustment row does (assets/ai-setup/actions/remove_payment_adjustment.yaml).
 */
class RemovePaymentAdjustment implements ActionInterface
{
    /**
     * @param Payment $payment
     * @param int     $index
     * @param array   $numbers
     * @return array
     */
    protected static function after( Payment $payment, $index, array $numbers )
    {
        $after = PaymentData::shift( $numbers, $numbers['adjustments'][ $index ], -1, PaymentData::taxInPrice( $payment ) );
        array_splice( $after['adjustments'], $index, 1 );

        return $after;
    }

    /**
     * @inheritDoc
     */
    public function validate( array $args, State $state )
    {
        foreach ( array_keys( $args ) as $key ) {
            if ( ! in_array( $key, array( 'payment_id', 'index' ), true ) ) {
                return array( 'arguments: unknown argument ' . $key . ' — the action takes payment_id, index' );
            }
        }
        if ( ! isset( $args['index'] ) ) {
            return array( 'adjustment_not_found: index is required — the position from get_payment.adjustments' );
        }
        list( $payment, $numbers, $errors ) = SetPaymentAdjustment::common( $args, $state );
        if ( $errors ) {
            return $errors;
        }
        $state->set( 'payment:' . $payment->getId(), self::after( $payment, $args['index'], $numbers ) );

        return array();
    }

    /**
     * @inheritDoc
     */
    public function preview( array $args, State $state )
    {
        $payment = Payment::find( (int) $args['payment_id'] );
        $before  = PaymentData::state( $payment, $state );
        $after   = self::after( $payment, $args['index'], $before );
        $old     = $before['adjustments'][ $args['index'] ];
        $preview = SetPaymentAdjustment::totalsPreview( $payment, $before, $after, array( array(
            'label'  => PaymentData::title( $payment ) . ' › ' . __( 'Manual adjustment', 'bookly-responsive-appointment-booking-tool' ),
            'before' => $old['reason'] . ': ' . Lib\Utils\Price::format( $old['amount'] ) . ( $old['tax'] != 0 ? ' (' . __( 'Tax', 'bookly-responsive-appointment-booking-tool' ) . ' ' . Lib\Utils\Price::format( $old['tax'] ) . ')' : '' ),
            'after'  => '—',
        ) ) );
        $state->set( 'payment:' . $payment->getId(), $after );

        return $preview;
    }

    /**
     * @inheritDoc
     */
    public function apply( array $args )
    {
        $payment = Payment::find( (int) $args['payment_id'] );
        $before  = PaymentData::snapshot( $payment );
        SetPaymentAdjustment::store( $payment, self::after( $payment, $args['index'], $before ) );

        return array( 'payment_id' => (int) $payment->getId(), 'before' => $before, 'after' => PaymentData::snapshot( $payment ) );
    }

    /**
     * @inheritDoc
     */
    public function undo( array $snapshot )
    {
        $payment = Payment::find( $snapshot['payment_id'] );
        if ( $payment && PaymentData::snapshot( $payment ) == $snapshot['after'] ) {
            SetPaymentAdjustment::store( $payment, $snapshot['before'] );
        }
    }
}
