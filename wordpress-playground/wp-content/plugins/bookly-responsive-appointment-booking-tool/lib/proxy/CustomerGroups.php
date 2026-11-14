<?php
namespace Bookly\Lib\Proxy;

use Bookly\Lib;

/**
 * @method static void   addBooklyMenuItem() Add 'Customer Groups' to Bookly menu.
 * @method static Lib\Query applyFrontendServiceVisibility( Lib\Query $query )
 * @method static array  prepareDefaultAppointmentStatuses( array $statuses ) Get Default Appointment Status depending for all groups.
 * @method static array  getGroups() Get all groups
 * @method static string takeDefaultAppointmentStatus( string $status, int $group_id ) Get Default Appointment Status depending on group_id.
 * @method static array  getGroupRows() Groups with all columns and the number of their customers: array( id => array( 'id', 'name', 'description', 'appointment_status', 'discount', 'skip_payment', 'gateways', 'customers' ) ), gateways as stored ( JSON string or null ).
 * @method static int    saveGroup( array $fields ) Create a group ( no 'id': empty description, Default status, discount 0, no skip payment, Default gateways, then the given fields ) or update the given fields of one: array( 'id'?, 'name'?, 'description'?, 'appointment_status'?, 'discount'?, 'skip_payment'?, 'gateways'? ( JSON string or null ) ). Returns the group id.
 * @method static void   deleteGroup( int $group_id ) Delete a group.
 */
abstract class CustomerGroups extends Lib\Base\Proxy
{

}