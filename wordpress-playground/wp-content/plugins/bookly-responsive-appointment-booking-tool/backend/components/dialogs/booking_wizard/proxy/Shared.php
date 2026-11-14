<?php
namespace Bookly\Backend\Components\Dialogs\BookingWizard\Proxy;

use Bookly\Lib;

/**
 * @method static array prepareL10n( array $l10n ) Add strings owned by add-on features to the booking wizard l10n (each add-on uses its own text domain).
 * @method static array prepareCatalog( array $catalog ) Enrich the booking wizard catalog with add-on-owned data (e.g. staff location bindings).
 * @method static array expandOrderItem( array $visits, array $item ) One order item may stand for several visits (a repeating booking is the same item said many times); return the visits it means.
 * @method static void linkCartItem( Lib\CartItem $cart_item, array $item, $index, $n ) Tie a cart item to the others it belongs with (a series is such a tie).
 * @method static void orderSaved( Lib\DataHolders\Booking\Order $order, array $items ) The order exists — write whatever the add-on keeps beside it (a repeat rule onto its series).
 * @method static bool itemHoldsTime( $holds, array $item ) Whether an order item occupies a slot at all — a booking without a time neither loses one nor blocks anybody.
 */
abstract class Shared extends Lib\Base\Proxy
{

}
