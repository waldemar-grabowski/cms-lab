<?php
namespace Bookly\Lib\Proxy;

use Bookly\Lib;
use Bookly\Backend\Components\Dialogs\Queue\NotificationList;

/**
 * @method static bool hideChildAppointments( bool $default, Lib\CartItem $cart_item ) If only first appointment in series needs to be paid hide next appointments.
 * @method static bool notifyStaffAndAdmins( bool $sent, Lib\Entities\Staff $staff, Lib\Entities\Notification $notification, Lib\Notifications\Assets\Base\Codes $codes, Lib\Notifications\Assets\Base\Attachments $attachments, $reply_to, NotificationList|null $queue = null )
 * @method static array seriesContext( array $data, Lib\Entities\Appointment $appointment, $ca ) The series an appointment belongs to and the visits in it — what a screen needs to ask whether an operation covers this visit, this one and the later ones, or the whole series.
 */
abstract class RecurringAppointments extends Lib\Base\Proxy
{

}