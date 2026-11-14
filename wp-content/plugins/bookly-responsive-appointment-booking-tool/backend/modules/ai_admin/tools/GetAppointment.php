<?php
namespace Bookly\Backend\Modules\AiAdmin\Tools;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\AppointmentData;

/**
 * get_appointment({appointment_id}) — one appointment in full, as the appointment card reads it:
 * time, staff, service, location, note, online meeting provider and every booking of it with
 * contacts, status history, custom fields, files and payment. Site time zone.
 */
class GetAppointment extends Tool
{
    const NAME = 'get_appointment';

    public function getName()
    {
        return self::NAME;
    }

    protected function description()
    {
        return 'One appointment in full: time, staff, service, location and every booking of it.';
    }

    protected function parameters()
    {
        return array(
            'type'       => 'object',
            'properties' => array(
                'appointment_id' => array( 'type' => 'integer', 'description' => 'The id from find_appointments.' ),
            ),
            'required'   => array( 'appointment_id' ),
        );
    }

    public function execute( array $arguments )
    {
        if ( ! Access::allowed( array( 'admin' ) ) ) {
            return self::json( array( 'error' => 'forbidden' ) );
        }
        $id          = isset( $arguments['appointment_id'] ) ? (int) $arguments['appointment_id'] : 0;
        $appointment = $id ? Lib\Entities\Appointment::find( $id ) : null;
        // Another staff member's appointment answers like a missing one.
        if ( ! $appointment || ! AppointmentData::staffAllowed( $appointment->getStaffId() ) ) {
            return self::json( array( 'not_found' => $id ) );
        }

        list( $start, $end, $duration ) = AppointmentData::interval( array(
            'start_date'      => $appointment->getStartDate(),
            'end_date'        => $appointment->getEndDate(),
            'extras_duration' => $appointment->getExtrasDuration(),
        ) );
        $result = array(
            'id'        => $id,
            'start'     => $start,
            'end'       => $end,
            'duration'  => $duration,
            'time_zone' => AppointmentData::timeZone(),
            'staff'     => self::staff( $appointment ),
            'service'   => self::service( $appointment ),
        );
        if ( Lib\Config::locationsActive() ) {
            $result['location'] = null;
            if ( $appointment->getLocationId() ) {
                $location           = Lib\Proxy\Locations::findById( $appointment->getLocationId() );
                $result['location'] = array(
                    'id'   => (int) $appointment->getLocationId(),
                    'name' => $location ? $location->getName() : null,
                );
            }
        }
        $result += array(
            'internal_note'  => (string) $appointment->getInternalNote(),
            'online_meeting' => AppointmentData::onlineMeeting( $appointment->getOnlineMeetingProvider(), $appointment->getOnlineMeetingId() ),
            'created_at'     => AppointmentData::minute( $appointment->getCreatedAt() ),
            'imported_from'  => AppointmentData::importedFrom( $appointment->getCreatedFrom() ),
            'bookings'       => self::bookings( $id ),
        );

        return self::json( $result );
    }

    /**
     * @param Lib\Entities\Appointment $appointment
     * @return array|null
     */
    private static function staff( Lib\Entities\Appointment $appointment )
    {
        $staff = Lib\Entities\Staff::find( $appointment->getStaffId() );
        if ( ! $staff ) {
            return null;
        }

        return array(
            'id'        => (int) $staff->getId(),
            'full_name' => $staff->getFullName(),
            'archived'  => $staff->getVisibility() === 'archive',
            'any'       => (bool) $appointment->getStaffAny(),
            'time_zone' => AppointmentData::staffTimeZone( $staff ),
        );
    }

    /**
     * The appointment's service, or its own (Custom) service with id null.
     *
     * @param Lib\Entities\Appointment $appointment
     * @return array
     */
    private static function service( Lib\Entities\Appointment $appointment )
    {
        $service = $appointment->getServiceId() ? Lib\Entities\Service::find( $appointment->getServiceId() ) : null;
        if ( ! $service ) {
            $start = $appointment->getStartDate();

            return array(
                'id'           => null,
                'title'        => (string) $appointment->getCustomServiceName(),
                'category'     => null,
                'duration'     => $start ? strtotime( $appointment->getEndDate() ) - strtotime( $start ) : null,
                'capacity_min' => null,
                'capacity_max' => null,
                'custom_price' => $appointment->getCustomServicePrice() !== null ? round( (float) $appointment->getCustomServicePrice(), 2 ) : null,
            );
        }
        $category = $service->getCategoryId() ? Lib\Entities\Category::find( $service->getCategoryId() ) : null;
        $group    = Lib\Config::groupBookingActive();

        return array(
            'id'           => (int) $service->getId(),
            'title'        => $service->getTitle(),
            'category'     => $category ? $category->getName() : null,
            'duration'     => (int) $service->getDuration(),
            'capacity_min' => $group ? (int) $service->getCapacityMin() : 1,
            'capacity_max' => $group ? (int) $service->getCapacityMax() : 1,
            'custom_price' => null,
        );
    }

