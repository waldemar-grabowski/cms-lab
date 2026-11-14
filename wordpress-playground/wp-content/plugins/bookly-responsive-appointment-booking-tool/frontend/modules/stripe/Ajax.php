<?php
namespace Bookly\Frontend\Modules\Stripe;

use Bookly\Lib;

class Ajax extends Lib\Base\Ajax
{
    /**
     * @inheritDoc
     */
    protected static function permissions()
    {
        return array( '_default' => 'anonymous' );
    }

    public static function cloudStripeNotify()
    {
        $response_code = 200;
        $payment = null;
        if ( Lib\Cloud\API::getInstance()->account->productActive( 'stripe' ) ) {
            try {
                $payment = self::notify();
            } catch ( \Exception $e ) {
                Lib\Utils\Log::error( $e->getMessage(), $e->getFile(), $e->getLine() );
                $response_code = 400;
            }
        }
        wp_send_json( array(
            'event_id' => $_POST['event_id'],
            'payment_id' => $payment ? $payment->getId() : null,
            'order_id' => $payment ? $payment->getOrderId() : null,
            'status' => $payment ? $payment->getStatus() : null,
        ), $response_code );
    }

    /**
     * Retrieve event by notifying from Bookly Cloud
     *
     * @throws \Exception
     */
    private static function notify()
    {
        $event = Lib\Cloud\API::getInstance()->getProduct( Lib\Cloud\Account::PRODUCT_STRIPE )->retrieveEvent( $_POST['event_id'] );
        switch ( $event['type'] ) {
            case 'checkout.session.completed':
                return self::processCheckoutSessionCompleted( $event );
                break;
            case 'charge.refunded':
                return self::processChargeRefunded( $event );
                break;
        }
    }

    /**
     * Process Stripe event checkout.session.completed
     *
     * @param array $event
     * @return Lib\Entities\Payment
     */
    private static function processCheckoutSessionCompleted( $event )
    {
        $gateway = new Lib\Payment\StripeCloudGateway( \Bookly\Frontend\Modules\Payment\Request::getInstance() );
        $payment = new Lib\Entities\Payment();
        if ( $payment->loadBy( array( 'id' => $event['metadata']['payment_id'], 'type' => Lib\Entities\Payment::TYPE_CLOUD_STRIPE ) ) ) {
            if ( array_key_exists( 'payment_intent', $event ) ) {
                $payment->setRefId( $event['payment_intent'] )->save();
                if ( $payment->getStatus() === Lib\Entities\Payment::STATUS_REJECTED ) {
                    // Case when rools 'Time interval of payment gateway' set payment as rejected
                    // We set status pending without saving for running retriving process
                    $payment->setStatus( Lib\Entities\Payment::STATUS_PENDING );
                }
            }
            $gateway->setPayment( $payment )->retrieve();
        }

        return $payment;
    }

    /**
     * Process Stripe charge.refunded
     *
     * @param array $data
     * @return Lib\Entities\Payment
     */
    private static function processChargeRefunded( $data )
    {
        /** @var Lib\Entities\Payment $payment */
        $payment = Lib\Entities\Payment::query()
            ->where( 'id', $data['metadata']['payment_id'] )
            ->where( 'type', Lib\Entities\Payment::TYPE_CLOUD_STRIPE )
            ->whereNot( 'status', Lib\Entities\Payment::STATUS_REFUNDED )
            ->findOne();
        if ( $payment ) {
            $payment
                ->setStatus( Lib\Entities\Payment::STATUS_REFUNDED )
                ->save();
        }

        return $payment;
    }

    /**
     * Override parent method to exclude actions from CSRF token verification.
     *
     * @param string $action
     * @return bool
     */
    protected static function csrfTokenValid( $action = null )
    {
        return $action === 'cloudStripeNotify' || parent::csrfTokenValid( $action );
    }
}