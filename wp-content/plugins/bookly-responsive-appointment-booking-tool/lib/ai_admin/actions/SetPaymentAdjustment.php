<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\State;
use Bookly\Lib\Entities\Payment;

/**
 * set_payment_adjustment — add a manual adjustment to a payment or change one by its index, as
 * Manual adjustment in the payment dialog does (assets/ai-setup/actions/set_payment_adjustment.yaml).
 * The total changes by the amount, plus the tax when the payment was taken with tax excluded.
 */
class SetPaymentAdjustment implements ActionInterface
{
    /** Absolute limit of the DECIMAL(10,2) columns. */
    const MAX = 99999999.99;

    /**
     * Checks shared with remove_payment_adjustment.
     *
     * @param array $args
     * @param State $state
     * @return array array( Payment|null, array numbers, string[] errors )
     */
    public static function common( array $args, State $state )
    {
        if ( ! Lib\Config::proActive() ) {
            return array( null, array(), array( 'pro_inactive: manual adjustments of a payment need Bookly Pro' ) );
        }
        list( $payment, $errors ) = PaymentData::find( isset( $args['payment_id'] ) ? $args['payment_id'] : null );
        if ( $errors ) {
            return array( null, array(), $errors );
        }
        $numbers = PaymentData::state( $payment, $state );
        if ( $numbers['status'] === Payment::STATUS_REFUNDED ) {
            return array( null, array(), array( 'payment_refunded: payment ' . $payment->getId() . ' was refunded, its adjustments are not changed' ) );
        }
        if ( isset( $args['index'] ) && ( ! is_int( $args['index'] ) || ! isset( $numbers['adjustments'][ $args['index'] ] ) ) ) {
            return array( null, array(), array( 'adjustment_not_found: payment ' . $payment->getId() . ' has no adjustment with index ' . wp_json_encode( $args['index'] ) . ' — indexes are in get_payment.adjustments' ) );
        }

        return array( $payment, $numbers, array() );
    }

    /**
     * The adjustment after the item: the given fields over the old one (or a new one).
     *
     * @param array $args
     * @param array $numbers
     * @return array
     */
    protected static function newAdjustment( array $args, array $numbers )
    {
        $old = isset( $args['index'] ) ? $numbers['adjustments'][ $args['index'] ] : array( 'reason' => '', 'amount' => 0.0, 'tax' => 0.0 );

        return array(
            'reason' => array_key_exists( 'reason', $args ) ? trim( (string) $args['reason'] ) : $old['reason'],
            'amount' => array_key_exists( 'amount', $args ) ? PaymentData::money( $args['amount'] ) : $old['amount'],
            'tax'    => array_key_exists( 'tax', $args ) ? PaymentData::money( $args['tax'] ) : $old['tax'],
        );
    }

    /**
     * Numbers after the item.
     *
     * @param Payment $payment
     * @param array   $args
     * @param array   $numbers
     * @return array
     */
    protected static function after( Payment $payment, array $args, array $numbers )
    {
        $mode  = PaymentData::taxInPrice( $payment );
        $new   = self::newAdjustment( $args, $numbers );
        $after = $numbers;
        if ( isset( $args['index'] ) ) {
            $after = PaymentData::shift( $after, $numbers['adjustments'][ $args['index'] ], -1, $mode );
            $after['adjustments'][ $args['index'] ] = $new;
        } else {
            $after['adjustments'][] = $new;
        }

        return PaymentData::shift( $after, $new, 1, $mode );
    }

    /**
     * @inheritDoc
     */
    public function validate( array $args, State $state )
    {
        foreach ( array_keys( $args ) as $key ) {
            if ( ! in_array( $key, array( 'payment_id', 'index', 'reason', 'amount', 'tax' ), true ) ) {
                return array( 'arguments: unknown argument ' . $key . ' — the action takes payment_id, index, reason, amount, tax' );
            }
        }
        list( $payment, $numbers, $errors ) = self::common( $args, $state );
        if ( $errors ) {
            return $errors;
        }
        if ( ! isset( $args['index'] ) && ( ! isset( $args['reason'] ) || ! array_key_exists( 'amount', $args ) ) ) {
            $errors[] = 'add_needs_reason_and_amount: a new adjustment needs reason and amount (to change an existing one give its index)';
        }
        if ( array_key_exists( 'reason', $args ) && ( ! is_string( $args['reason'] ) || trim( $args['reason'] ) === '' ) ) {
            $errors[] = 'add_needs_reason_and_amount: reason is text and not empty — the client sees it';
        }
        foreach ( array( 'amount', 'tax' ) as $key ) {
            if ( array_key_exists( $key, $args ) && ! PaymentData::isMoney( $args[ $key ] ) ) {
                $errors[] = 'amount_not_a_number: ' . $key . ' is a number with at most 2 decimals, in the site currency (not a percent)';
            }
        }
        if ( $errors ) {
            return $errors;
        }
        $new = self::newAdjustment( $args, $numbers );
        if ( $new['tax'] != 0 && ! PaymentData::showTaxField( $payment ) ) {
            $errors[] = 'tax_field_hidden: this payment has no Tax field (no Taxes add-on and no tax on the payment) — omit tax';
        }
        if ( isset( $args['index'] ) && $new === $numbers['adjustments'][ $args['index'] ] ) {
            $errors[] = 'nothing_to_change: the adjustment already has these values';
        }
        $after = self::after( $payment, $args, $numbers );
        if ( abs( $after['total'] ) > self::MAX || abs( $after['tax'] ) > self::MAX ) {
            $errors[] = 'amount_out_of_range: the payment total or tax would not fit (at most 99999999.99)';
        } elseif ( $after['total'] < 0 ) {
            $errors[] = 'total_negative: the payment total would be ' . $after['total'] . ', below 0';
        }
        if ( ! $errors ) {
            $state->set( 'payment:' . $payment->getId(), $after );
        }

        return $errors;
    }

