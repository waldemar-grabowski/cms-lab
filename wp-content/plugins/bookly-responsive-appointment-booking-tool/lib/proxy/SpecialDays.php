<?php
namespace Bookly\Lib\Proxy;

use Bookly\Lib\Base;

/**
 * @method static array getSchedule( array $staff_ids, \DateTime $start_date, \DateTime $end_date ) Get special days with breaks for given staff ids.
 * @method static array getServiceSchedule( int|array $service_id, \DateTime $start_date, \DateTime $end_date ) Get service schedule ( working time & breaks ).
 * @method static array getDaysAndTimes() Get days and times for the first step of booking.
 * @method static array getServiceSpecialDays( int $service_id ) Special days of a service sorted by date: array( array( 'id', 'date', 'start_time', 'end_time', 'breaks' => array( array( 'start_time', 'end_time' ) ) ) ).
 * @method static void  setServiceSpecialDays( int $service_id, string $date, array $days ) Make the service's special days on the date exactly $days ( array( array( 'start_time', 'end_time', 'breaks' ) ) ); empty deletes them.
 * @method static int   saveStaffSpecialDay( int $staff_id, string $date, string $start_time, string $end_time, array|null $breaks ) Create or update the staff member's special day ( location_id NULL ) on the date; $breaks — array( array( 'start_time', 'end_time' ) ) replaces the day's breaks, null keeps them. Returns the special day id.
 * @method static void  deleteStaffSpecialDay( int $special_day_id ) Delete a staff special day with its breaks.
 */
abstract class SpecialDays extends Base\Proxy
{

}