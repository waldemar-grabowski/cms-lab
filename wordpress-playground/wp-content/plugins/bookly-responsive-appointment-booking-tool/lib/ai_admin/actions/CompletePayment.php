<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\State;
use Bookly\Lib\Entities\CustomerAppointment;
use Bookly\Lib\Entities\Payment;

/**
 * complete_payment — mark a pending payment paid in full, as Complete payment in the payment
 * dialog does (assets/ai-setup/actions/complete_payment.yaml). Only the pending case; the same code
 * as the dialog (Lib\Payment\ManualCompletion).
 */
class CompletePayment implements ActionInterface
{
    /**
     * @inheritDoc
     */
    public function validate( array $args, State $state )
    {
        foreach ( array_keys( $args ) as $key ) {
            if ( $key !== 'payment_id' ) {
                return array( 'arguments: unknown argument ' . $key . ' — the action takes payment_id' );
            }
        }
        list( $payment, $errors ) = PaymentData::find( isset( $args['payment_id'] ) ? $args['payment_id'] : null );
        if ( $errors ) {
            return $errors;
        }
        $numbers = PaymentData::state( $payment, $state );
        if ( $numbers['status'] !== Payment::STATUS_PENDING ) {
            return array( 'not_pending: payment ' . $payment->getId() . ' is ' . $numbers['status'] . ' — only a pending payment is marked paid by the assistant; the owner does other cases in Payments → payment → Complete payment' );
        }
        if ( ! Lib\Config::proActive() && $numbers['total'] <= $numbers['paid'] + $numbers['child_paid'] ) {
            $errors[] = 'free_needs_balance: nothing is due on this pending payment; without Bookly Pro it cannot be completed';
        }
        if ( $numbers['child_paid'] > 0 ) {
            $errors[] = 'has_additional_payments: part of it was already paid by a link (' . $numbers['child_paid'] . '); marking it paid would count that part twice — the owner completes it in the payment dialog or adds the missing amount as an adjustment';
        }
        if ( ! $errors ) {
            $numbers['status'] = Payment::STATUS_COMPLETED;
            $numbers['paid']   = $numbers['total'];
            $state->set( 'payment:' . $payment->getId(), $numbers );
        }

        return $errors;
    }

    /**
     * Statuses the appointments of the order get (Order::completePayment()).
     *
     * @param array $appointments
     * @return array customer_appointment_id => new status, only those that change
     */
    protected static function newStatuses( array $appointments )
    {
        $after   = get_option( 'bookly_successful_payment_appointment_status' );
        $default = get_option( 'bookly_appointment_default_status' );
        $changes = array();
        foreach ( $appointments as $ca ) {
            if ( $after && $after !== 'disabled' ) {
                if ( $ca['status'] === $default || $ca['status'] === CustomerAppointment::STATUS_REJECTED ) {
                    $changes[ $ca['customer_appointment_id'] ] = $after;
                }
            } elseif ( Lib\Config::eventsActive() && $ca['status'] === CustomerAppointment::STATUS_REJECTED ) {
                $changes[ $ca['customer_appointment_id'] ] = $default;
            }
        }
        foreach ( $changes as $id => $status ) {
            foreach ( $appointments as $ca ) {
                if ( $ca['customer_appointment_id'] === $id && $ca['status'] === $status ) {
                    unset( $changes[ $id ] );
                }
            }
        }

        return $changes;
    }

