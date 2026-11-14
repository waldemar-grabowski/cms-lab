<?php
namespace Bookly\Lib\Proxy;

use Bookly\Lib;

/**
 * @method static void addBooklyMenuItem() Add 'Gift Cards' to Bookly menu.
 * @method static \BooklyGiftCards\Lib\Entities\GiftCardType|null findGiftCardType( int $id ) Find gift card type by ID.
 * @method static array prepareWcProductIds( array $ids ) Add gift card WooCommerce product IDs.
 * @method static int|null wcProductIdByCartTypeId( int $cart_type_id ) Get WC product ID for gift card type.
 */
abstract class GiftCards extends Lib\Base\Proxy
{

}
