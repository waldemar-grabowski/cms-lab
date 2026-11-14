<?php
namespace Bookly\Lib\Proxy;

use Bookly\Lib;

/**
 * @method static void   addLicenseBooklyMenuItem() Add 'License' to Bookly admin menu.
 * @method static bool   addToWooCommerceCart( bool $added, Lib\UserBookingData $userData ) Put the cart into the WooCommerce cart, false when it could not be added ( add-on off, item no longer available ).
 * @method static string createWPUser( Lib\Entities\Customer $customer ) Crate WP user for customer.
 * @method static void   createBackendPayment( array $data, Lib\Entities\CustomerAppointment $ca ) Crate WP user for customer.
 * @method static void   deleteGoogleCalendarEvent( Lib\Entities\Appointment $appointment ) Delete Google Calendar event for given appointment.
 * @method static void   deleteOnlineMeeting( Lib\Base\Entity $entity ) Delete online meeting.
 * @method static void   deleteStaffCategory( int $category_id ) Delete staff category, its staff members become uncategorized.
 * @method static string getFullAddressByCustomerData( array $data ) Get address string from customer data.
 * @method static string getCustomerTimezone( string|null $time_zone, string $time_zone_offset ) Get last appointment timezone for customer.
 * @method static array  getDisplayedAddressFields() Get displayed address fields.
 * @method static array  getGoogleCalendarBookings( array $staff_ids, Lib\Slots\DatePoint $dp ) Get bookings fromGoogle Calendar  for Finder.
 * @method static string getGoogleCalendarSyncMode() Get Google Calendar synchronization mode ( 1-way, 1.5-way, 2-way. null means Google Calendar integration is not configured ).
 * @method static string|null getLastCustomerLocale( int $customer_id ) Get last appointment locale for customer.
 * @method static string getLastCustomerTimezone( int $customer_id ) Get last appointment timezone for customer.
 * @method static int    getMinimumTimePriorBooking( int $service_id ) Get minimum time ( in seconds ) prior to booking.
 * @method static int    getMinimumTimePriorCancel( int $service_id ) Get minimum time ( in seconds ) prior to cancel.
 * @method static array  getStaffCategoryName( int $category_id ) Get staff category name.
 * @method static array  getStaffDataForDropDown( array $exclude_visibility = null ) Get staff grouped by categories for drop-down list.
 * @method static array  getTagsData( string $type ) Get list of tags.
 * @method static array  getTagRows( string $type ) Rows of the tag catalog of the type with all columns.
 * @method static int    insertTagRow( array $row ) Insert a tag catalog row ( with its id when given ); returns the id.
 * @method static void   deleteTagRows( array $ids ) Delete tag catalog rows.
 * @method static int    getEmailLogsCount( string $since ) Number of emails in the email log since the given date ( Y-m-d H:i:s ).
 * @method static array  getServiceStaffPreferenceOrders( int $service_id ) Positions of staff members for the "Specified order" rule of a service: array( staff_id => position ).
 * @method static void   setServiceStaffPreferenceOrders( int $service_id, array $positions, array $remove_staff_ids ) Write positions ( array( staff_id => position ) ) for a service and delete the rows of $remove_staff_ids.
 * @method static array  getTimeZoneOffset( string $time_zone_value ) Get timezone offset from string.
 * @method static bool   graceExpired() Check whether grace period has expired or not.
 * @method static void   logEmail( string $to, string $subject, string $body, array $headers, array $attachments, int $type_id ) Log sent emails.
 * @method static string prepareNotificationMessage( string $message, string $recipient, string $gateway ) Prepare notification for staff.
 * @method static Lib\Slots\RangeCollection prepareGeneratorRanges( Lib\Slots\RangeCollection $ranges, Lib\Slots\Staff $staff, int $duration ) Prepare range collection depends on staff hours limit.
 * @method static bool   getWorkingTimeLimitError( Lib\Entities\Staff $staff, string $start_date, string $end_date, int $duration, int $appointment_id ) Check if interval is suitable for staff's hours limit.
 * @method static void   renderLicenseDialog() Render license dialog.
 * @method static void   revokeGoogleCalendarToken( Lib\Entities\Staff $staff ) Revoke Google Calendar token for given staff.
 * @method static array  saveStaffCategories( array $categories ) Save staff categories in the given order ( array( array( 'id' => int|null, 'name' => string, 'info' => string ) ) ), positions 0…n-1; image is kept. Returns ids in the same order.
 * @method static bool   showFacebookLoginButton() Whether to show Facebook login button at the time step of booking form.
 * @method static void   syncGoogleCalendarEvent( Lib\Entities\Appointment $appointment ) Synchronize Google Calendar with appointment.
 * @method static bool   isValidForCartItem( $default, Lib\CartItem $item, Lib\CartInfo $cart_info ) Check if gift card valid for current item
 */
abstract class Pro extends Lib\Base\Proxy
{

}