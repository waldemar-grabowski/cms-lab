<?php
namespace Bookly\Backend\Components\Dialogs\Payment;

use Bookly\Lib;
use Bookly\Lib\Entities\Payment;

class Dialog extends Lib\Base\Component
{
    /**
     * Render payment details dialog.
     */
    public static function render()
    {
        self::enqueueStyles( array(
            'alias' => array( 'bookly-backend-globals', ),
        ) );

        self::enqueueScripts( array(
            'module' => array( 'js/payment-details-dialog.js' => array( 'bookly-backend-globals' ), ),
        ) );

        $types = array();
        foreach ( Payment::getTypes() as $type ) {
            $types[ $type ] = Payment::typeToString( $type );
        }

        $statuses = array();
        foreach ( array( Payment::STATUS_COMPLETED, Payment::STATUS_PENDING, Payment::STATUS_REJECTED, Payment::STATUS_REFUNDED, ) as $status ) {
            $statuses[ $status ] = Payment::statusToString( $status );
        }

        wp_localize_script( 'bookly-payment-details-dialog.js', 'BooklyL10nPaymentDetailsDialog', array(
            'types' => $types,
            'statuses' => $statuses,
            'moment_format_date' => Lib\Utils\DateTime::convertFormat( 'date', Lib\Utils\DateTime::FORMAT_MOMENT_JS ),
            'moment_format_time' => Lib\Utils\DateTime::convertFormat( 'time', Lib\Utils\DateTime::FORMAT_MOMENT_JS ),
            'format_price' => Lib\Utils\Price::formatOptions(),
            'can_edit' => (int) ( Lib\Utils\Common::isCurrentUserSupervisor() || Lib\Utils\Common::isCurrentUserStaff() ),
            'l10n' => array(
                'amount' => __( 'Amount', 'bookly-responsive-appointment-booking-tool' ),
                'apply' => __( 'Apply', 'bookly-responsive-appointment-booking-tool' ),
                'bind_payment' => __( 'Bind payment', 'bookly-responsive-appointment-booking-tool' ),
                'cancel' => __( 'Cancel', 'bookly-responsive-appointment-booking-tool' ),
                'close' => __( 'Close', 'bookly-responsive-appointment-booking-tool' ),
                'complete_payment' => __( 'Complete payment', 'bookly-responsive-appointment-booking-tool' ),
                'coupon_discount' => __( 'Coupon discount', 'bookly-responsive-appointment-booking-tool' ),
                'gift_card_discount' => __( 'Gift card discount', 'bookly-responsive-appointment-booking-tool' ),
                'customer' => __( 'Customer', 'bookly-responsive-appointment-booking-tool' ),
                'date' => __( 'Date', 'bookly-responsive-appointment-booking-tool' ),
                'deposit' => _x( 'Deposit', 'portion of the payment', 'bookly-responsive-appointment-booking-tool' ),
                'discount' => __( 'Discount', 'bookly-responsive-appointment-booking-tool' ),
                'child_payment' => __( 'Additional payment', 'bookly-responsive-appointment-booking-tool' ),
                'checkout_url' => __( 'Copy checkout URL', 'bookly-responsive-appointment-booking-tool' ),
                'due' => __( 'Due', 'bookly-responsive-appointment-booking-tool' ),
                'group_discount' => __( 'Group discount', 'bookly-responsive-appointment-booking-tool' ),
                'manual_adjustment' => __( 'Manual adjustment', 'bookly-responsive-appointment-booking-tool' ),
                'invoice_number' => __( 'Invoice number', 'bookly-responsive-appointment-booking-tool' ),
                'invoice_number_exists' => __( 'This invoice number is already in use', 'bookly-responsive-appointment-booking-tool' ),
                'na' => __( 'N/A', 'bookly-responsive-appointment-booking-tool' ),
                'paid' => __( 'Paid', 'bookly-responsive-appointment-booking-tool' ),
                'payment' => __( 'Payment', 'bookly-responsive-appointment-booking-tool' ),
                'payment_is_not_found' => __( 'Payment not found.', 'bookly-responsive-appointment-booking-tool' ),
                'price' => __( 'Price', 'bookly-responsive-appointment-booking-tool' ),
                'price_correction' => __( 'Price correction', 'bookly-responsive-appointment-booking-tool' ),
                'provider' => __( 'Provider', 'bookly-responsive-appointment-booking-tool' ),
                'reason' => __( 'Reason', 'bookly-responsive-appointment-booking-tool' ),
                'item' => __( 'Item', 'bookly-responsive-appointment-booking-tool' ),
                'status' => __( 'Status', 'bookly-responsive-appointment-booking-tool' ),
                'subtotal' => __( 'Subtotal', 'bookly-responsive-appointment-booking-tool' ),
                'tax' => __( 'Tax', 'bookly-responsive-appointment-booking-tool' ),
                'tips' => __( 'Tips', 'bookly-responsive-appointment-booking-tool' ),
                'total' => __( 'Total', 'bookly-responsive-appointment-booking-tool' ),
                'type' => __( 'Type', 'bookly-responsive-appointment-booking-tool' ),
                'wc_order_id' => __( 'order ID', 'bookly-responsive-appointment-booking-tool' ),
                'refund' => __( 'Refund', 'bookly-responsive-appointment-booking-tool' ),
                'are_you_sure_want_refund' => __( 'Are you sure you want to approve refund? This action cannot be undone.', 'bookly-responsive-appointment-booking-tool' )
            ),
        ) );
    }
}