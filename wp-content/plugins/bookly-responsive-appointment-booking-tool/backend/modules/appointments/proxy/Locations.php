<?php
namespace Bookly\Backend\Modules\Appointments\Proxy;

use Bookly\Lib;

/**
 * @method static void renderFilter() Render location filter on appointments list page.
 * @method static array getFilterOptions() Return location options as array of {id, name} for client-side filter.
 */
abstract class Locations extends Lib\Base\Proxy
{

}
