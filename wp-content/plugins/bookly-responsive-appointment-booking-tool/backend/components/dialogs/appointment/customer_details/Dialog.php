<?php
namespace Bookly\Backend\Components\Dialogs\Appointment\CustomerDetails;

use Bookly\Lib;
use Bookly\Lib\Entities\CustomerAppointment;

class Dialog extends Lib\Base\Component
{
    /**
     * Render customer details dialog.
     */
    public static function render()
    {
        self::enqueueScripts( array(
            'module' => array( 'js/customer-details.js' => array( 'bookly-backend-globals' ), ),
        ) );

        self::enqueueData( array(
            'extras_list',
            'extras_multiply_nop'
        ), 'bookly-customer-details.js' );

        $statuses = array();
        foreach ( CustomerAppointment::getStatuses() as $status ) {
            $statuses[] = array(
                'id' => $status,
                'title' => CustomerAppointment::statusToString( $status ),
            );
        }

        wp_localize_script( 'bookly-customer-details.js', 'BooklyL10nCustomerDetailsDialog', Proxy\Shared::prepareL10n( array(
            'statuses' => $statuses,
            'showNotes' => Lib\Config::showNotes(),
            'l10n' => array(
                'customerDetails' => __( 'Edit booking details', 'bookly-responsive-appointment-booking-tool' ),
                'nop' => __( 'Number of persons', 'bookly-responsive-appointment-booking-tool' ),
                'status' => __( 'Status', 'bookly-responsive-appointment-booking-tool' ),
                'notes' => __( 'Appointment notes', 'bookly-responsive-appointment-booking-tool' ),
                'notes_help' => sprintf( __( 'This text can be inserted into notifications with %s code', 'bookly-responsive-appointment-booking-tool' ), '{appointment_notes}' ),
                'timezone' => __( 'Timezone', 'bookly-responsive-appointment-booking-tool' ),
                'apply' => __( 'Apply', 'bookly-responsive-appointment-booking-tool' ),
                'cancel' => __( 'Cancel', 'bookly-responsive-appointment-booking-tool' ),
                'areYouSure' => __( 'Are you sure?', 'bookly-responsive-appointment-booking-tool' ),
            ),
        ) ) );
    }
}