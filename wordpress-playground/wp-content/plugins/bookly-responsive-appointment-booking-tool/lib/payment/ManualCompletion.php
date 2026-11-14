<?php
namespace Bookly\Lib\Payment;

use Bookly\Lib;

/**
 * Marking a payment paid by hand (Complete payment in the payment dialog): paid becomes the total,
 * the status completed; appointments get the status after payment and the booking notifications
 * go out again; calendars are synced.
 */
abstract class ManualCompletion
{
    /**
     * @param Lib\Entities\Payment $payment
     */
    public static function complete( Lib\Entities\Payment $payment )
    {
        $details = $payment->getDetailsData();
        $details->setData(
            array( 'tax_paid' => $payment->getTax() )
        );

        Proxy\Events::redeemReservedAttendees( $payment );

        $payment
            ->setPaid( $payment->getTotal() )
            ->setStatus( Lib\Entities\Payment::STATUS_COMPLETED )
            ->save();

        $success_ca_status = get_option( 'bookly_successful_payment_appointment_status' );
        if ( Lib\Config::eventsActive() || ( $success_ca_status && $success_ca_status !== 'disabled' ) ) {
            $order = Lib\DataHolders\Booking\Order::createFromOrderId( $payment->getOrderId() );
            if ( $order ) {
                $order->completePayment();
                Lib\Notifications\Cart\Sender::send( $order );
            }
        }

        list( $sync ) = Lib\Config::syncCalendars();
        if ( $sync ) {
            $appointments = Lib\Entities\Appointment::query( 'a' )
                ->leftJoin( 'CustomerAppointment', 'ca', 'ca.appointment_id = a.id' )
                ->whereNot( 'a.start_date', null )
                ->where( 'ca.payment_id', $payment->getId() )
                ->find();
            foreach ( $appointments as $appointment ) {
                Lib\Utils\Common::syncWithCalendars( $appointment );
            }
        }
    }
}
