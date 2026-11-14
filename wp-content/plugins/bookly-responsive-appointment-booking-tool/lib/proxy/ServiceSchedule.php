<?php
namespace Bookly\Lib\Proxy;

use Bookly\Lib;

/**
 * @method static array getSchedule( int $service_id ) Get schedule for service.
 * @method static array getWeeklySchedule( array $service_ids ) Get schedule for services.
 * @method static array getServiceScheduleDays( int $service_id ) Stored schedule rows of a service, without creating missing days: array( day_index => array( 'start_time', 'end_time', 'breaks' => array( array( 'start_time', 'end_time' ) ) ) ), day_index 1 = Sunday.
 * @method static void  setServiceScheduleDays( int $service_id, array $days ) Write the schedule of a service in the shape of getServiceScheduleDays(): listed days are created or updated, their breaks replaced when 'breaks' is given; rows of days not listed are deleted.
 */
abstract class ServiceSchedule extends Lib\Base\Proxy
{

}