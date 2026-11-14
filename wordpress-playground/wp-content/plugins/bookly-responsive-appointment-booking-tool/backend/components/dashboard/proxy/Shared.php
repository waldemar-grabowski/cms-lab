<?php
namespace Bookly\Backend\Components\Dashboard\Proxy;

use Bookly\Lib;

/**
 * @method static array|null getTicketSales( string $based_on, string $from_s, string $to_s, mixed $staff, array $services ) Tickets paid for per day (rows of quantity / group_date) for the sales chart.
 * @method static array|null getPackageSales( string $from_s, string $to_s, mixed $staff, array $services ) Packages paid for per day (rows of quantity / group_date) for the sales chart.
 * @method static array|null getGiftCardSales( string $from_s, string $to_s, mixed $staff, array $services ) Gift cards paid for per day (rows of quantity / group_date) for the sales chart.
 * @method static string|false|null getStandaloneSalesConstraint( mixed $staff, array $services ) SQL condition (against payments alias p) restricting standalone-sale payments to packages matching the staff / services filter; falsy when nothing can match.
 */
abstract class Shared extends Lib\Base\Proxy
{

}
