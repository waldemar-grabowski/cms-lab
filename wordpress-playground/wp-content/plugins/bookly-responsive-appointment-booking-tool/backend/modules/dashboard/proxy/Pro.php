<?php
namespace Bookly\Backend\Modules\Dashboard\Proxy;

use Bookly\Lib;

/**
 * @method static void renderAnalytics() Render analytics section.
 * @method static array|null getDashboardAnalytics( string $range, string $based_on, array $filter ) Analytics rows + totals for the combined dashboard endpoint (null in free).
 */
abstract class Pro extends Lib\Base\Proxy
{

}