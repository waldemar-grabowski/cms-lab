<?php
namespace Bookly\Backend\Modules\AiAdmin\Tools;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Actions\PaymentData;

/**
 * get_payment(payment_id) — one payment as its dialog shows it: amounts, adjustments with their
 * index, appointments and whether Complete payment and the Tax field are shown.
 */
class GetPayment extends Tool
{
    const NAME = 'get_payment';

    public function getName()
    {
        return self::NAME;
    }

    protected function description()
    {
        return 'One payment as its dialog shows it: amounts, adjustments, appointments, whether it can be completed.';
    }

    protected function parameters()
    {
        return array(
            'type'       => 'object',
            'properties' => array( 'payment_id' => array( 'type' => 'integer' ) ),
            'required'   => array( 'payment_id' ),
        );
    }

    public function execute( array $arguments )
    {
        if ( ! Lib\AiAdmin\Access::allowed( array( 'admin' ) ) ) {
            return self::json( array( 'error' => 'only a Bookly administrator can read payments' ) );
        }
        $payment = isset( $arguments['payment_id'] ) ? Lib\Entities\Payment::find( (int) $arguments['payment_id'] ) : null;
        if ( ! $payment ) {
            return self::json( array( 'error' => 'payment not found — ids are in find_payments' ) );
        }

        return self::json( PaymentData::data( $payment ) );
    }
}
