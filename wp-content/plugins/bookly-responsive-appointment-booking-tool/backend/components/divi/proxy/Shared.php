<?php
namespace Bookly\Backend\Components\Divi\Proxy;

use Bookly\Lib;

/**
 * @method static array prepareBooklyFormFields( array $fields ) Add/prepend Divi Builder fields (e.g. locations, persons, quantity, duration). Providers that must appear before Category (e.g. locations) should prepend via `$new + $fields`.
 * @method static array prepareBooklyFormShortcode( array $shortcode, array $props ) Add shortcode attribute/hide entries ( list( $short_code, $hide ) ), e.g. locations, persons, quantity, duration.
 */
abstract class Shared extends Lib\Base\Proxy
{

}
