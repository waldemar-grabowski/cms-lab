<?php
namespace Bookly\Lib\Proxy;

use Bookly\Lib;
use BooklyCustomStatuses\Lib\Entities\CustomStatus;

/**
 * @method static CustomStatus[] getAll() Get all custom statuses.
 * @method static array prepareAllStatuses( array $statuses ) Prepare all available statuses.
 * @method static array prepareBusyStatuses( array $statuses ) Prepare statuses which are considered busy.
 * @method static array prepareFreeStatuses( array $statuses ) Prepare statuses which are considered free.
 * @method static string statusToString( string $status ) Convert status to human readable string.
 * @method static string statusToIcon( string $status ) Convert status to CSS class for icon
 * @method static array  getStatusesList() Custom statuses in the order of the Order dialog: array( array( 'id', 'slug', 'name', 'busy', 'position', 'color' ) ).
 * @method static int    saveStatus( array $fields ) Create a status ( no 'id': 'name', 'busy'? ) as the New Status dialog does, or change 'busy' of one; the slug of an existing status is kept. Returns the id, 0 when the status is not found.
 * @method static void   setStatusesPosition( array $ids ) Positions 0…n-1 in the order of the ids, as the Order dialog saves them.
 * @method static void   deleteStatus( int $id ) Delete a status that no appointment has.
 */
abstract class CustomStatuses extends Lib\Base\Proxy
{

}