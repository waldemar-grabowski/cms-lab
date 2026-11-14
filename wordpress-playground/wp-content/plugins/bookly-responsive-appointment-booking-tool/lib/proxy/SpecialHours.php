<?php
namespace Bookly\Lib\Proxy;

use Bookly\Lib;

/**
 * @method static float adjustPrice( float $price, int $staff_id, int $service_id, int $location_id, $start_time, int $week_day ) Adjust price for given staff, service and time.
 * @method static array adjustPrices( array $items ) Batch version of adjustPrice: loads special hours in a single query and adjusts many prices in memory. $items = [ key => ['price','staff_id','service_id','location_id','start_time','week_day'] ]; returns [ key => adjusted price ].
 * @method static bool  isNotInSpecialHour( $start_time, $end_time, int $service_id, int $staff_id, int $location_id, array|null $days ) Shows if period between start_date and end_date intersected with any special hour period
 * @method static int|null getPeriodsCount( int[] $staff_ids, int[] $service_ids ) Number of special hour periods of these staff members for these services.
 */
abstract class SpecialHours extends Lib\Base\Proxy
{
}