    /**
     * Rows and warnings shared with remove_payment_adjustment.
     *
     * @param Payment $payment
     * @param array   $before
     * @param array   $after
     * @param array   $rows rows of the adjustment itself
     * @return array
     */
    public static function totalsPreview( Payment $payment, array $before, array $after, array $rows )
    {
        $title = PaymentData::title( $payment );
        $rows[] = array(
            'label'  => $title . ' › ' . __( 'Total', 'bookly-responsive-appointment-booking-tool' ),
            'before' => Lib\Utils\Price::format( $before['total'] ),
            'after'  => Lib\Utils\Price::format( $after['total'] ),
        );
        if ( $before['tax'] != $after['tax'] ) {
            $rows[] = array(
                'label'  => $title . ' › ' . __( 'Tax', 'bookly-responsive-appointment-booking-tool' ),
                'before' => Lib\Utils\Price::format( $before['tax'] ),
                'after'  => Lib\Utils\Price::format( $after['tax'] ),
            );
        }
        $rows[] = array(
            'label'  => $title . ' › ' . __( 'Paid', 'bookly-responsive-appointment-booking-tool' ),
            'before' => PaymentData::paidText( $before['total'], $before['paid'], $before['child_paid'] ),
            'after'  => PaymentData::paidText( $after['total'], $after['paid'], $after['child_paid'] ),
        );
        $warnings = array( __( 'An adjustment changes only what is owed on the payment: nothing is charged or refunded, and the paid amount stays as it is.', 'bookly-responsive-appointment-booking-tool' ) );
        $due  = round( $after['total'] - $after['paid'] - $after['child_paid'], 2 );
        if ( $due > 0 && $after['status'] !== Payment::STATUS_PENDING ) {
            $warnings[] = sprintf( __( 'The payment now has a balance of %s; nobody is charged — the client pays it by link or the owner marks it paid.', 'bookly-responsive-appointment-booking-tool' ), Lib\Utils\Price::format( $due ) );
        } elseif ( $due < 0 ) {
            $warnings[] = sprintf( __( 'The client has paid %1$s of %2$s; the difference of %3$s is not refunded.', 'bookly-responsive-appointment-booking-tool' ), Lib\Utils\Price::format( $after['paid'] + $after['child_paid'] ), Lib\Utils\Price::format( $after['total'] ), Lib\Utils\Price::format( -$due ) );
        }

        return array( 'rows' => $rows, 'warnings' => $warnings );
    }

    /**
     * @inheritDoc
     */
    public function preview( array $args, State $state )
    {
        $payment = Payment::find( (int) $args['payment_id'] );
        $before  = PaymentData::state( $payment, $state );
        $after   = self::after( $payment, $args, $before );
        $new     = self::newAdjustment( $args, $before );
        $old     = isset( $args['index'] ) ? $before['adjustments'][ $args['index'] ] : null;
        $label   = PaymentData::title( $payment ) . ' › ' . __( 'Manual adjustment', 'bookly-responsive-appointment-booking-tool' );
        $text    = function ( $adjustment ) {
            return $adjustment === null
                ? '—'
                : $adjustment['reason'] . ': ' . Lib\Utils\Price::format( $adjustment['amount'] ) . ( $adjustment['tax'] != 0 ? ' (' . __( 'Tax', 'bookly-responsive-appointment-booking-tool' ) . ' ' . Lib\Utils\Price::format( $adjustment['tax'] ) . ')' : '' );
        };
        $preview = self::totalsPreview( $payment, $before, $after, array( array( 'label' => $label, 'before' => $text( $old ), 'after' => $text( $new ) ) ) );
        $preview['warnings'][] = __( 'The reason is visible to the client who opens this payment.', 'bookly-responsive-appointment-booking-tool' );
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
        $after   = self::after( $payment, $args, $before );
        self::store( $payment, $after );

        return array( 'payment_id' => (int) $payment->getId(), 'before' => $before, 'after' => PaymentData::snapshot( $payment ) );
    }

    /**
     * Restore total, tax and adjustments while the payment is as the action left it.
     *
     * @inheritDoc
     */
    public function undo( array $snapshot )
    {
        $payment = Payment::find( $snapshot['payment_id'] );
        if ( $payment && PaymentData::snapshot( $payment ) == $snapshot['after'] ) {
            self::store( $payment, $snapshot['before'] );
        }
    }

    /**
     * Write total, tax and the adjustments list.
     *
     * @param Payment $payment
     * @param array   $numbers
     */
    public static function store( Payment $payment, array $numbers )
    {
        $adjustments = array();
        foreach ( $numbers['adjustments'] as $item ) {
            $adjustments[] = array( 'type' => Payment::ITEM_ADJUSTMENT, 'reason' => $item['reason'], 'amount' => $item['amount'], 'tax' => $item['tax'] );
        }
        $payment->getDetailsData()->setData( array( 'adjustments' => $adjustments ) );
        $payment->setTotal( $numbers['total'] )->setTax( $numbers['tax'] )->save();
    }
}