    /**
     * Every booking of the appointment, in the order they were made.
     *
     * @param int $appointment_id
     * @return array
     */
    private static function bookings( $appointment_id )
    {
        $rows = Lib\Entities\CustomerAppointment::query( 'ca' )
            ->select( 'ca.id, ca.customer_id, ca.status, ca.status_changed_at, ca.created_from, ca.created_at,
                ca.number_of_persons, ca.units, ca.extras, ca.custom_fields, ca.notes, ca.series_id, ca.package_id,
                ca.compound_service_id, ca.collaborative_service_id, ca.time_zone, ca.time_zone_offset,
                c.full_name, c.phone, c.email, c.group_id, ' . AppointmentData::paymentColumns() )
            ->leftJoin( 'Customer', 'c', 'c.id = ca.customer_id' )
            ->leftJoin( 'Payment', 'p', 'p.id = ca.payment_id' )
            ->where( 'ca.appointment_id', $appointment_id )
            ->sortBy( 'ca.id' )
            ->fetchArray();
        $files    = Lib\Config::filesActive();
        $bookings = array();
        foreach ( $rows as $row ) {
            $ca = new Lib\Entities\CustomerAppointment();
            $ca->load( $row['id'] );
            $custom_fields = array();
            foreach ( Lib\Proxy\CustomFields::getForCustomerAppointment( $ca ) ?: array() as $field ) {
                $custom_fields[] = array( 'id' => (int) $field['id'], 'label' => $field['label'], 'value' => $field['value'] );
            }
            $booking = array(
                'booking_id'        => (int) $row['id'],
                'customer'          => array(
                    'id'        => (int) $row['customer_id'],
                    'full_name' => $row['full_name'],
                    'phone'     => (string) $row['phone'],
                    'email'     => (string) $row['email'],
                    'group_id'  => $row['group_id'] !== null ? (int) $row['group_id'] : null,
                ),
                'status'            => $row['status'],
                'status_changed_at' => AppointmentData::minute( $row['status_changed_at'] ),
                'source'            => $row['created_from'],
                'created_at'        => AppointmentData::minute( $row['created_at'] ),
                'persons'           => (int) $row['number_of_persons'],
                'units'             => (int) $row['units'],
                'extras'            => AppointmentData::extras( $row['extras'] ),
                'custom_fields'     => $custom_fields,
            );
            if ( $files ) {
                $stored           = json_decode( (string) $row['custom_fields'], true );
                $booking['files'] = array_values( Lib\Proxy\Files::getFileNamesForCustomFields( is_array( $stored ) ? $stored : array() ) ?: array() );
            }
            $booking += array(
                'notes'                 => (string) $row['notes'],
                'series_id'             => $row['series_id'] !== null ? (int) $row['series_id'] : null,
                'package_id'            => $row['package_id'] !== null ? (int) $row['package_id'] : null,
                'compound_service'      => self::serviceTitle( $row['compound_service_id'] ),
                'collaborative_service' => self::serviceTitle( $row['collaborative_service_id'] ),
                'customer_time_zone'    => Lib\Config::proActive() ? Lib\Proxy\Pro::getCustomerTimezone( $row['time_zone'], $row['time_zone_offset'] ) : null,
                'payment'               => AppointmentData::payment( $row ),
            );
            $bookings[] = $booking;
        }

        return $bookings;
    }

    /**
     * Title of a parent compound / collaborative service, or null.
     *
     * @param int|null $service_id
     * @return string|null
     */
    private static function serviceTitle( $service_id )
    {
        if ( $service_id === null ) {
            return null;
        }
        $service = Lib\Entities\Service::find( $service_id );

        return $service ? $service->getTitle() : null;
    }
}
