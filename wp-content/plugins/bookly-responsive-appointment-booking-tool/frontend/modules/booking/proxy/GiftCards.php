<?php
namespace Bookly\Frontend\Modules\Booking\Proxy;

use Bookly\Lib;

/**
 * @method static \BooklyGiftCards\Lib\Entities\GiftCard findOneGiftCardByCode( string $code ) Return gift card entity.
 */
abstract class GiftCards extends Lib\Base\Proxy
{

}