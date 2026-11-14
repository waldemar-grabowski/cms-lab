<?php
namespace Bookly\Backend\Modules\Settings;

class Codes
{
    /**
     * Get JSON for appearance codes
     *
     * @param string $section
     * @return string
     */
    public static function getJson( $section )
    {
        $appointment_codes = array(
            'appointment_id' => array( 'description' => __( 'Appointment ID', 'bookly-responsive-appointment-booking-tool' ) ),
            'appointment_date' => array( 'description' => __( 'Date of appointment', 'bookly-responsive-appointment-booking-tool' ), 'if' => true ),
            'appointment_time' => array( 'description' => __( 'Time of appointment', 'bookly-responsive-appointment-booking-tool' ), 'if' => true ),
            'booking_number' => array( 'description' => __( 'Booking number', 'bookly-responsive-appointment-booking-tool' ) ),
            'category_name' => array( 'description' => __( 'Name of category', 'bookly-responsive-appointment-booking-tool' ), 'if' => true ),
            'company_address' => array( 'description' => __( 'Address of company', 'bookly-responsive-appointment-booking-tool' ), 'if' => true ),
            'company_name' => array( 'description' => __( 'Name of company', 'bookly-responsive-appointment-booking-tool' ), 'if' => true ),
            'company_phone' => array( 'description' => __( 'Company phone', 'bookly-responsive-appointment-booking-tool' ), 'if' => true ),
            'company_website' => array( 'description' => __( 'Company web-site address', 'bookly-responsive-appointment-booking-tool' ), 'if' => true ),
            'internal_note' => array( 'description' => __( 'Internal note', 'bookly-responsive-appointment-booking-tool' ), 'if' => true ),
            'service_capacity' => array( 'description' => __( 'Capacity of service', 'bookly-responsive-appointment-booking-tool' ) ),
            'service_duration' => array( 'description' => __( 'Duration of service', 'bookly-responsive-appointment-booking-tool' ) ),
            'service_info' => array( 'description' => __( 'Info of service', 'bookly-responsive-appointment-booking-tool' ), 'if' => true ),
            'service_name' => array( 'description' => __( 'Name of service', 'bookly-responsive-appointment-booking-tool' ) ),
            'service_price' => array( 'description' => __( 'Price of service', 'bookly-responsive-appointment-booking-tool' ), 'if' => true ),
            'staff_email' => array( 'description' => __( 'Email of staff', 'bookly-responsive-appointment-booking-tool' ), 'if' => true ),
            'staff_info' => array( 'description' => __( 'Info of staff', 'bookly-responsive-appointment-booking-tool' ), 'if' => true ),
            'staff_name' => array( 'description' => __( 'Name of staff', 'bookly-responsive-appointment-booking-tool' ) ),
            'staff_phone' => array( 'description' => __( 'Phone of staff', 'bookly-responsive-appointment-booking-tool' ), 'if' => true ),
        );
        $client_codes = array(
            'appointment_id' => array( 'description' => __( 'Appointment ID', 'bookly-responsive-appointment-booking-tool' ) ),
            'appointment_notes' => array( 'description' => __( 'Customer notes for appointment', 'bookly-responsive-appointment-booking-tool' ), 'if' => true ),
            'booking_number' => array( 'description' => __( 'Booking number', 'bookly-responsive-appointment-booking-tool' ) ),
            'client_email' => array( 'description' => __( 'Email of client', 'bookly-responsive-appointment-booking-tool' ), 'if' => true ),
            'client_first_name' => array( 'description' => __( 'First name of client', 'bookly-responsive-appointment-booking-tool' ), 'if' => true ),
            'client_last_name' => array( 'description' => __( 'Last name of client', 'bookly-responsive-appointment-booking-tool' ), 'if' => true ),
            'client_name' => array( 'description' => __( 'Full name of client', 'bookly-responsive-appointment-booking-tool' ) ),
            'client_note' => array( 'description' => __( 'Note of client', 'bookly-responsive-appointment-booking-tool' ) ),
            'client_phone' => array( 'description' => __( 'Phone of client', 'bookly-responsive-appointment-booking-tool' ), 'if' => true ),
            'payment_status' => array( 'description' => __( 'Status of payment', 'bookly-responsive-appointment-booking-tool' ) ),
            'payment_type' => array( 'description' => __( 'Payment type', 'bookly-responsive-appointment-booking-tool' ) ),
            'status' => array( 'description' => __( 'Status of appointment', 'bookly-responsive-appointment-booking-tool' ) ),
        );
        switch ( $section ) {
            case 'calendar_one_participant' :
                $codes = array_merge( $appointment_codes, $client_codes );
                break;
            case 'calendar_many_participants' :
            case 'ics_for_staff' :
                $codes = array_merge( $appointment_codes, array(
                    'participants' => array(
                        'description' => array(
                            __( 'Loop over participants list', 'bookly-responsive-appointment-booking-tool' ),
                            __( 'Loop over participants list with delimiter', 'bookly-responsive-appointment-booking-tool' ),
                        ),
                        'loop' => array(
                            'item' => 'participant',
                            'codes' => $client_codes,
                        ),
                    ),
                ) );
                break;
            case 'ics_for_customer' :
                $codes = array(
                    'appointment_date' => array( 'description' => __( 'Date of appointment', 'bookly-responsive-appointment-booking-tool' ), 'if' => true ),
                    'appointment_time' => array( 'description' => __( 'Time of appointment', 'bookly-responsive-appointment-booking-tool' ), 'if' => true ),
                    'service_name' => array( 'description' => __( 'Name of service', 'bookly-responsive-appointment-booking-tool' ) ),
                    'service_price' => array( 'description' => __( 'Price of service', 'bookly-responsive-appointment-booking-tool' ), 'if' => true ),
                    'staff_name' => array( 'description' => __( 'Name of staff', 'bookly-responsive-appointment-booking-tool' ) ),
                    'client_name' => array( 'description' => __( 'Full name of client', 'bookly-responsive-appointment-booking-tool' ) ),
                    'client_email' => array( 'description' => __( 'Email of client', 'bookly-responsive-appointment-booking-tool' ), 'if' => true ),
                    'client_phone' => array( 'description' => __( 'Phone of client', 'bookly-responsive-appointment-booking-tool' ), 'if' => true ),
                    'company_address' => array( 'description' => __( 'Address of company', 'bookly-responsive-appointment-booking-tool' ), 'if' => true ),
                    'company_name' => array( 'description' => __( 'Name of company', 'bookly-responsive-appointment-booking-tool' ), 'if' => true ),
                    'company_phone' => array( 'description' => __( 'Company phone', 'bookly-responsive-appointment-booking-tool' ), 'if' => true ),
                    'company_website' => array( 'description' => __( 'Company web-site address', 'bookly-responsive-appointment-booking-tool' ), 'if' => true ),
                );
                break;
            default:
                $codes = array();
                break;
        }

        $codes = Proxy\Shared::prepareCodes( $codes, $section );

        return json_encode( $codes );
    }
}