    /**
     * @inheritDoc
     */
    public function preview( array $args, State $state )
    {
        $payment = Payment::find( (int) $args['payment_id'] );
        $before  = PaymentData::state( $payment, $state );
        $rows    = array(
            array(
                'label'  => PaymentData::title( $payment ) . ' › ' . __( 'Status', 'bookly-responsive-appointment-booking-tool' ),
                'before' => Payment::statusToString( $before['status'] ),
                'after'  => Payment::statusToString( Payment::STATUS_COMPLETED ),
            ),
            array(
                'label'  => PaymentData::title( $payment ) . ' › ' . __( 'Paid', 'bookly-responsive-appointment-booking-tool' ),
                'before' => PaymentData::paidText( $before['total'], $before['paid'], $before['child_paid'] ),
                'after'  => PaymentData::paidText( $before['total'], $before['total'], $before['child_paid'] ),
            ),
        );
        $appointments = PaymentData::appointments( $payment );
        $changes      = self::newStatuses( $appointments );
        foreach ( $appointments as $ca ) {
            if ( isset( $changes[ $ca['customer_appointment_id'] ] ) ) {
                $rows[] = array(
                    'label'  => sprintf( __( 'Appointment #%d', 'bookly-responsive-appointment-booking-tool' ), $ca['appointment_id'] ) . ' · ' . $ca['service'] . ( $ca['start'] ? ' · ' . Lib\Utils\DateTime::formatDateTime( $ca['start'] ) : '' ),
                    'before' => CustomerAppointment::statusToString( $ca['status'] ),
                    'after'  => CustomerAppointment::statusToString( $changes[ $ca['customer_appointment_id'] ] ),
                );
            }
        }
        $warnings = array( __( 'This only marks the payment as paid: no money is charged or moved, the payment method stays as it was, and the status cannot be set back to pending.', 'bookly-responsive-appointment-booking-tool' ) );
        $after = get_option( 'bookly_successful_payment_appointment_status' );
        if ( Lib\Config::eventsActive() || ( $after && $after !== 'disabled' ) ) {
            $warnings[] = __( 'The "New booking" notifications are sent again to the client, the staff member and the administrators.', 'bookly-responsive-appointment-booking-tool' );
        } else {
            foreach ( $appointments as $ca ) {
                if ( $ca['status'] === CustomerAppointment::STATUS_REJECTED ) {
                    $warnings[] = sprintf( __( 'Appointment #%d stays rejected: with "Status of appointments after payment" disabled, change its status by hand in Appointments.', 'bookly-responsive-appointment-booking-tool' ), $ca['appointment_id'] );
                }
            }
        }
        $has = PaymentData::orderHas( $payment );
        if ( $has['gift_card_purchase'] && $payment->getType() !== Payment::TYPE_LOCAL ) {
            $warnings[] = __( 'The gift card bought in this order is not created by marking it paid by hand.', 'bookly-responsive-appointment-booking-tool' );
        }
        if ( $has['coupon'] ) {
            $warnings[] = __( 'The coupon of this order is not counted as used by marking it paid by hand.', 'bookly-responsive-appointment-booking-tool' );
        }
        if ( $has['event_ticket'] ) {
            $warnings[] = __( 'The event ticket of this order is not issued by marking it paid by hand.', 'bookly-responsive-appointment-booking-tool' );
        }
        if ( $has['package'] && get_option( 'bookly_packages_show_with_payments' ) === 'completed' ) {
            $warnings[] = __( 'The package of this order becomes visible to the client and available for booking.', 'bookly-responsive-appointment-booking-tool' );
        }
        $before['status'] = Payment::STATUS_COMPLETED;
        $before['paid']   = $before['total'];
        $state->set( 'payment:' . $payment->getId(), $before );

        return array( 'rows' => $rows, 'warnings' => $warnings );
    }

    /**
     * @inheritDoc
     */
    public function apply( array $args )
    {
        $payment  = Payment::find( (int) $args['payment_id'] );
        $data     = $payment->getDetailsData()->getData();
        $statuses = array();
        foreach ( PaymentData::appointments( $payment ) as $ca ) {
            $statuses[ $ca['customer_appointment_id'] ] = $ca['status'];
        }
        $snapshot = array(
            'payment_id' => (int) $payment->getId(),
            'status'     => $payment->getStatus(),
            'paid'       => $payment->getPaid(),
            'tax_paid'   => isset( $data['tax_paid'] ) ? $data['tax_paid'] : null,
            'statuses'   => $statuses,
        );
        Lib\Payment\ManualCompletion::complete( $payment );

        return $snapshot;
    }

    /**
     * Return the payment and the appointment statuses while they are as the action left them.
     * The notifications already sent and the synced calendars stay.
     *
     * @inheritDoc
     */
    public function undo( array $snapshot )
    {
        $payment = Payment::find( $snapshot['payment_id'] );
        if ( ! $payment || $payment->getStatus() !== Payment::STATUS_COMPLETED || PaymentData::money( $payment->getPaid() ) !== PaymentData::money( $payment->getTotal() ) ) {
            return;
        }
        $payment->getDetailsData()->setData( array( 'tax_paid' => $snapshot['tax_paid'] ) );
        $payment->setPaid( $snapshot['paid'] )->setStatus( $snapshot['status'] )->save();
        foreach ( $snapshot['statuses'] as $id => $status ) {
            $ca = CustomerAppointment::find( $id );
            if ( $ca && $ca->getStatus() !== $status ) {
                $ca->setStatus( $status )->save();
            }
        }
    }
}
