<?php
namespace Bookly\Lib\Utils;

use Bookly\Lib;
use Bookly\Lib\Entities;

/**
 * What a code is, apart from where it may be used: the group it belongs to and a sample
 * of what it prints. Every place still builds its own list of codes; this only fills in
 * the code editor's view of them.
 */
abstract class CodesCatalog
{
    /** @var array|null */
    protected static $catalog;

    /**
     * Codes for the code editor (CodeEditor.svelte), with a sample for every code
     * the catalog knows.
     *
     * @param array $codes Codes in the format of Codes::getJson(); a code may name its 'group'
     * @return array { codes: array, groups: array group => title, in the order to show them }
     */
    public static function forEditor( array $codes )
    {
        $catalog = self::get();

        return array(
            'codes' => self::withSamples( $codes, $catalog['samples'] ),
            'groups' => $catalog['groups'],
        );
    }

    /**
     * @param array $codes
     * @param array $samples
     * @return array
     */
    protected static function withSamples( array $codes, array $samples )
    {
        foreach ( $codes as $name => &$code ) {
            if ( ! is_array( $code ) ) {
                continue;
            }
            if ( isset( $code['loop']['codes'] ) ) {
                $code['loop']['codes'] = self::withSamples( $code['loop']['codes'], $samples );
            } elseif ( ! isset( $code['sample'] ) && isset( $samples[ $name ] ) && $samples[ $name ] !== '' ) {
                $code['sample'] = $samples[ $name ];
            }
        }

        return $codes;
    }

    /**
     * @return array { groups: array, samples: array }
     */
    protected static function get()
    {
        if ( self::$catalog === null ) {
            self::$catalog = Lib\Proxy\Shared::prepareCodesCatalog( array(
                'groups' => self::groups(),
                'samples' => self::samples(),
            ) );
        }

        return self::$catalog;
    }

    /**
     * Titles of the groups codes are listed under. Several groups can share a title:
     * the editor shows them as one.
     *
     * @return array
     */
    protected static function groups()
    {
        $client = __( 'Client', 'bookly-responsive-appointment-booking-tool' );
        $appointment = __( 'Appointment', 'bookly-responsive-appointment-booking-tool' );
        $service = __( 'Service', 'bookly-responsive-appointment-booking-tool' );
        $staff = __( 'Staff', 'bookly-responsive-appointment-booking-tool' );
        $account = __( 'Account', 'bookly-responsive-appointment-booking-tool' );

        return array(
            'customer' => $client,
            'customer_timezone' => $client,
            'customer_locale' => $client,
            'appointment' => $appointment,
            'customer_appointment' => $appointment,
            'appointments_list' => $appointment,
            'order' => $appointment,
            'cart' => __( 'Cart', 'bookly-responsive-appointment-booking-tool' ),
            'service' => $service,
            'category' => $service,
            'staff' => $staff,
            'staff_agenda' => $staff,
            'mobile_staff_cabinet' => $staff,
            'payment' => __( 'Payment', 'bookly-responsive-appointment-booking-tool' ),
            'company' => __( 'Company', 'bookly-responsive-appointment-booking-tool' ),
            'user_credentials' => $account,
            'verification_code' => $account,
        );
    }

    /**
     * Samples of what codes print. The company is real, the catalog is taken from the
     * first service and staff member, the client and the appointment are made up.
     *
     * @return array
     */
    protected static function samples()
    {
        $tomorrow = date_create( current_time( 'mysql' ) )->modify( '+1 day' )->format( 'Y-m-d' );
        $samples = array(
            'client_name' => 'Jane Smith',
            'client_first_name' => 'Jane',
            'client_last_name' => 'Smith',
            'client_email' => 'jane.smith@example.com',
            'client_phone' => '+1 555 0100',
            'client_address' => '1 Main St, Springfield',
            'client_timezone' => Lib\Config::getWPTimeZone(),
            'client_locale' => get_locale(),
            'appointment_id' => '1024',
            'booking_number' => '1024',
            'order_id' => '1024',
            'appointment_date' => DateTime::formatDate( $tomorrow ),
            'appointment_time' => DateTime::formatTime( '10:00:00' ),
            'agenda_date' => DateTime::formatDate( $tomorrow ),
            'tomorrow_date' => DateTime::formatDate( $tomorrow ),
            'status' => Entities\CustomerAppointment::statusToString( Entities\CustomerAppointment::STATUS_APPROVED ),
            'payment_type' => Entities\Payment::typeToString( Entities\Payment::TYPE_LOCAL ),
            'payment_status' => Entities\Payment::statusToString( Entities\Payment::STATUS_COMPLETED ),
            'company_name' => get_option( 'bookly_co_name' ),
            'company_phone' => get_option( 'bookly_co_phone' ),
            'company_website' => get_option( 'bookly_co_website' ),
            'company_address' => get_option( 'bookly_co_address' ),
            'site_address' => site_url(),
        );

        /** @var Entities\Service $service */
        $service = Entities\Service::query()->where( 'type', Entities\Service::TYPE_SIMPLE )->sortBy( 'position' )->findOne();
        if ( $service ) {
            $end = date_create( $tomorrow . ' 10:00:00' )->modify( '+' . (int) $service->getDuration() . ' seconds' );
            $samples += array(
                'service_name' => $service->getTranslatedTitle(),
                'service_price' => Price::format( $service->getPrice() ),
                'total_price' => Price::format( $service->getPrice() ),
                'service_duration' => DateTime::secondsToInterval( $service->getDuration() ),
                'total_duration' => DateTime::secondsToInterval( $service->getDuration() ),
                'service_info' => $service->getInfo(),
                'appointment_end_date' => DateTime::formatDate( $end->format( 'Y-m-d' ) ),
                'appointment_end_time' => DateTime::formatTime( $end->format( 'H:i:s' ) ),
            );
            $category = $service->getCategoryId() ? Entities\Category::find( $service->getCategoryId() ) : null;
            if ( $category ) {
                $samples['category_name'] = $category->getTranslatedName();
                $samples['category_info'] = $category->getInfo();
            }
        }

        /** @var Entities\Staff $staff */
        $staff = Entities\Staff::query()->sortBy( 'position' )->findOne();
        if ( $staff ) {
            $samples += array(
                'staff_name' => $staff->getTranslatedName(),
                'staff_email' => $staff->getEmail(),
                'staff_phone' => $staff->getPhone(),
                'staff_info' => $staff->getInfo(),
            );
        }

        return $samples;
    }
}
