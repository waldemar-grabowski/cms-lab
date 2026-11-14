<?php
namespace Bookly\Backend\Modules\AiAdmin\Tools;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\StaffData;

/**
 * get_staff({staff_id}) — one staff member in the shape the staff actions take: profile fields
 * (set_staff), services (set_staff_services), weekly schedule (set_staff_schedule,
 * set_staff_schedule_break), special days from today on (set_staff_special_day), and what the
 * dialog offers on this site.
 */
class GetStaff extends Tool
{
    const NAME = 'get_staff';

    public function getName()
    {
        return self::NAME;
    }

    protected function description()
    {
        return 'One staff member in the shape the staff actions take: staff, services, schedule, special_days, allowed values.';
    }

    protected function parameters()
    {
        return array(
            'type'       => 'object',
            'properties' => array(
                'staff_id' => array( 'type' => 'integer', 'description' => 'Id from list_staff.' ),
            ),
            'required'   => array( 'staff_id' ),
        );
    }

    public function execute( array $arguments )
    {
        if ( ! Access::allowed( array( 'admin' ) ) ) {
            return self::json( array( 'error' => 'forbidden' ) );
        }
        $staff_id = isset( $arguments['staff_id'] ) ? (int) $arguments['staff_id'] : 0;
        if ( ! StaffData::exists( $staff_id ) ) {
            return self::json( array( 'error' => 'unknown staff_id' ) );
        }
        $profile = StaffData::profile( $staff_id );
        $result  = array(
            'staff'    => array_merge( array( 'id' => $staff_id ), $profile ),
            'services' => StaffData::services( $staff_id ),
            'schedule' => array(),
        );
        $schedule = StaffData::schedule( $staff_id );
        foreach ( array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ) as $key ) {
            $result['schedule'][ $key ] = $schedule[ $key ];
        }
        $unavailable = array();
        if ( Lib\Config::specialDaysActive() ) {
            $result['special_days'] = array();
            foreach ( StaffData::specialDays( $staff_id ) as $day ) {
                unset( $day['id'] );
                $result['special_days'][] = $day;
            }
        } else {
            $unavailable['special_days'] = 'special-days';
        }
        if ( Lib\Config::locationsActive() ) {
            $result['location_schedules'] = StaffData::ownLocations( $staff_id, 'schedule' );
            $result['location_services']  = StaffData::ownLocations( $staff_id, 'services' );
        }
        $result['allowed'] = array(
            'services'          => StaffData::allowedServices(),
            'gateways'          => array_keys( Lib\Utils\Common::getGateways() ),
            'time_step_minutes' => StaffData::step(),
        );
        if ( ! Lib\Config::proActive() ) {
            foreach ( array( 'category_id', 'gateways', 'time_zone', 'working_time_limit' ) as $field ) {
                $unavailable[ $field ] = 'pro';
            }
        }
        if ( ! Lib\Config::groupBookingActive() ) {
            $unavailable['capacity'] = 'group-booking';
        }
        $result['unavailable'] = $unavailable ?: new \stdClass();

        return self::json( $result );
    }
}
