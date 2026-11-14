<?php
namespace Bookly\Backend\Components\Dialogs\BookingWizard;

use Bookly\Backend\Components\Dialogs\Queue\NotificationList;
use Bookly\Lib;
use Bookly\Lib\Entities\Category;
use Bookly\Lib\Entities\Service;
use Bookly\Lib\Entities\StaffService;
use Bookly\Lib\Entities\SubService;
use Bookly\Lib\Slots\DatePoint;
use Bookly\Lib\Slots\Finder;

class Ajax extends Lib\Base\Ajax
{
    /**
     * Slot search bounds (kept here, not scattered, so they are easy to tune).
     *
     * Two-phase forward search in getBookingWizardSlots:
     *   Phase 1 — scan a small NEAR window; fast and bounded for the common case.
     *   Phase 2 — only when the near window is completely empty (seasonal / far
     *             availability): scan open to the booking horizon, capped by a time
     *             budget so a pathological fully-booked business can't burn the CPU.
     */
    const SLOT_NEAR_WINDOW_DAYS = 10;    // Phase 1 near window, in days.
    const SLOT_OPEN_TIMEOUT     = 1.0;   // Phase 2 open scan, max seconds per request.

    protected static function permissions()
    {
        return array( '_default' => array( 'staff', 'supervisor' ) );
    }

    /**
     * Staff members the current user may act on. A supervisor (or admin) has no
     * restriction; a plain staff member is confined to their own staff record —
     * the same scope the calendar, the classic appointment dialog and the appointment
     * card apply. The UI hides other staff, but every endpoint below must enforce it
     * server-side: the client can forge any staff_id in the request.
     *
     * @return int[]|null Allowed staff ids, or null for no restriction.
     */
    private static function allowedStaffIds()
    {
        return Lib\Utils\Common::getCurrentUserStaffIds();
    }

    /**
     * Status the operator picked for the new bookings, or null to leave the default.
     *
     * @return string|null
     */
    private static function bookingStatus()
    {
        $status = (string) self::parameter( 'status', '' );

        return in_array( $status, Lib\Entities\CustomerAppointment::getStatuses(), true ) ? $status : null;
    }

    /**
     * Time zone of the customer for the new bookings, or null when there is none or Pro is
     * inactive. The value as the time zone list holds it: a zone name or a fixed offset
     * (UTC+2, UTC-5.5); Pro::getTimeZoneOffset() turns it into what a booking stores.
     *
     * A zone the operator did not pick is the one the customer last booked in — the zone
     * the classic form fills in. It is looked up here rather than sent by the wizard, so
     * the wizard never has to wait for it before saving.
     *
     * @param Lib\Entities\Customer|null $customer
     * @return string|null
     */
    private static function bookingTimeZone( $customer )
    {
        if ( ! Lib\Config::proActive() ) {
            return null;
        }
        $value = (string) self::parameter( 'time_zone', '' );
        if ( $value === '' ) {
            return $customer ? ( Lib\Proxy\Pro::getLastCustomerTimezone( $customer->getId() ) ?: null ) : null;
        }

        return preg_match( '/^UTC[+-]\d{1,2}(\.\d+)?$/', $value ) || in_array( $value, timezone_identifiers_list(), true )
            ? $value
            : null;
    }

    /**
     * Custom fields the operator filled in, as bookings store them: a list of {id, value}.
     * Only fields this site defines are kept, and a value is a string or a list of strings.
     *
     * @return array
     */
    private static function bookingCustomFields()
    {
        if ( ! Lib\Config::customFieldsActive() ) {
            return array();
        }
        $known = array();
        $files_active = Lib\Config::filesActive();
        foreach ( (array) Lib\Proxy\CustomFields::getWhichHaveData() as $field ) {
            if ( $files_active || $field->type !== 'file' ) {
                $known[] = (int) $field->id;
            }
        }
        $result = array();
        foreach ( (array) json_decode( self::parameter( 'custom_fields', '[]' ), true ) as $field ) {
            if ( ! is_array( $field ) || ! isset( $field['id'], $field['value'] ) || ! in_array( (int) $field['id'], $known, true ) ) {
                continue;
            }
            $value = $field['value'];
            if ( is_array( $value ) ) {
                $value = array_values( array_map( 'strval', array_filter( $value, 'is_scalar' ) ) );
            } elseif ( is_scalar( $value ) ) {
                $value = (string) $value;
            } else {
                continue;
            }
            $result[] = array( 'id' => (int) $field['id'], 'value' => $value );
        }

        return $result;
    }

    /**
     * Custom fields a booking of the given service keeps. A custom service is not a service
     * fields can be attached to, so it keeps them all — as the booking details dialog shows
     * them all for it.
     *
     * @param array $custom_fields
     * @param int $service_id
     * @return array
     */
    private static function customFieldsForService( array $custom_fields, $service_id )
    {
        if ( ! $custom_fields || ! $service_id ) {
            return $custom_fields;
        }

        return Lib\Proxy\CustomFields::filterForService( $custom_fields, $service_id );
    }

    /**
     * Single bookings an order item consists of: a series is made of visits, a multi-stage
     * visit of stages.
     *
     * @param Lib\DataHolders\Booking\Item $item
     * @return Lib\DataHolders\Booking\Simple[]
     */
    private static function orderItemLeaves( Lib\DataHolders\Booking\Item $item )
    {
        $leaves = array();
        foreach ( $item->getItems() as $sub_item ) {
            $leaves = $sub_item === $item
                ? array_merge( $leaves, array( $item ) )
                : array_merge( $leaves, self::orderItemLeaves( $sub_item ) );
        }

        return $leaves;
    }

    /**
     * Translatable strings for the booking wizard UI (mounted from JS, so its strings
     * are localized here rather than in a template). Ellipsis is kept out of the
     * translatable phrase; count sentences are printf-style formats resolved on the client.
     *
     * @return array
     */
    public static function getL10n()
    {
        $l10n = array(
            'newAppointment'        => __( 'New appointment', 'bookly-responsive-appointment-booking-tool' ),
            'openClassicForm'       => __( 'Open classic form', 'bookly-responsive-appointment-booking-tool' ),
            'rescheduleAppointment' => __( 'Reschedule appointment', 'bookly-responsive-appointment-booking-tool' ),
            'multiStageReschedule'  => __( 'This appointment is a part of a multi-stage service — reschedule it in the appointment form.', 'bookly-responsive-appointment-booking-tool' ),
            // Adding a customer to an appointment that already exists: the visit is fixed,
            // only who joins it and on what terms is chosen.
            'addCustomer'           => __( 'Add customer', 'bookly-responsive-appointment-booking-tool' ),
            'adding'                => __( 'Adding', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'addingTo'              => __( 'Adding to', 'bookly-responsive-appointment-booking-tool' ),
            'customerAdded'         => __( 'Customer added to the appointment.', 'bookly-responsive-appointment-booking-tool' ),
            'customerAddedFor'      => __( '%s was added to the appointment.', 'bookly-responsive-appointment-booking-tool' ),
            'seatsTaken'            => __( 'Seats: %1$s of %2$s', 'bookly-responsive-appointment-booking-tool' ),
            'noSeatsLeft'           => __( 'This appointment is full.', 'bookly-responsive-appointment-booking-tool' ),
            'visitGetsLonger'       => __( 'These extras make the visit longer — the appointment will be extended for all participants.', 'bookly-responsive-appointment-booking-tool' ),
            'multiStageAdd'         => __( 'This appointment is a part of a multi-stage service — add a customer to it in the appointment form.', 'bookly-responsive-appointment-booking-tool' ),
            'catalogFailed'         => __( 'Failed to load the services catalog.', 'bookly-responsive-appointment-booking-tool' ),
            'search'                => __( 'Search', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'noResults'             => __( 'No results', 'bookly-responsive-appointment-booking-tool' ),
            'customService'         => __( 'Custom service', 'bookly-responsive-appointment-booking-tool' ),
            'customServiceAdd'      => __( 'Custom service', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'preferred'             => __( 'preferred', 'bookly-responsive-appointment-booking-tool' ),
            'anyStaffMember'        => __( 'Any staff', 'bookly-responsive-appointment-booking-tool' ),
            'provideThisService'    => __( 'Provide this service', 'bookly-responsive-appointment-booking-tool' ),
            'staffNotProviding'     => __( '%1$s does not provide “%2$s” — showing all staff members', 'bookly-responsive-appointment-booking-tool' ),
            'otherStaff'            => __( 'Other staff', 'bookly-responsive-appointment-booking-tool' ),
            'notProviding'          => __( 'not providing', 'bookly-responsive-appointment-booking-tool' ),
            'duration'              => __( 'Duration', 'bookly-responsive-appointment-booking-tool' ),
            'serviceName'           => __( 'Service name', 'bookly-responsive-appointment-booking-tool' ),
            'price'                 => __( 'Price', 'bookly-responsive-appointment-booking-tool' ),
            'less'                  => __( 'Less', 'bookly-responsive-appointment-booking-tool' ),
            'more'                  => __( 'More', 'bookly-responsive-appointment-booking-tool' ),
            'loadingSlots'          => __( 'Loading slots', 'bookly-responsive-appointment-booking-tool' ),
            'fromPrice'             => _x( 'from', 'precedes a price, e.g. "from $40"', 'bookly-responsive-appointment-booking-tool' ),
            'staff'                 => __( 'staff', 'bookly-responsive-appointment-booking-tool' ),
            'notAvailableShort'     => _x( 'n/a', 'not available', 'bookly-responsive-appointment-booking-tool' ),
            'booked'                => __( 'booked', 'bookly-responsive-appointment-booking-tool' ),
            'chooseStaffMember'     => __( 'Choose staff member', 'bookly-responsive-appointment-booking-tool' ),
            'chooseAStaffMember'    => __( 'choose a staff member', 'bookly-responsive-appointment-booking-tool' ),
            'noSlots'               => __( 'No available slots for the selected criteria.', 'bookly-responsive-appointment-booking-tool' ),
            'slotsFailed'           => __( 'Failed to load slots.', 'bookly-responsive-appointment-booking-tool' ),
            'retry'                 => __( 'Retry', 'bookly-responsive-appointment-booking-tool' ),
            'openOrder'             => __( 'Open order', 'bookly-responsive-appointment-booking-tool' ),
            'order'                 => _x( 'Order', 'purchase order', 'bookly-responsive-appointment-booking-tool' ),
            'collapseOrder'         => __( 'Collapse order', 'bookly-responsive-appointment-booking-tool' ),
            'reschedule'            => __( 'Reschedule', 'bookly-responsive-appointment-booking-tool' ),
            'doubleBooking'         => __( 'double booking', 'bookly-responsive-appointment-booking-tool' ),
            'staffTimeLimit'        => __( 'exceeds the working hours limit for the staff member', 'bookly-responsive-appointment-booking-tool' ),
            'checkingLimits'        => __( 'Checking limits', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'customerBookingsLimit' => __( 'the customer has reached the limit of bookings for this service', 'bookly-responsive-appointment-booking-tool' ),
            'notifyCustomersChange' => __( 'Notify customers about this change', 'bookly-responsive-appointment-booking-tool' ),
            'rescheduling'          => __( 'Rescheduling', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'emptyOrderLine1'       => __( 'Click a slot to add it here.', 'bookly-responsive-appointment-booking-tool' ),
            'emptyOrderLine2'       => __( 'Several services can be combined into one order.', 'bookly-responsive-appointment-booking-tool' ),
            'timeWasTaken'          => __( 'time was taken — pick another slot', 'bookly-responsive-appointment-booking-tool' ),
            'addAnotherService'     => __( 'Add another service', 'bookly-responsive-appointment-booking-tool' ),
            'removeItem'            => __( 'Remove item', 'bookly-responsive-appointment-booking-tool' ),
            'apptCreatedFor'        => __( 'Appointment created for %s.', 'bookly-responsive-appointment-booking-tool' ),
            'apptsCreatedFor'       => __( '%1$s appointments created for %2$s.', 'bookly-responsive-appointment-booking-tool' ),
            'clear'                 => __( 'Clear', 'bookly-responsive-appointment-booking-tool' ),
            'durH'                  => __( '%d h', 'bookly-responsive-appointment-booking-tool' ),
            'durMin'                => __( '%d min', 'bookly-responsive-appointment-booking-tool' ),
            'showBooked'            => __( 'Show booked', 'bookly-responsive-appointment-booking-tool' ),
            'customerSelector'      => array(
                'placeholder'   => __( 'Search for customer', 'bookly-responsive-appointment-booking-tool' ),
                'noResults'     => __( 'No results found', 'bookly-responsive-appointment-booking-tool' ),
                'searching'     => __( 'Searching', 'bookly-responsive-appointment-booking-tool' ),
                'new_customer'  => __( 'New customer', 'bookly-responsive-appointment-booking-tool' ),
                'more_results'  => __( 'Refine your search to see more', 'bookly-responsive-appointment-booking-tool' ),
            ),
            'sendNotifications'     => __( 'Send notifications', 'bookly-responsive-appointment-booking-tool' ),
            'total'                 => __( 'Total', 'bookly-responsive-appointment-booking-tool' ),
            'paymentLink'           => __( 'Payment link', 'bookly-responsive-appointment-booking-tool' ),
            'appointmentNotes'      => __( 'Appointment notes', 'bookly-responsive-appointment-booking-tool' ),
            'withoutCustomer'       => __( 'Without customer', 'bookly-responsive-appointment-booking-tool' ),
            'noCustomerConflict'    => __( 'Waiting list and multi-stage items require a customer.', 'bookly-responsive-appointment-booking-tool' ),
            'apptCreated'           => __( 'Appointment created.', 'bookly-responsive-appointment-booking-tool' ),
            'apptRescheduled'       => __( 'Appointment rescheduled.', 'bookly-responsive-appointment-booking-tool' ),
            'apptRescheduledFor'    => __( 'Appointment rescheduled for %s.', 'bookly-responsive-appointment-booking-tool' ),
            'apptsCreated'          => __( 'Appointments created.', 'bookly-responsive-appointment-booking-tool' ),
            'close'                 => __( 'Close', 'bookly-responsive-appointment-booking-tool' ),
            'moreSlots'             => __( 'More slots', 'bookly-responsive-appointment-booking-tool' ),
            'datePicker'            => Lib\Utils\DateTime::datePickerOptions(),
            'noSlotsOnDay'          => __( 'No free slots on %1$s — showing from %2$s.', 'bookly-responsive-appointment-booking-tool' ),
            'notificationsSent'     => __( 'Notifications sent', 'bookly-responsive-appointment-booking-tool' ),
            'noNotificationsSent'   => __( 'No notifications were sent', 'bookly-responsive-appointment-booking-tool' ),
            'smsHint'               => __( 'Customers open texts far more often than emails — SMS reminders help reduce no-shows.', 'bookly-responsive-appointment-booking-tool' ),
            'smsHintLink'           => __( 'Set up SMS notifications', 'bookly-responsive-appointment-booking-tool' ),
            'subtotal'              => __( 'Subtotal', 'bookly-responsive-appointment-booking-tool' ),
            'tax'                   => __( 'Tax', 'bookly-responsive-appointment-booking-tool' ),
            'creating'              => __( 'Creating', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'createAppointment'     => __( 'Create appointment', 'bookly-responsive-appointment-booking-tool' ),
            'createAppointmentsN'   => __( 'Create appointments (%s)', 'bookly-responsive-appointment-booking-tool' ),
            'bookSlot'              => __( 'Book %1$s · %2$s', 'bookly-responsive-appointment-booking-tool' ),
            'now'                   => _x( 'now', 'label before the current appointment time being rescheduled', 'bookly-responsive-appointment-booking-tool' ),
            // Start-date chips.
            'today'                 => __( 'Today', 'bookly-responsive-appointment-booking-tool' ),
            'tomorrow'              => __( 'Tomorrow', 'bookly-responsive-appointment-booking-tool' ),
            'in2days'               => __( 'In 2 days', 'bookly-responsive-appointment-booking-tool' ),
            'nextWeek'              => __( 'Next week', 'bookly-responsive-appointment-booking-tool' ),
            'in2weeks'              => __( 'In 2 weeks', 'bookly-responsive-appointment-booking-tool' ),
            'inAMonth'              => __( 'In a month', 'bookly-responsive-appointment-booking-tool' ),
            'fromDate'              => __( 'From date', 'bookly-responsive-appointment-booking-tool' ) . '…',
            // Time-of-day chips.
            'anyTime'               => __( 'Any time', 'bookly-responsive-appointment-booking-tool' ),
            'morning'               => __( 'Morning', 'bookly-responsive-appointment-booking-tool' ),
            'afternoon'             => __( 'Afternoon', 'bookly-responsive-appointment-booking-tool' ),
            'evening'               => __( 'Evening', 'bookly-responsive-appointment-booking-tool' ),
            'night'                 => __( 'Night', 'bookly-responsive-appointment-booking-tool' ),
            'customRange'           => __( 'Custom', 'bookly-responsive-appointment-booking-tool' ) . '…',
            // An appointment can be stored without a time at all; where the wizard shows
            // when an existing appointment is, that is the answer.
            'noTime'                => __( 'No time', 'bookly-responsive-appointment-booking-tool' ),
            // Same screen as rescheduling, different promise: an appointment without a
            // time is not moved anywhere, it is given one.
            'setTime'               => __( 'Set time', 'bookly-responsive-appointment-booking-tool' ),
            'setTimeTitle'          => __( 'Set appointment time', 'bookly-responsive-appointment-booking-tool' ),
            'savingTime'            => __( 'Saving', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'timeSet'               => __( 'Appointment time set.', 'bookly-responsive-appointment-booking-tool' ),
            'timeSetFor'            => __( 'Time set for %s.', 'bookly-responsive-appointment-booking-tool' ),
            // Buttons shared by the wizard's screens. Words that belong to a feature —
            // repeating, tasks — come from the add-on that brings the feature.
            'change'                => __( 'Change', 'bookly-responsive-appointment-booking-tool' ),
            'apply'                 => __( 'Apply', 'bookly-responsive-appointment-booking-tool' ),
            'cancel'                => __( 'Cancel', 'bookly-responsive-appointment-booking-tool' ),
            'days'                  => __( 'days', 'bookly-responsive-appointment-booking-tool' ),
            'saving'                => __( 'Saving', 'bookly-responsive-appointment-booking-tool' ) . '…',
            // Booking details of the picked customer: the status and the custom fields.
            'editBookingDetails'    => __( 'Edit booking details', 'bookly-responsive-appointment-booking-tool' ),
            'status'                => __( 'Status', 'bookly-responsive-appointment-booking-tool' ),
            'customFields'          => __( 'Custom fields', 'bookly-responsive-appointment-booking-tool' ),
            'timezone'              => __( 'Timezone', 'bookly-responsive-appointment-booking-tool' ),
            'selectCity'            => __( 'Select a city', 'bookly-responsive-appointment-booking-tool' ),
            'remove'                => __( 'Remove', 'bookly-responsive-appointment-booking-tool' ),
            'download'              => __( 'Download', 'bookly-responsive-appointment-booking-tool' ),
            // Save/error messages.
            'groupMoveNote'         => __( 'Only this customer will be moved — the rest of the group keeps its time.', 'bookly-responsive-appointment-booking-tool' ),
            'errTimeTaken'          => __( 'This time was just taken. Pick another slot.', 'bookly-responsive-appointment-booking-tool' ),
            'errReschedule'         => __( 'Failed to reschedule. Please try again.', 'bookly-responsive-appointment-booking-tool' ),
            'errStagesMismatch'     => __( 'The selected time slot has a different set of stages. Pick another time.', 'bookly-responsive-appointment-booking-tool' ),
            'errCascadePartial'     => __( 'Only some stages were moved. Check the times of the other stages in the appointment form.', 'bookly-responsive-appointment-booking-tool' ),
            'errNoDuration'         => __( 'This appointment has no duration — set its time in the appointment form.', 'bookly-responsive-appointment-booking-tool' ),
            'errSlotsTaken'         => __( 'Some time slots were taken while you were choosing. Remove the highlighted items and pick another time.', 'bookly-responsive-appointment-booking-tool' ),
            'errCreate'             => __( 'Failed to create appointments. Please try again.', 'bookly-responsive-appointment-booking-tool' ),
        );

        return Proxy\Shared::prepareL10n( $l10n );
    }

    /**
     * Get catalog data for the booking wizard: categories with services,
     * staff providing each service (with base prices), locations.
     */
    public static function getBookingWizardData()
    {
        $result = array(
            'categories' => array(),
            'locations' => array(),
            'slot_length' => Lib\Config::getTimeSlotLength(),
            // Форматы отображения — из настроек WP/Bookly, как во всех модулях.
            'format' => array(
                'price' => Lib\Utils\Price::formatOptions(),
                'moment_date' => Lib\Utils\DateTime::convertFormat( 'date', Lib\Utils\DateTime::FORMAT_MOMENT_JS ),
                'moment_time' => Lib\Utils\DateTime::convertFormat( 'time', Lib\Utils\DateTime::FORMAT_MOMENT_JS ),
            ),
            // Taxes: the payment total is authoritative (built from CartInfo on save);
            // this only lets the visit panel show an approximate tax line so the shown
            // total does not surprise the operator. Per-service rate is attached below.
            'taxes' => array(
                'active' => (bool) Lib\Config::taxesActive(),
                'included' => get_option( 'bookly_taxes_in_price' ) != 'excluded',
            ),
        );
        // The "notify" switch remembers its last state per user — same meta as the
        // classic appointment form. Null (never used yet) keeps the client default.
        $meta_notify = get_user_meta( get_current_user_id(), 'bookly_appointment_form_send_notifications', true );
        $result['notify_default'] = $meta_notify === '' ? null : (int) $meta_notify;
        // Effective tax rate per service (summed rate; empty when the add-on is inactive).
        $tax_rates = (array) Lib\Proxy\Taxes::getServiceTaxRates();

        // Customer picker: preload the list on small bases, switch the selector to
        // remote search (bookly_get_customers_list) on large ones — same rule as
        // the appointments filter.
        $customers_remote = Lib\Entities\Customer::query()->count() >= Lib\Entities\Customer::REMOTE_LIMIT;
        $result['customers'] = $customers_remote
            ? array()
            : Lib\Entities\Customer::query( 'c' )
                ->select( 'c.id, c.full_name, c.email, c.phone' )
                ->addSelect( Lib\Config::customerGroupsActive() ? 'c.group_id' : '0 AS group_id' )
                ->sortBy( 'c.full_name' )
                ->fetchArray();
        $result['customers_remote'] = $customers_remote;

        // Booking details the operator may set for the picked customer: the status and the
        // custom fields. Left alone, the booking gets the status the customer's group gives
        // it — the default the cart applies — so the default is sent per group.
        $result['statuses'] = array();
        foreach ( Lib\Entities\CustomerAppointment::getStatuses() as $status ) {
            $result['statuses'][] = array( 'id' => $status, 'title' => Lib\Entities\CustomerAppointment::statusToString( $status ) );
        }
        $result['default_statuses'] = Lib\Proxy\CustomerGroups::prepareDefaultAppointmentStatuses( array( 0 => Lib\Config::getDefaultAppointmentStatus() ) );
        $result['custom_fields'] = array();
        $result['custom_fields_per_service'] = false;
        if ( Lib\Config::customFieldsActive() ) {
            // A file field needs the Files add-on to upload into — the same filter the
            // booking details dialog applies.
            $files_active = Lib\Config::filesActive();
            foreach ( (array) Lib\Proxy\CustomFields::getWhichHaveData() as $field ) {
                if ( $files_active || $field->type !== 'file' ) {
                    $result['custom_fields'][] = $field;
                }
            }
            $result['custom_fields_per_service'] = Lib\Config::customFieldsPerService();
        }
        // The customer's time zone is a Pro feature: notifications show the customer the
        // time in it. A flat list of zone values, as the booking details dialog offers them.
        $result['timezones'] = array();
        if ( Lib\Config::proActive() ) {
            foreach ( Lib\Utils\DateTime::getTimeZoneOptions() as $zones ) {
                foreach ( array_keys( $zones ) as $zone ) {
                    $result['timezones'][] = $zone;
                }
            }
        }

        // Add-on–gated service types: hide compound/collaborative when their add-ons are
        // inactive (e.g. Pro disabled) — otherwise the wizard offers unbookable services.
        $service_types = array( Service::TYPE_SIMPLE );
        if ( Lib\Config::compoundServicesActive() ) {
            $service_types[] = Service::TYPE_COMPOUND;
        }
        if ( Lib\Config::collaborativeServicesActive() ) {
            $service_types[] = Service::TYPE_COLLABORATIVE;
        }
        // Variable duration (units) is the Custom Duration add-on — no units without it.
        $custom_duration_active = Lib\Config::customDurationActive();

        $services = array();
        $rows = Service::query( 's' )
            ->select( 's.id, s.category_id, s.type, s.title, s.duration, s.price, s.units_min, s.units_max' )
            ->whereIn( 's.type', $service_types )
            ->sortBy( 's.position' )
            ->fetchArray();
        foreach ( $rows as $row ) {
            $units_min = $custom_duration_active ? max( 1, (int) $row['units_min'] ) : 1;
            $units_max = $custom_duration_active ? max( $units_min, (int) $row['units_max'] ) : 1;
            $service = array(
                'id' => (int) $row['id'],
                'category_id' => (int) $row['category_id'],
                'type' => $row['type'],
                'title' => $row['title'],
                'duration' => (int) $row['duration'],
                'price' => (float) $row['price'],
                'capacity_min' => 1,
                'capacity_max' => 1,
                'units_min' => $units_min,
                'units_max' => $units_max,
                'tax_rate' => isset( $tax_rates[ $row['id'] ] ) ? (float) $tax_rates[ $row['id'] ] : 0.0,
                'staff' => array(),
                'stages' => array(),
            );
            // Variable duration (units): pre-format the option labels server-side so
            // the client does not need its own seconds-to-interval logic. A fixed
            // multiplier (min == max > 1) still needs its single option — the client
            // takes the effective duration text from here.
            if ( $units_max > 1 && $row['type'] === Service::TYPE_SIMPLE ) {
                $service['units_options'] = array();
                for ( $n = $units_min; $n <= $units_max; $n++ ) {
                    $service['units_options'][] = array(
                        'units' => $n,
                        'text' => Lib\Utils\DateTime::secondsToInterval( $n * $service['duration'] ),
                    );
                }
            }
            $services[ $row['id'] ] = $service;
        }
        if ( ! $services ) {
            wp_send_json_success( $result );
        }

        // Sub-services (compound/collaborative): stages + providers are derived from them.
        $sub_map = array(); // service_id => [sub_service_id, ...]
        $sub_rows = SubService::query( 'ss' )
            ->select( 'ss.service_id, ss.sub_service_id, sv.title, sv.duration' )
            ->leftJoin( 'Service', 'sv', 'sv.id = ss.sub_service_id' )
            ->where( 'ss.type', SubService::TYPE_SERVICE )
            ->whereIn( 'ss.service_id', array_keys( $services ) )
            ->sortBy( 'ss.position' )
            ->fetchArray();
        foreach ( $sub_rows as $row ) {
            $service_id = (int) $row['service_id'];
            $sub_map[ $service_id ][] = (int) $row['sub_service_id'];
            $services[ $service_id ]['stages'][] = array(
                'id' => (int) $row['sub_service_id'],
                'title' => $row['title'],
                'duration' => (int) $row['duration'],
            );
        }
        // Multi-stage duration: compound = sum of stages, collaborative = longest stage.
        foreach ( $services as $id => $service ) {
            if ( $service['stages'] ) {
                $durations = array_map( function ( $st ) { return $st['duration']; }, $service['stages'] );
                $services[ $id ]['duration'] = $service['type'] === Service::TYPE_COLLABORATIVE
                    ? max( $durations )
                    : array_sum( $durations );
                foreach ( $services[ $id ]['stages'] as $i => $stage ) {
                    $services[ $id ]['stages'][ $i ]['duration_text'] = Lib\Utils\DateTime::secondsToInterval( $stage['duration'] );
                }
            }
            $services[ $id ]['duration_text'] = Lib\Utils\DateTime::secondsToInterval( $services[ $id ]['duration'] );
        }

        // Providers: direct staff_services for simple, union over sub-services for multi-stage.
        $lookup_ids = array_keys( $services );
        foreach ( $sub_map as $sub_ids ) {
            $lookup_ids = array_merge( $lookup_ids, $sub_ids );
        }
        $staff_rows = StaffService::query( 'ss' )
            ->select( 'ss.service_id, ss.staff_id, ss.location_id, ss.price, st.full_name' )
            ->addSelect( sprintf( '%s AS capacity_min, %s AS capacity_max',
                Lib\Proxy\Shared::prepareStatement( 1, 'ss.capacity_min', 'StaffService' ),
                Lib\Proxy\Shared::prepareStatement( 1, 'ss.capacity_max', 'StaffService' )
            ) )
            ->leftJoin( 'Staff', 'st', 'st.id = ss.staff_id' )
            ->whereIn( 'ss.service_id', array_unique( $lookup_ids ) )
            ->whereNot( 'st.visibility', 'archive' )
            ->fetchArray();
        $providers = array(); // service_id => [staff_id => ['id' =>, 'name' =>, 'price' =>]]
        foreach ( $staff_rows as $row ) {
            // Base rows only (location NULL): with "custom settings for location" the
            // table also holds per-location duplicates — they must not clobber the base
            // price/capacity here. Location-specific figures are attached separately by
            // the Locations add-on proxy (prepareCatalog) and applied client-side when
            // a location is picked.
            if ( $row['location_id'] ) {
                continue;
            }
            $providers[ (int) $row['service_id'] ][ (int) $row['staff_id'] ] = array(
                'id' => (int) $row['staff_id'],
                'name' => $row['full_name'],
                'price' => (float) $row['price'],
                'capacity_min' => (int) $row['capacity_min'],
                'capacity_max' => (int) $row['capacity_max'],
            );
        }
        // Permission scope: a plain staff member only sees themselves as a provider,
        // so a service they do not provide drops out of the catalog entirely (below,
        // a service with no staff is not added to any category).
        $allowed_staff = self::allowedStaffIds();
        foreach ( $services as $id => $service ) {
            $own = array();
            if ( isset( $sub_map[ $id ] ) ) {
                foreach ( $sub_map[ $id ] as $sub_id ) {
                    $own += isset( $providers[ $sub_id ] ) ? $providers[ $sub_id ] : array();
                }
            } else {
                $own = isset( $providers[ $id ] ) ? $providers[ $id ] : array();
            }
            // Aggregation as in the frontend catalog: MIN(capacity_min) / MAX(capacity_max).
            $capacity_min = null;
            foreach ( $own as $staff ) {
                if ( $allowed_staff !== null && ! in_array( $staff['id'], $allowed_staff, true ) ) {
                    continue;
                }
                // Per-staff capacity: the persons control narrows to the chosen staff
                // member's own range (the engine excludes staff with a lower capacity
                // from the search results anyway, so a wider list would offer numbers
                // that silently drop the chosen staff member).
                $services[ $id ]['staff'][] = array(
                    'id' => $staff['id'],
                    'name' => $staff['name'],
                    'price' => $staff['price'],
                    'capacity_min' => $staff['capacity_min'],
                    'capacity_max' => $staff['capacity_max'],
                );
                $services[ $id ]['capacity_max'] = max( $services[ $id ]['capacity_max'], $staff['capacity_max'] );
                $capacity_min = $capacity_min === null ? $staff['capacity_min'] : min( $capacity_min, $staff['capacity_min'] );
            }
            $services[ $id ]['capacity_min'] = max( 1, (int) $capacity_min );
        }

        // Service extras (when the add-on is active): own extras for simple services,
        // union over sub-services for multi-stage — mirrors how the engine distributes
        // chosen extras across sub-services.
        $result['extras_consider_duration'] = (bool) Lib\Proxy\ServiceExtras::considerDuration();
        // When extras do NOT affect duration, the client prices them itself and the
        // slot search skips the extras parameter entirely (identical slots for any
        // extras choice — no refetch). The multiply flag mirrors getTotalPrice().
        $result['extras_multiply_nop'] = (bool) get_option( 'bookly_service_extras_multiply_nop', 1 );
        $extras_cache = array(); // stage service_id => extras[]
        $stage_extras = function ( $stage_id ) use ( &$extras_cache ) {
            if ( ! isset ( $extras_cache[ $stage_id ] ) ) {
                $extras_cache[ $stage_id ] = array();
                foreach ( (array) Lib\Proxy\ServiceExtras::findByServiceId( $stage_id ) as $extra ) {
                    $extras_cache[ $stage_id ][] = array(
                        'id' => (int) $extra->getId(),
                        'title' => $extra->getTitle(),
                        'price' => (float) $extra->getPrice(),
                        'duration' => (int) $extra->getDuration(),
                        'duration_text' => $extra->getDuration() ? Lib\Utils\DateTime::secondsToInterval( $extra->getDuration() ) : '',
                        // min_quantity > 0 makes the extra mandatory: preselected, can't go below.
                        'min_quantity' => max( 0, (int) $extra->getMinQuantity() ),
                        'max_quantity' => max( 1, (int) $extra->getMaxQuantity() ),
                    );
                }
            }

            return $extras_cache[ $stage_id ];
        };
        foreach ( $services as $id => $service ) {
            // Dedup by extra id: a compound can repeat the same sub-service (→ same extras)
            // several times; each extra must appear once. The engine assigns a chosen extra
            // to a single occurrence anyway (distributeExtrasAcrossSubServices unsets it after
            // the first match), same as the standard forms.
            $extras = array();
            foreach ( isset ( $sub_map[ $id ] ) ? $sub_map[ $id ] : array( $id ) as $stage_id ) {
                foreach ( $stage_extras( $stage_id ) as $extra ) {
                    $extras[ $extra['id'] ] = $extra;
                }
            }
            if ( $extras ) {
                $services[ $id ]['extras'] = array_values( $extras );
            }
        }

        // Group services by category, keep categories/services order by position.
        $categories = array();
        foreach ( Category::query( 'c' )->sortBy( 'c.position' )->fetchArray() as $row ) {
            $categories[ (int) $row['id'] ] = array(
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'services' => array(),
            );
        }
        $categories[0] = array( 'id' => 0, 'name' => __( 'Uncategorized', 'bookly-responsive-appointment-booking-tool' ), 'services' => array() );
        foreach ( $services as $service ) {
            if ( $service['staff'] ) {
                $categories[ isset( $categories[ $service['category_id'] ] ) ? $service['category_id'] : 0 ]['services'][] = $service;
            }
        }
        foreach ( $categories as $category ) {
            if ( $category['services'] ) {
                $result['categories'][] = $category;
            }
        }

        // Locations add-on: no location control when it is inactive.
        $locations = Lib\Config::locationsActive() ? ( Lib\Proxy\Locations::getAll() ?: array() ) : array();
        foreach ( $locations as $location ) {
            $result['locations'][] = $location instanceof \Bookly\Lib\Base\Entity || is_object( $location )
                ? array( 'id' => (int) $location->getId(), 'name' => $location->getName() )
                : array( 'id' => (int) $location['id'], 'name' => $location['name'] );
        }
        // With "custom settings for location" the engine does not support a search
        // without a location (same as the frontend, where one is always selected) —
        // the client then drops "All locations" and preselects a concrete one.
        $result['locations_required'] = $result['locations'] && (bool) Lib\Proxy\Locations::servicesPerLocationAllowed();

        $result = Proxy\Shared::prepareCatalog( $result );

        wp_send_json_success( $result );
    }

    /**
     * Find available slots for the booking wizard.
     *
     * The response unit is the slot resolved by the engine (one per timestamp)
     * with the full alternatives chain serialized — every staff member available
     * at that time with the price adjusted for the slot time (special hours).
     */
    public static function getBookingWizardSlots()
    {
        $result = self::findSlots( array(
            // Display time zone. A staff member may have a personal time zone (staff
            // Advanced settings) and the rest of the backend shows it to them (calendar,
            // classic dialog). For admins/supervisors this is the WP time zone.
            'client_time_zone'    => Lib\Utils\Common::getCurrentUserTimeZone(),
            'service_id'          => (int) self::parameter( 'service_id' ),
            'staff_ids'           => array_map( 'intval', (array) self::parameter( 'staff_ids', array() ) ),
            'location_id'         => (int) self::parameter( 'location_id', 0 ) ?: null,
            'nop'                 => max( 1, (int) self::parameter( 'nop', 1 ) ),
            'days'                => min( 7, max( 1, (int) self::parameter( 'days', 3 ) ) ),
            'start_date'          => self::parameter( 'start_date' ) ?: current_time( 'Y-m-d' ),
            'custom_duration'     => (int) self::parameter( 'custom_duration', 0 ),
            'ignore_appointments' => array_map( 'intval', (array) self::parameter( 'ignore_appointments', array() ) ),
            'units'               => (int) self::parameter( 'units', 1 ),
            'extras'              => (array) json_decode( self::parameter( 'extras', '' ), true ),
            'order'               => json_decode( self::parameter( 'order', '[]' ), true ),
            'allowed_staff'       => self::allowedStaffIds(),
        ) );
        if ( $result === null ) {
            wp_send_json_error();
        }

        wp_send_json_success( $result );
    }

    /**
     * Slot search of the wizard, without reading the request: what getBookingWizardSlots()
     * answers, or null when the service is not found.
     *
     * $params: client_time_zone, service_id, staff_ids, location_id, nop, days, start_date
     * (Y-m-d), custom_duration (seconds, 0 — a service), ignore_appointments, units, extras
     * ({extra_id: quantity}), order (items already picked into the order), allowed_staff (int[]
     * or null for no restriction).
     *
     * @param array $params
     * @return array|null array( 'days', 'next_start', 'timed_out' )
     */
    protected static function findSlots( array $params )
    {
        // Slot VALUES stay in WP time zone — the product-wide contract (the frontend keeps
        // values in WP tz even with a visitor tz); the engine's client-tz mode only drives
        // labels, day grouping and window parsing.
        DatePoint::$client_timezone = $params['client_time_zone'];

        $service_id = $params['service_id'];
        $staff_ids = $params['staff_ids'];
        $location_id = $params['location_id'];
        $nop = $params['nop'];
        $days = $params['days'];
        $start_date = $params['start_date'];
        // Custom service: only the duration matters for the search; name and price are
        // client-side input (the engine returns price 0, the client shows the entered one).
        $custom_duration = $params['custom_duration'];
        // Reschedule mode: the appointment being moved must not block its own slots.
        $ignore_appointments = $params['ignore_appointments'];
        self::$require_unjoined = (bool) $ignore_appointments;

        $units = 1;
        $extras = array();
        $extras_price = 0.0;
        $base_prices = array();
        // Fixed compound/collaborative price: with the default combined price method
        // ('regular') a multi-stage service is priced by the PARENT service price
        // (staff-independent), NOT the sum of stage staff prices — same as the cart
        // (CartItem::getServicePriceWithoutExtras). Only 'nested' sums the stages.
        $flat_service_price = null;

        if ( $custom_duration > 0 ) {
            // Custom service (service_id = null): any non-archived staff member, synthetic
            // unsaved Service entity carries the duration into the engine.
            if ( ! $staff_ids ) {
                $staff_ids = array_map( 'intval', Lib\Entities\Staff::query()->whereNot( 'visibility', 'archive' )->fetchCol( 'id' ) );
            }
            if ( ! $staff_ids ) {
                return array( 'days' => array(), 'next_start' => null );
            }
            $synthetic = new Service();
            $synthetic
                ->setDuration( min( $custom_duration, DAY_IN_SECONDS ) )
                ->setPaddingLeft( 0 )
                ->setPaddingRight( 0 );
            $chain_item = new CustomServiceChainItem();
            $chain_item->setCustomService( $synthetic );
            $service_id = null;
        } else {
            $service = Service::find( $service_id );
            if ( ! $service ) {
                return null;
            }

            // Variable duration (units) applies to simple services only; the value is
            // clamped to the service bounds. Slot length and price scale by units.
            if ( $service->getType() === Service::TYPE_SIMPLE ) {
                $units = min( max( (int) $params['units'], $service->getUnitsMin() ), $service->getUnitsMax() );
            }

            // Service extras: map {extra_id: quantity}. The engine takes care of the
            // duration (when the consider-duration option is on); the price is added below.
            foreach ( $params['extras'] as $extra_id => $qty ) {
                if ( (int) $qty > 0 ) {
                    $extras[ (int) $extra_id ] = (int) $qty;
                }
            }
            $extras_price = $extras ? (float) Lib\Proxy\ServiceExtras::getTotalPrice( $extras, $nop ) : 0.0;

            // Stage services: the service itself for simple, sub-services for compound/collaborative.
            $stage_ids = SubService::query( 'ss' )
                ->where( 'ss.type', SubService::TYPE_SERVICE )
                ->where( 'ss.service_id', $service_id )
                ->sortBy( 'ss.position' )
                ->fetchCol( 'sub_service_id' );
            $stage_ids = $stage_ids ? array_map( 'intval', $stage_ids ) : array( $service_id );

            // Base prices per (stage service, staff, location) — special hours applied
            // per leg below. With "custom settings for location" the staff_services
            // table holds extra per-location rows; the price is resolved per leg by the
            // leg's own location (resolveLegPrice), the same way the cart prices a slot.
            $provider_rows = StaffService::query( 'ss' )
                ->select( 'ss.service_id, ss.staff_id, ss.location_id, ss.price' )
                ->whereIn( 'ss.service_id', $stage_ids )
                ->fetchArray();
            $base_prices = array(); // service_id => staff_id => location_id (0 = base) => price
            foreach ( $provider_rows as $row ) {
                $base_prices[ (int) $row['service_id'] ][ (int) $row['staff_id'] ][ $row['location_id'] ? (int) $row['location_id'] : 0 ] = (float) $row['price'];
            }

            // Multi-stage with a non-nested price method → flat parent price for the slot.
            if ( $service->withSubServices() && get_option( 'bookly_combined_price_method' ) !== 'nested' ) {
                $flat_service_price = (float) $service->getPrice();
            }
            if ( ! $staff_ids ) {
                $staff_ids = array();
                foreach ( $base_prices as $stage_prices ) {
                    $staff_ids = array_merge( $staff_ids, array_keys( $stage_prices ) );
                }
                $staff_ids = array_values( array_unique( $staff_ids ) );
            }
            if ( ! $staff_ids ) {
                return array( 'days' => array(), 'next_start' => null );
            }

            $chain_item = new Lib\ChainItem();
        }

        // Permission scope: confine the search to the staff the current user may act
        // on (covers both the explicit staff_ids and the "any staff" fallbacks above).
        $allowed_staff = $params['allowed_staff'];
        if ( $allowed_staff !== null ) {
            $staff_ids = array_values( array_intersect( $staff_ids, $allowed_staff ) );
            if ( ! $staff_ids ) {
                return array( 'days' => array(), 'next_start' => null );
            }
        }

        $chain_item
            ->setStaffIds( $staff_ids )
            ->setServiceId( $service_id )
            ->setNumberOfPersons( $nop )
            ->setQuantity( 1 )
            ->setUnits( $units )
            ->setExtras( $extras )
            ->setLocationId( $location_id );
        $chain = new Lib\Chain();
        $chain->add( $chain_item );

        $userData = new Lib\UserBookingData( null );
        $userData->resetChain();
        $userData->chain = $chain;
        $userData->setDays( array( 1, 2, 3, 4, 5, 6, 7 ) );
        $userData->setDateFrom( $start_date );

        // Items already picked into the order occupy their slots in this search —
        // the operator composes the next position around the ones chosen so far.
        $order_items = $params['order'];
        $custom_bookings = is_array( $order_items ) && $order_items
            ? self::applyOrderItems( $userData, $order_items )
            : array();

        // Stop-callback bookkeeping: count days that actually have a FREE slot. Fully booked
        // days are still collected (for the "show booked" toggle) but must not count toward
        // the requested number of days — otherwise a booked-solid stretch would end the scan
        // before reaching the real availability behind it. Each completed group is inspected
        // once (incremental cursor), so this stays cheap over a long scan.
        $stop_avail_days = 0;
        $stop_cursor = 0;
        $finder = null;
        $finder = new Finder(
            $userData,
            function ( DatePoint $client_dp ) { return $client_dp->format( 'Y-m-d' ); },
            function ( DatePoint $client_dp, $groups_count, $slots_count, $available_slots_count ) use ( $days, &$finder, &$stop_avail_days, &$stop_cursor ) {
                $collected = $finder->getSlots();
                $keys = array_keys( $collected );
                for ( $n = count( $keys ); $stop_cursor < $n; $stop_cursor++ ) {
                    foreach ( $collected[ $keys[ $stop_cursor ] ] as $slot ) {
                        if ( ! self::slotBusy( $slot ) ) { // free ⟺ !busy, same rule as the serializer
                            $stop_avail_days++;
                            break;
                        }
                    }
                }
                return $stop_avail_days >= $days ? 2 : 0;
            },
            // Waiting list: null = engine default (add-on active + option enabled). Slots
            // whose matching booking is full then come back as WAITING_LIST_STARTED — an
            // actionable "join the queue" state, distinct from overlap-blocked FULLY_BOOKED.
            // A waiting-list slot counts as available for the stop counter above: for the
            // operator it IS something to offer, and a booked-solid business with the
            // waiting list on would otherwise hit the Phase 2 timeout on every request.
            null,
            $ignore_appointments,
            // Blocked slots are always requested; hiding them is a client-side toggle,
            // so the request cache key stays the same when the toggle flips.
            true
        );
        // Every staff member with their own state, not only the best one per time: a free
        // colleague must not hide the waiting list or a double booking of the staff member
        // the operator asked for.
        $finder->keepAllCandidates();
        $finder->prepare();
        foreach ( $custom_bookings as $custom_booking ) {
            $finder->addStaffBooking( $custom_booking[0], $custom_booking[1] );
        }
        // The requested window always starts at start_date regardless of frontend
        // calendar/time-step options _prepareDates derives its bounds from.
        // When the window starts today, the lower bound is "now" — earlier slots of the
        // day are in the past. No min-time-prior-booking here: admins are not limited
        // by the client-side booking lead time.
        $window_start = DatePoint::fromStrInClientTz( $start_date );
        $now = DatePoint::now()->toClientTz();
        if ( $now->gt( $window_start ) ) {
            $window_start = $now;
        }
        // ONE forward scan over the whole horizon; the break callback drives both phases:
        //   Phase 1 — the first SLOT_NEAR_WINDOW_DAYS days are always scanned in full, like
        //             the plain window search (no time limit — the near window is cheap).
        //   Phase 2 — past the near window the scan keeps going but is bounded by a time
        //             budget: seasonal / far availability is still found (empty days cost
        //             nothing to skip), while a booked-solid business bails out at the
        //             timeout instead of scanning all the way to the horizon.
        // The stop callback (above) ends the scan as soon as `days` available days are
        // collected, so the common case (availability soon) reaches neither bound.
        // Near window is measured from the effective start (window_start is clamped to now),
        // so a start_date in the past can't collapse the immune window below the horizon.
        $near_end = $window_start->modify( '+' . self::SLOT_NEAR_WINDOW_DAYS . ' days' );
        $horizon_end = DatePoint::now()->toClientTz()->modify( '+' . (int) Lib\Config::getMaximumAvailableDaysForBooking() . ' days' );
        if ( $horizon_end->lt( $near_end ) ) {
            $horizon_end = $near_end; // tiny booking horizon — never cut below the near window
        }
        $finder->client_start_dp = $window_start;
        $finder->start_dp = $window_start->toWpTz();
        $finder->client_end_dp = $horizon_end;
        $finder->end_dp = $horizon_end->toWpTz();

        // Break callback: hard-stop at the booking horizon, and — only once past the near
        // window — at the time budget. Called per scanned date-point, so a long run of fully
        // booked days is bounded by the timeout. $last_scanned records how far the scan
        // reached (the "More days" resume point when it stops on the timeout).
        $deadline = microtime( true ) + self::SLOT_OPEN_TIMEOUT;
        $timed_out = false;
        $last_scanned = null;
        $break_cb = function ( DatePoint $dp, $srv_days, $cnt, $av ) use ( &$timed_out, &$last_scanned, $near_end, $horizon_end, $deadline ) {
            $last_scanned = $dp;
            // A multi-day service occupies srv_days-1 days before $dp — bound on that edge.
            $back = $dp->modify( -( $srv_days > 1 ? $srv_days - 1 : 0 ) . ' days' );
            if ( $back->gte( $horizon_end ) ) {
                return true;
            }
            if ( $back->gte( $near_end ) && microtime( true ) > $deadline ) {
                $timed_out = true;
                return true;
            }
            return false;
        };

        $finder->load( $break_cb );
        $slots_by_day = $finder->getSlots();

        // The requested `days` means days with AVAILABLE slots — fully booked days found on
        // the way are included in the response (for the "show booked" toggle) but do not
        // satisfy the request.
        $result = array( 'days' => array(), 'next_start' => null, 'timed_out' => $timed_out );
        $available_days = 0;
        $last_day = null;

        // Special hours: собрать леги всех слотов и альтернатив и пересчитать цены ОДНИМ
        // запросом (adjustPrices грузит special hours раз, матчит в памяти) вместо запроса
        // на каждый лег. base-цены — фолбэк при неактивном аддоне. При флэт-цене
        // мультистейджа per-лег цены не нужны — карта не строится.
        $price_map = array();
        if ( $flat_service_price === null ) {
            $price_items = array();
            foreach ( $slots_by_day as $group_slots ) {
                foreach ( $group_slots as $slot ) {
                    self::collectLegPrices( $slot, $service_id, $location_id, $base_prices, $price_items );
                    $s = $slot;
                    $guard = 0;
                    while ( $s->hasAltSlot() && $guard++ < 500 ) {
                        $s = $s->altSlot();
                        self::collectLegPrices( $s, $service_id, $location_id, $base_prices, $price_items );
                    }
                }
            }
            $adjusted = Lib\Config::specialHoursActive() ? Lib\Proxy\SpecialHours::adjustPrices( $price_items ) : array();
            foreach ( $price_items as $key => $it ) {
                $price_map[ $key ] = ( isset( $adjusted[ $key ] ) && is_numeric( $adjusted[ $key ] ) )
                    ? (float) $adjusted[ $key ]
                    : (float) $it['price']; // аддон неактивен/нет строки — базовая цена
            }
        }

        foreach ( $slots_by_day as $group => $group_slots ) {
            $day = array( 'date' => $group, 'slots' => array() );
            $day_has_available = false;
            /** @var Lib\Slots\Range $slot */
            foreach ( $group_slots as $slot ) {
                $data = self::serializeSlot( $slot, $service_id, $location_id, $price_map, $units, $extras_price, $flat_service_price, $nop );
                $alt = array();
                $s = $slot;
                $guard = 0;
                while ( $s->hasAltSlot() && $guard++ < 500 ) {
                    $s = $s->altSlot();
                    $alt[] = self::serializeSlot( $s, $service_id, $location_id, $price_map, $units, $extras_price, $flat_service_price, $nop );
                }
                $data['alt'] = $alt;
                $day['slots'][] = $data;
                if ( ! $data['busy'] ) {
                    $day_has_available = true;
                }
            }
            $result['days'][] = $day;
            $last_day = $group;
            if ( $day_has_available ) {
                $available_days++;
            }
        }

        if ( $available_days >= $days && $last_day ) {
            // Page filled → the next page continues right after the last collected day.
            $result['next_start'] = date( 'Y-m-d', strtotime( $last_day . ' +1 day' ) );
        } elseif ( $timed_out ) {
            // Stopped on the time budget → resume from where the scan actually reached.
            $resume = $last_day ? strtotime( $last_day . ' +1 day' )
                : ( $last_scanned ? strtotime( $last_scanned->toClientTz()->format( 'Y-m-d' ) ) : null );
            $result['next_start'] = $resume ? date( 'Y-m-d', $resume ) : null;
        } else {
            // Scanned to the horizon without filling the page → no further pages.
            $result['next_start'] = null;
        }
        // Auto-continuation stops at the booking horizon (the frontend limit); an explicit
        // start_date beyond it still works — admins are allowed to look further.
        $horizon = date( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' +' . Lib\Config::getMaximumAvailableDaysForBooking() . ' days' ) );
        if ( $result['next_start'] && $result['next_start'] > $horizon ) {
            $result['next_start'] = null;
        }

        return $result;
    }

    /**
     * Warnings for the order being composed: limits the plugin sets, which the operator is
     * told about but not stopped by.
     *
     * The classic appointment form does exactly that — it warns when a customer has used up
     * the bookings allowed for a service and when a staff member would work past their daily
     * limit, and still saves. An operator booking by phone may know better than the limit,
     * so the wizard warns in the same two cases and leaves the decision to them.
     *
     * The order is checked as a whole, not item by item: two items for the same staff member
     * on one day add up against their limit, and several visits of one service count against
     * the customer's limit together, just as they will once saved.
     *
     * Answers `warnings`: order item index → list of codes (`staff_time_limit`,
     * `customer_bookings_limit`); the words are the wizard's own.
     */
    public static function getBookingWizardWarnings()
    {
        $items = json_decode( self::parameter( 'items', '[]' ), true );
        $customer_id = (int) self::parameter( 'customer_id' );
        $warnings = array();
        if ( ! is_array( $items ) ) {
            wp_send_json_success( array( 'warnings' => (object) $warnings ) );
        }

        // A repeating item stands for every visit of its series: each one takes the staff
        // member's time and counts against the customer's limit. Expanded the same way the
        // save expands it, each visit keeping the index of the item it came from.
        $visits = array();
        foreach ( $items as $index => $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            foreach ( Proxy\Shared::expandOrderItem( array( $item ), $item ) as $visit ) {
                $visits[] = array( $index, $visit );
            }
        }

        $allowed_staff = self::allowedStaffIds();
        $consider_extras = (bool) Lib\Proxy\ServiceExtras::considerDuration();
        $workload = array();      // staff_id|day => seconds already asked for by earlier items
        $service_dates = array(); // service_id => array( datetime, … ) for the customer limit
        $service_items = array(); // service_id => item indexes
        foreach ( $visits as $pair ) {
            list( $index, $item ) = $pair;
            if ( empty( $item['datetime'] ) ) {
                continue;
            }
            $units = max( 1, (int) ( isset( $item['units'] ) ? $item['units'] : 1 ) );
            $extras_duration = $consider_extras
                ? (int) Lib\Proxy\ServiceExtras::getTotalDuration( (array) ( isset( $item['extras'] ) ? $item['extras'] : array() ) )
                : 0;
            $legs = isset( $item['legs'] ) && is_array( $item['legs'] ) && $item['legs']
                ? $item['legs']
                : array( array( 'service_id' => isset( $item['service_id'] ) ? $item['service_id'] : null, 'staff_id' => isset( $item['staff_id'] ) ? $item['staff_id'] : 0, 'datetime' => $item['datetime'] ) );

            foreach ( $legs as $leg_index => $leg ) {
                $staff_id = (int) ( isset( $leg['staff_id'] ) ? $leg['staff_id'] : 0 );
                if ( ! $staff_id || empty( $leg['datetime'] ) || ( $allowed_staff !== null && ! in_array( $staff_id, $allowed_staff, true ) ) ) {
                    continue;
                }
                $staff = Lib\Entities\Staff::find( $staff_id );
                if ( ! $staff ) {
                    continue;
                }
                if ( ! empty( $leg['service_id'] ) ) {
                    $leg_service = Lib\Entities\Service::find( (int) $leg['service_id'] );
                    $duration = $leg_service ? $leg_service->getDuration() * ( count( $legs ) > 1 ? 1 : $units ) : 0;
                } else {
                    $duration = isset( $item['custom']['duration'] ) ? (int) $item['custom']['duration'] : 0;
                }
                // Extras lengthen the visit once, not every stage of it.
                if ( $leg_index === 0 ) {
                    $duration += $extras_duration;
                }
                if ( $duration <= 0 ) {
                    continue;
                }
                $start = $leg['datetime'];
                $end = date( 'Y-m-d H:i:s', strtotime( $start ) + $duration );
                // The limit is a day of the staff member, so it is counted in their time zone.
                $staff_tz = $staff->getTimeZone();
                if ( $staff_tz ) {
                    $wp_tz = Lib\Config::getWPTimeZone();
                    $start = Lib\Utils\DateTime::convertTimeZone( $start, $wp_tz, $staff_tz );
                    $end = Lib\Utils\DateTime::convertTimeZone( $end, $wp_tz, $staff_tz );
                }
                $key = $staff_id . '|' . substr( $start, 0, 10 );
                $earlier = isset( $workload[ $key ] ) ? $workload[ $key ] : 0;
                if ( Lib\Proxy\Pro::getWorkingTimeLimitError( $staff, $start, $end, $earlier + $duration, null ) ) {
                    $warnings[ $index ][] = 'staff_time_limit';
                }
                $workload[ $key ] = $earlier + $duration;
            }

            if ( $customer_id && ! empty( $item['service_id'] ) ) {
                $service_id = (int) $item['service_id'];
                $service_dates[ $service_id ][] = $item['datetime'];
                $service_items[ $service_id ][] = $index;
            }
        }

        foreach ( $service_dates as $service_id => $dates ) {
            $service = Lib\Entities\Service::find( $service_id );
            if ( $service && $service->appointmentsLimitReached( $customer_id, $dates ) ) {
                foreach ( $service_items[ $service_id ] as $index ) {
                    $warnings[ $index ][] = 'customer_bookings_limit';
                }
            }
        }

        foreach ( $warnings as $index => $codes ) {
            $warnings[ $index ] = array_values( array_unique( $codes ) );
        }

        wp_send_json_success( array( 'warnings' => (object) $warnings ) );
    }

    /**
     * Save the wizard visit: one order for the picked customer.
     *
     * Availability is re-checked with the same engine right before saving — a slot
     * could be taken between the search and this call (another operator, a frontend
     * booking). On any conflict nothing is saved and the conflicting item indexes
     * are returned, so the operator re-picks and retries. Items explicitly picked
     * as busy (double booking) skip the check.
     *
     * Regular items go through the cart, which gives the compound/collaborative
     * cascade and joining partially filled group appointments — same as the
     * frontend forms. Custom-service items go through the canonical admin save.
     */
    public static function saveBookingWizardAppointments()
    {
        // Order without a customer — parity with the classic form (appointments with no
        // CAs): staff reserves the time for themselves (a break, an errand — usually a
        // custom service) or holds a slot to fill in the customer later. No cart/payment
        // (nothing to charge) and no notifications (nobody to notify): every item is
        // saved through the canonical admin path instead.
        $no_customer = (bool) self::parameter( 'no_customer' );
        $customer = $no_customer ? null : Lib\Entities\Customer::find( (int) self::parameter( 'customer_id' ) );
        if ( ! $customer && ! $no_customer ) {
            wp_send_json_error( array( 'error' => 'customer_required' ) );
        }
        $notify = ! $no_customer && (bool) self::parameter( 'notify' );
        $meta_notify_before = (bool) get_user_meta( get_current_user_id(), 'bookly_appointment_form_send_notifications', true );
        // One note per visit, stored on every created customer appointment — same field
        // the classic form edits and the {appointment_notes} notification code reads.
        $notes = trim( (string) self::parameter( 'notes', '' ) );
        // Status and custom fields are one set per visit too: set once for the customer,
        // they go to every booking the visit creates.
        $status = self::bookingStatus();
        $custom_fields = self::bookingCustomFields();
        $time_zone = self::bookingTimeZone( $customer );
        // The wizard has a single explicit "notify" switch instead of the per-message
        // queue dialog: notifications from all saved items are collected in memory,
        // sent right away and reported back, so the operator sees what actually went out.
        $notify_list = new NotificationList();
        $items = json_decode( self::parameter( 'items', '[]' ), true );
        if ( ! is_array( $items ) || ! $items ) {
            wp_send_json_error( array( 'error' => 'no_items' ) );
        }

        // Permission scope: reject any item (or multi-stage leg) assigned to a staff
        // member the current user may not act on.
        $allowed_staff = self::allowedStaffIds();
        if ( $allowed_staff !== null ) {
            foreach ( $items as $item ) {
                $staff_ids = array( (int) ( isset( $item['staff_id'] ) ? $item['staff_id'] : 0 ) );
                foreach ( (array) ( isset( $item['legs'] ) ? $item['legs'] : array() ) as $leg ) {
                    $staff_ids[] = (int) $leg['staff_id'];
                }
                foreach ( $staff_ids as $staff_id ) {
                    if ( $staff_id && ! in_array( $staff_id, $allowed_staff, true ) ) {
                        wp_send_json_error( array( 'error' => 'forbidden_staff' ) );
                    }
                }
            }
        }

        $conflicts = array();
        foreach ( $items as $index => $item ) {
            if ( $no_customer && ( ! empty( $item['waiting_list'] ) || ! empty( $item['series'] ) || count( (array) $item['legs'] ) > 1 ) ) {
                // A queue entry is for somebody, a series belongs to somebody, and
                // multi-stage cascades need the cart — all three require a customer.
                wp_send_json_error( array( 'error' => 'customer_required' ) );
            }
            // An item may hold no time at all — then there is no slot to lose while the
            // operator was choosing, and availability simply does not apply to it.
            if ( ! Proxy\Shared::itemHoldsTime( true, $item ) ) {
                continue;
            }
            // One item may stand for many visits — that is what an add-on does with it
            // (a repeating booking is the same item said several times). The core checks
            // whatever it is handed back: one entry for an ordinary item, several for a
            // repeating one.
            foreach ( Proxy\Shared::expandOrderItem( array( $item ), $item ) as $visit ) {
                // Earlier items of the same order are passed along: each item must fit
                // both the database AND the order composed so far (mutual overlaps).
                if ( empty( $visit['overbook'] ) && ! self::slotStillAvailable( $visit, array(), ! empty( $visit['waiting_list'] ), array_slice( $items, 0, $index ) ) ) {
                    $conflicts[] = $index;
                    break;
                }
            }
        }
        if ( $conflicts ) {
            wp_send_json_error( array( 'error' => 'slots_taken', 'conflicts' => $conflicts ) );
        }

        $created = array( 'order_id' => null, 'appointments' => array() );
        // One order = one payment: built by the cart for regular items, extended below with
        // the custom-service lines (or created standalone when the order is custom-only).
        $payment = null;
        $custom_paid = array(); // array( array( CustomerAppointment, price ), ... )

        $regular = $no_customer ? array() : array_filter( $items, function ( $item ) { return empty( $item['custom'] ); } );
        if ( $regular ) {
            $userData = new Lib\UserBookingData( null );
            $cart_items = array();
            foreach ( $regular as $index => $item ) {
                // An item may stand for several visits — a repeating booking is the same
                // item said many times. What that means, and how the resulting cart items
                // belong together, is the add-on's business: the core only builds them.
                $visits = Proxy\Shared::expandOrderItem( array( $item ), $item );
                foreach ( $visits as $n => $visit ) {
                    $location_id = (int) ( isset( $visit['location_id'] ) ? $visit['location_id'] : 0 ) ?: null;
                    $extras = array();
                    foreach ( (array) ( isset( $visit['extras'] ) ? $visit['extras'] : array() ) as $extra_id => $qty ) {
                        if ( (int) $qty > 0 ) {
                            $extras[ (int) $extra_id ] = (int) $qty;
                        }
                    }
                    // Slots as the cart expects them: one row per leg (multi-stage services
                    // come from the search with the full legs cascade). The 5th element 'w'
                    // is the cart's waiting-list marker: CartItem::toBePutOnWaitingList() —
                    // the CA is created with the waitlisted status and CartInfo diverts the
                    // price to waiting_list_total, keeping it out of the payable amount.
                    $slots = array();
                    $multi_stage = false;
                    foreach ( (array) $visit['legs'] as $leg ) {
                        $slot = array( (int) $leg['service_id'], (int) $leg['staff_id'], $leg['datetime'], $location_id );
                        if ( ! empty( $visit['waiting_list'] ) ) {
                            $slot[4] = 'w';
                        }
                        $slots[] = $slot;
                        $multi_stage = $multi_stage || (int) $leg['service_id'] !== (int) $visit['service_id'];
                    }
                    $cart_item = new Lib\CartItem();
                    $cart_item
                        ->setType( Lib\CartItem::TYPE_APPOINTMENT )
                        ->setStaffIds( array( (int) $visit['staff_id'] ) )
                        ->setServiceId( (int) $visit['service_id'] )
                        ->setNumberOfPersons( max( 1, (int) ( isset( $visit['nop'] ) ? $visit['nop'] : 1 ) ) )
                        ->setLocationId( $location_id )
                        ->setUnits( max( 1, (int) ( isset( $visit['units'] ) ? $visit['units'] : 1 ) ) )
                        ->setExtras( $extras )
                        // A multi-stage visit is handed every field: the cart gives each stage
                        // the fields of its own service, and filtering by the whole visit's
                        // service first would lose the fields attached to a stage only.
                        ->setCustomFields( $multi_stage ? $custom_fields : self::customFieldsForService( $custom_fields, (int) $visit['service_id'] ) )
                        ->setSlots( $slots );
                    // Ties between cart items of one order (a series is such a tie) are put
                    // on by whoever owns the meaning of that tie.
                    Proxy\Shared::linkCartItem( $cart_item, $item, $index, $n );
                    $userData->cart->add( $cart_item );
                    $cart_items[] = $cart_item;
                }
            }
            // The customer is picked by id, so UserBookingData::save() is bypassed on
            // purpose: it overwrites the customer's personal data (name, email, address)
            // with the current WP user's values. setCustomer only points CartInfo at the
            // right customer for pricing — it does not write to the DB (save() is what
            // mutates). The order is built directly and the cart saved with no customer
            // mutation at all.
            $userData->setCustomer( $customer );
            // The cart writes the order's time zone onto every booking it creates.
            if ( $time_zone !== null ) {
                $tz = Lib\Proxy\Pro::getTimeZoneOffset( $time_zone );
                $userData->setTimeZone( $tz['time_zone'] );
                $userData->setTimeZoneOffset( $tz['time_zone_offset'] );
            }

            // Payment: a pending "local" payment built from the cart, so the visit can be
            // paid later (marked paid via the payment dialog). CartInfo computes the total
            // the same way the gateways do — taxes, automatic discounts and customer-group
            // pricing all applied — so the amount is correct by construction. Gateway
            // "local" ⇒ paid = 0, status = pending.
            $cart_info = $userData->cart->getInfo( Lib\Entities\Payment::TYPE_LOCAL );
            $payment = new Lib\Entities\Payment();
            $payment
                ->setCartInfo( $cart_info )
                ->setCustomerId( $customer->getId() )
                ->save();

            $order = Lib\DataHolders\Booking\Order::create( $customer );
            $order->setPayment( $payment );
            $order = $userData->cart->save( $order, $userData->getTimeZone(), $userData->getTimeZoneOffset() );
            // Whatever an add-on has to write onto the saved order — a repeat rule onto
            // its series, for one — happens here, once the entities exist.
            Proxy\Shared::orderSaved( $order, $regular );
            // Details/order_id snapshot once the order exists — this JSON is what the
            // payment dialog and invoices render.
            $payment->setDetailsFromOrder( $order, $cart_info )->save();
            if ( $status !== null ) {
                // The cart gives every booking the default status of the customer's group
                // and cannot be told otherwise, so a status the operator picked is put on
                // afterwards — through the entities, so the notifications below see it.
                // A queue entry keeps its own status: it is a place in the queue, not a booking.
                $changed = array();
                foreach ( $order->getItems() as $order_item ) {
                    foreach ( self::orderItemLeaves( $order_item ) as $leaf ) {
                        $ca = $leaf->getCA();
                        if ( $ca->getStatus() === Lib\Entities\CustomerAppointment::STATUS_WAITLISTED || $ca->getStatus() === $status ) {
                            continue;
                        }
                        $ca->setStatus( $status )->setCreatedFrom( 'backend' )->save();
                        $changed[ $leaf->getAppointment()->getId() ] = $leaf->getAppointment();
                    }
                }
                // Calendars were synced by the cart with the default status.
                foreach ( $changed as $changed_appointment ) {
                    Lib\Utils\Common::syncWithCalendars( $changed_appointment );
                }
            }
            $ca_update = array( 'created_from' => 'backend' );
            if ( $notes !== '' ) {
                $ca_update['notes'] = $notes;
                // Update the in-memory CA objects too (no entity save — that would write
                // back the hardcoded 'frontend' created_from), so the notification
                // rendering below sees the notes via the {appointment_notes} code.
                foreach ( $order->getItems() as $order_item ) {
                    $order_item->getCA()->setNotes( $notes );
                }
            }
            global $wpdb;
            $wpdb->update(
                Lib\Entities\CustomerAppointment::getTableName(),
                $ca_update,
                array( 'order_id' => $order->getOrderId() )
            );
            if ( $notify ) {
                // Same notifications as the frontend Cart sender (incl. the Pro combined
                // email), but collected instead of fired — sent in one batch below.
                Lib\Notifications\Booking\Sender::sendForOrder( $order, array(), true, $notify_list );
            }
            $created['order_id'] = $order->getOrderId();
            foreach ( $cart_items as $cart_item ) {
                if ( $cart_item->getAppointmentId() ) {
                    $created['appointments'][] = $cart_item->getAppointmentId();
                }
            }
        }

        // Canonical admin save path: custom-service items always, and with no_customer —
        // every item (the cart requires a customer by construction).
        foreach ( $items as $item ) {
            if ( empty( $item['custom'] ) && ! $no_customer ) {
                continue;
            }
            if ( ! empty( $item['custom'] ) ) {
                $service_id = 0;
                $custom_name = trim( (string) $item['custom']['name'] );
                $custom_price = (string) (float) $item['custom']['price'];
                $duration = min( max( (int) $item['custom']['duration'], 300 ), DAY_IN_SECONDS );
            } else {
                // A regular service reserved with no customer: same-service bookings can
                // still join the slot (occupancy 0), other services see it as busy.
                $item_service = Service::find( (int) $item['service_id'] );
                if ( ! $item_service ) {
                    wp_send_json_error( array( 'error' => 'custom_save_failed', 'details' => array( 'service' => 'not found' ), 'created' => $created ) );
                }
                $service_id = $item_service->getId();
                $custom_name = '';
                $custom_price = '';
                $units = $item_service->getType() === Service::TYPE_SIMPLE
                    ? min( max( (int) ( isset( $item['units'] ) ? $item['units'] : 1 ), $item_service->getUnitsMin() ), $item_service->getUnitsMax() )
                    : 1;
                $duration = $item_service->getDuration() * $units;
            }
            $start_date = $item['datetime'];
            $end_date = date( 'Y-m-d H:i:s', strtotime( $start_date ) + $duration );
            // Slot values are WP tz; the canonical save expects the display time zone
            // and converts back itself — pre-convert so the round trip is exact.
            list ( $start_date, $end_date ) = self::toDisplayTz( array( $start_date, $end_date ) );
            $response = Lib\Utils\Appointment::save(
                0,
                (int) $item['staff_id'],
                $service_id,
                $custom_name,
                $custom_price,
                (int) ( isset( $item['location_id'] ) ? $item['location_id'] : 0 ),
                0,
                $start_date,
                $end_date,
                array(),
                array(),
                'current',
                $no_customer
                    ? array() // no CAs — the classic "appointment without customers"
                    : array(
                        array(
                            'id' => $customer->getId(),
                            'status' => $status !== null ? $status : Lib\Config::getDefaultAppointmentStatus(),
                            'number_of_persons' => 1,
                            'extras' => array(),
                            'custom_fields' => self::customFieldsForService( $custom_fields, $service_id ),
                            'timezone' => $time_zone,
                            'notes' => $notes,
                            'payment_id' => null,
                        ),
                    ),
                0, // suppress the internal queue — notifications are collected below
                // Without a CA the note has nowhere to live — keep it as the internal note.
                $no_customer ? $notes : '',
                'backend'
            );
            if ( ! empty( $response['errors'] ) ) {
                wp_send_json_error( array( 'error' => 'custom_save_failed', 'details' => $response['errors'], 'created' => $created ) );
            }
            if ( isset( $response['appointments'][0]['id'] ) ) {
                $appointment_id = (int) $response['appointments'][0]['id'];
                $created['appointments'][] = $appointment_id;
                if ( ! $no_customer ) {
                    $custom_appointment = Lib\Entities\Appointment::find( $appointment_id );
                    if ( $custom_appointment ) {
                        foreach ( $custom_appointment->getCustomerAppointments( true ) as $ca ) {
                            if ( $notify ) {
                                Lib\Notifications\Booking\Sender::sendForCA( $ca, $custom_appointment, array(), true, $notify_list );
                            }
                            if ( ! empty( $item['custom'] ) ) {
                                // Queue the custom line for the order's payment below.
                                $custom_paid[] = array( $ca, (float) $item['custom']['price'] );
                            }
                        }
                    }
                }
            }
        }

        // The order's single payment covers the custom items too: append a details line per
        // custom CA (the Details holder resolves the synthetic service from the appointment's
        // custom_service_name/price) and raise the total — the same public recipe the
        // Recurring Appointments backend payments use, so every details consumer (payments
        // dialog, invoices, checkout) already understands the result. Custom-only orders get
        // a standalone pending payment built the same way.
        if ( $custom_paid ) {
            if ( ! $payment ) {
                $payment = new Lib\Entities\Payment();
                $payment
                    ->setType( Lib\Entities\Payment::TYPE_LOCAL )
                    ->setStatus( Lib\Entities\Payment::STATUS_PENDING )
                    ->setPaid( 0 )
                    ->setTax( 0 )
                    ->setTotal( 0 )
                    ->save();
                $payment->getDetailsData()
                    ->setCustomer( $customer )
                    ->setData( array( 'from_backend' => true ) );
            }
            $details = $payment->getDetailsData();
            $custom_sum = 0;
            foreach ( $custom_paid as $pair ) {
                list( $ca, $price ) = $pair;
                $app_details = new Lib\DataHolders\Details\Appointment();
                $app_details->setCa( $ca )->setPrice( $price );
                $details->addDetails( $app_details );
                $ca->setPaymentId( $payment->getId() )->save();
                $custom_sum += $price;
            }
            $payment->setTotal( $payment->getTotal() + $custom_sum )->save();
        }

        self::addPaymentCheckout( $created, $payment );

        $created['notifications'] = array();
        if ( $notify ) {
            $notify_list->send();
            $created['notifications'] = $notify_list->getInfo();
        }
        // Remember the switch state for next time — same user meta as the classic
        // appointment form, so both entry points share it. Written after all saves:
        // Utils\Appointment::save() stores its own (suppressed) value in this meta.
        // The no-customer mode hides the switch — restore the remembered state instead
        // (Appointment::save above has already clobbered it with the suppressed 0).
        update_user_meta( get_current_user_id(), 'bookly_appointment_form_send_notifications', ( $no_customer ? $meta_notify_before : $notify ) ? '1' : '0' );

        wp_send_json_success( $created );
    }

    /**
     * How long an appointment occupies its staff member.
     *
     * An appointment may have no time at all — that is how a task is stored, with empty
     * dates. There is nothing to subtract then, so the length comes from where creation
     * takes it: the service duration times the booked units. Extras are NOT added — they
     * live in `extras_duration` beside `end_date` rather than inside it (see Cart::save).
     *
     * A task on a custom service has no length anywhere: the record keeps a custom name
     * and price, never a duration. Such an appointment gets 0 and is refused a time —
     * see `saveBookingWizardReschedule`.
     *
     * @param Lib\Entities\Appointment $appointment
     * @param int $units
     * @return int Seconds.
     */
    private static function appointmentDuration( $appointment, $units = 1 )
    {
        if ( $appointment->getStartDate() ) {
            return strtotime( $appointment->getEndDate() ) - strtotime( $appointment->getStartDate() );
        }

        $service = $appointment->getServiceId()
            ? Lib\Entities\Service::find( (int) $appointment->getServiceId() )
            : null;

        return $service ? max( 1, (int) $units ) * (int) $service->getDuration() : 0;
    }

    /**
     * Get the context of an appointment being rescheduled: the locked position
     * (service or custom, params) and the "was" card data.
     */
    public static function getBookingWizardRescheduleData()
    {
        // Two modes: appointment_id — move the whole appointment (calendar entry);
        // ca_id — move ONE customer out of it (Appointments table row entry). When the
        // customer is the only one, the latter degenerates into the former: moving the
        // appointment itself keeps its id, payments and calendar sync bindings.
        $ca = null;
        if ( self::parameter( 'ca_id' ) ) {
            $ca = Lib\Entities\CustomerAppointment::find( (int) self::parameter( 'ca_id' ) );
            if ( ! $ca ) {
                wp_send_json_error( array( 'error' => 'not_found' ) );
            }
            $appointment = Lib\Entities\Appointment::find( $ca->getAppointmentId() );
        } else {
            $appointment = Lib\Entities\Appointment::find( (int) self::parameter( 'appointment_id' ) );
        }
        if ( ! $appointment ) {
            wp_send_json_error( array( 'error' => 'not_found' ) );
        }

        // Permission scope: a plain staff member may only reschedule their own appointments.
        $allowed_staff = self::allowedStaffIds();
        if ( $allowed_staff !== null && ! in_array( (int) $appointment->getStaffId(), $allowed_staff, true ) ) {
            wp_send_json_error( array( 'error' => 'forbidden' ) );
        }

        $busy_statuses = Lib\Proxy\CustomStatuses::prepareBusyStatuses( array(
            Lib\Entities\CustomerAppointment::STATUS_PENDING,
            Lib\Entities\CustomerAppointment::STATUS_APPROVED,
        ) );
        $nop = 0;
        $units = 1;
        $extras = array();
        $customers = array();
        $ca_list = Lib\Entities\CustomerAppointment::query( 'ca' )
            ->select( 'ca.id, ca.customer_id, ca.status, ca.number_of_persons, ca.units, ca.extras, ca.series_id, ca.compound_service_id, ca.collaborative_service_id, c.full_name' )
            ->leftJoin( 'Customer', 'c', 'c.id = ca.customer_id' )
            ->where( 'ca.appointment_id', $appointment->getId() )
            ->fetchArray();
        // Moving needs somebody to move. Adding a customer does not: an appointment with
        // nobody in it is exactly where the first customer is added.
        if ( ! $ca_list && ! self::parameter( 'join' ) ) {
            wp_send_json_error( array( 'error' => 'no_customers' ) );
        }
        foreach ( $ca_list as $ca_row ) {
            $customers[] = $ca_row['full_name'];
            $units = max( $units, (int) $ca_row['units'] );
            if ( in_array( $ca_row['status'], $busy_statuses ) ) {
                $nop += (int) $ca_row['number_of_persons'];
                // Merged by max quantity — enough for the availability search.
                foreach ( (array) json_decode( (string) $ca_row['extras'], true ) as $extra_id => $qty ) {
                    $extras[ (int) $extra_id ] = max( isset( $extras[ (int) $extra_id ] ) ? $extras[ (int) $extra_id ] : 0, (int) $qty );
                }
            }
        }

        $duration = self::appointmentDuration( $appointment, $units ) - (int) $appointment->getExtrasDuration();

        // The series this booking belongs to, if any: the wizard asks WHAT to move — this
        // visit, this one and the ones after it, or the whole series — and needs the visits
        // themselves to show what each answer would do.
        //
        // For a whole-appointment move the series is offered only when every booking in the
        // appointment sits in the SAME series: a group where participants repeat on their
        // own schedules has no single series to move.
        // Приправить контекст переноса тем, что знают о записи аддоны: для повторяющейся
        // записи это её серия — из неё визард строит вопрос «что именно переносим».
        $extra = Lib\Proxy\RecurringAppointments::seriesContext( array(), $appointment, $ca );
        // Каскад составной услуги — знание ядра, а не аддона: токены лежат в его таблице,
        // собирает каскад его корзина, а ищет его слоты его же движок. Аддоны приносят
        // только слова (см. `compoundOfN` / `collaborativeOfN`).
        $cascade = Lib\Utils\Appointment::cascadeContext( $appointment, $ca );
        if ( $cascade ) {
            $extra['cascade'] = $cascade;
        }

        // The "was" card shows the old time in the operator's display time zone
        // (staff members may have a personal one); `datetime` stays the WP-tz value.
        $display_tz = Lib\Utils\Common::getCurrentUserTimeZone();
        $wp_tz = Lib\Config::getWPTimeZone();
        // A task has no start at all — nothing to convert, and the card says so in words.
        $display_datetime = ! $appointment->getStartDate() || $display_tz === $wp_tz
            ? $appointment->getStartDate()
            : Lib\Utils\DateTime::convertTimeZone( $appointment->getStartDate(), $wp_tz, $display_tz );

        // Customer mode (the moved customer is not the only one): the search runs
        // with HIS parameters (nop/units/extras), the "was" card shows him alone
        // plus a group note.
        if ( $ca && count( $ca_list ) > 1 ) {
            $ca_row = null;
            foreach ( $ca_list as $row ) {
                if ( (int) $row['id'] === (int) $ca->getId() ) {
                    $ca_row = $row;
                }
            }
            if ( ! $ca_row ) {
                wp_send_json_error( array( 'error' => 'not_found' ) );
            }
            $group_persons = 0;
            foreach ( $ca_list as $row ) {
                $group_persons += max( 1, (int) $row['number_of_persons'] );
            }
            wp_send_json_success( array(
                'mode' => 'customer',
                'appointment_id' => $appointment->getId(),
                'ca_id' => (int) $ca->getId(),
                'statuses' => array( array(
                    'count' => max( 1, (int) $ca_row['number_of_persons'] ),
                    'label' => Lib\Entities\CustomerAppointment::statusToString( $ca_row['status'] ),
                ) ),
                'group_persons' => $group_persons,
                'service_id' => $appointment->getServiceId() ? (int) $appointment->getServiceId() : null,
                'custom' => $appointment->getServiceId() ? null : array(
                    'name' => $appointment->getCustomServiceName(),
                    'price' => (float) $appointment->getCustomServicePrice(),
                    'duration' => $duration,
                ),
                'staff_id' => (int) $appointment->getStaffId(),
                'datetime' => $appointment->getStartDate(),
                'display' => $display_datetime,
                'location_id' => (int) $appointment->getLocationId(),
                'nop' => max( 1, (int) $ca_row['number_of_persons'] ),
                'units' => max( 1, (int) $ca_row['units'] ),
                'extras' => (array) json_decode( (string) $ca_row['extras'], true ),
                'customers' => array( $ca_row['full_name'] ),
            ) + $extra );
        }

        // Participants summary for the "was" card — "N × Status" badges where N is
        // PEOPLE (number_of_persons), not bookings: consistent with the calendar
        // tooltip "Signed up" figure and the slot capacity math.
        $status_counts = array();
        foreach ( $ca_list as $ca_row ) {
            $persons = max( 1, (int) $ca_row['number_of_persons'] );
            $status_counts[ $ca_row['status'] ] = ( isset( $status_counts[ $ca_row['status'] ] ) ? $status_counts[ $ca_row['status'] ] : 0 ) + $persons;
        }
        $statuses = array();
        foreach ( $status_counts as $status => $count ) {
            $statuses[] = array( 'count' => $count, 'label' => Lib\Entities\CustomerAppointment::statusToString( $status ) );
        }

        wp_send_json_success( array(
            'mode' => 'appointment',
            'appointment_id' => $appointment->getId(),
            'statuses' => $statuses,
            // Seats the appointment holds right now — what "add a customer" subtracts from
            // the capacity. Not `nop`: that one is a search parameter and never drops below
            // one person, while an appointment whose bookings are all cancelled, rejected or
            // waitlisted holds no seat at all and has room for the next customer.
            'taken' => $nop,
            // Where a customer goes once the seats run out: null — nowhere, the
            // appointment is simply full.
            'waiting_list' => self::joinWaitingList( $appointment, $ca_list ),
            'service_id' => $appointment->getServiceId() ? (int) $appointment->getServiceId() : null,
            'custom' => $appointment->getServiceId() ? null : array(
                'name' => $appointment->getCustomServiceName(),
                'price' => (float) $appointment->getCustomServicePrice(),
                'duration' => $duration,
            ),
            'staff_id' => (int) $appointment->getStaffId(),
            'datetime' => $appointment->getStartDate(),
            'display' => $display_datetime,
            'location_id' => (int) $appointment->getLocationId(),
            'nop' => max( 1, $nop ),
            'units' => $units,
            'extras' => $extras,
            'customers' => $customers,
        ) + $extra );
    }

    /**
     * Add one more customer to an appointment that already exists.
     *
     * Deliberately not the ordinary save: that one goes through the cart, and the cart
     * decides for itself which appointment a booking lands in — it looks for a row with
     * the same service, staff and start/end and creates a new one when it finds none
     * (Lib\Cart::save). For "add to THIS appointment" that is a guess where an answer is
     * already known, and it guesses wrong for a custom service (no service to match on)
     * or a different duration. So the booking is attached here, to the appointment the
     * operator had open, and nothing else can be attached to instead.
     *
     * What the cart does compute is the money, and that is reused: the same CartInfo the
     * gateways use, so taxes, discounts and customer-group pricing come out right by
     * construction. It is asked for the total only — nothing of it is saved.
     *
     * Notifications follow the wizard: sent right away and reported back, rather than
     * queued for a second window to confirm.
     */
    public static function saveBookingWizardJoin()
    {
        $appointment = Lib\Entities\Appointment::find( (int) self::parameter( 'appointment_id' ) );
        if ( ! $appointment ) {
            wp_send_json_error( array( 'error' => 'not_found' ) );
        }

        // Permission scope: a plain staff member may only act on their own appointments.
        $allowed_staff = self::allowedStaffIds();
        if ( $allowed_staff !== null && ! in_array( (int) $appointment->getStaffId(), $allowed_staff, true ) ) {
            wp_send_json_error( array( 'error' => 'forbidden' ) );
        }

        $customer = Lib\Entities\Customer::find( (int) self::parameter( 'customer_id' ) );
        if ( ! $customer ) {
            wp_send_json_error( array( 'error' => 'customer_required' ) );
        }

        $busy_statuses = Lib\Proxy\CustomStatuses::prepareBusyStatuses( array(
            Lib\Entities\CustomerAppointment::STATUS_PENDING,
            Lib\Entities\CustomerAppointment::STATUS_APPROVED,
        ) );
        $ca_list = Lib\Entities\CustomerAppointment::query( 'ca' )
            ->select( 'ca.status, ca.number_of_persons, ca.units, ca.compound_service_id, ca.collaborative_service_id' )
            ->where( 'ca.appointment_id', $appointment->getId() )
            ->fetchArray();
        $taken = 0;
        $units = 1;
        foreach ( $ca_list as $ca_row ) {
            // A stage of a compound or collaborative service is booked as a cascade, not
            // as a seat in one appointment — the same limit the reschedule mode states.
            if ( $ca_row['compound_service_id'] || $ca_row['collaborative_service_id'] ) {
                wp_send_json_error( array( 'error' => 'multi_stage' ) );
            }
            $units = max( $units, (int) $ca_row['units'] );
            if ( in_array( $ca_row['status'], $busy_statuses ) ) {
                $taken += max( 1, (int) $ca_row['number_of_persons'] );
            }
        }

        $nop = max( 1, (int) self::parameter( 'nop', 1 ) );
        $service = $appointment->getServiceId() ? Service::find( $appointment->getServiceId() ) : null;
        $location_id = $appointment->getLocationId() ? (int) $appointment->getLocationId() : null;

        // Capacity is a hard limit, as in the classic form: seats are taken by the
        // bookings that hold them, and there is no "book anyway" for a group service.
        // A full appointment still takes the customer onto its waiting list, when it has
        // one with room — the same answer the slot search gives a new appointment.
        $to_waiting_list = false;
        if ( $service ) {
            $capacity = self::staffServiceCapacity( (int) $appointment->getStaffId(), $service->getId(), $location_id );
            $waiting_list = self::joinWaitingList( $appointment, $ca_list );
            $occupied = $taken + ( $waiting_list && $waiting_list['despite_capacity'] ? $waiting_list['waitlisted'] : 0 );
            if ( $occupied + $nop > $capacity ) {
                if ( $waiting_list && ( $waiting_list['capacity'] === null || $waiting_list['waitlisted'] + $nop <= $waiting_list['capacity'] ) ) {
                    $to_waiting_list = true;
                } else {
                    wp_send_json_error( array( 'error' => 'capacity', 'capacity' => $capacity, 'taken' => $taken ) );
                }
            }
        }

        $extras = array();
        foreach ( (array) json_decode( self::parameter( 'extras', '{}' ), true ) as $extra_id => $qty ) {
            if ( (int) $qty > 0 ) {
                $extras[ (int) $extra_id ] = (int) $qty;
            }
        }
        $notes = trim( (string) self::parameter( 'notes', '' ) );
        $status = self::bookingStatus();
        $custom_fields = self::customFieldsForService( self::bookingCustomFields(), (int) $appointment->getServiceId() );
        $time_zone = self::bookingTimeZone( $customer );
        $notify = (bool) self::parameter( 'notify' );
        update_user_meta( get_current_user_id(), 'bookly_appointment_form_send_notifications', $notify ? '1' : '0' );

        // Every booking belongs to an order, including one made of a single booking:
        // that is what the payment, its details and the invoices are keyed by.
        $orders_entity = new Lib\Entities\Order();
        $orders_entity
            ->setToken( Lib\Utils\Common::generateToken( get_class( $orders_entity ), 'token' ) )
            ->save();

        $cart_info = null;
        $payment = new Lib\Entities\Payment();
        if ( $service ) {
            // The cart is built and asked for the total, then dropped: `getInfo` reads,
            // `save` is what writes, and only the first one is called. A place in the
            // queue is not paid for: the 'w' slot marker moves its price out of the total.
            $slot = array( $service->getId(), (int) $appointment->getStaffId(), $appointment->getStartDate(), $location_id );
            if ( $to_waiting_list ) {
                $slot[4] = 'w';
            }
            $user_data = new Lib\UserBookingData( null );
            $cart_item = new Lib\CartItem();
            $cart_item
                ->setType( Lib\CartItem::TYPE_APPOINTMENT )
                ->setStaffIds( array( (int) $appointment->getStaffId() ) )
                ->setServiceId( $service->getId() )
                ->setNumberOfPersons( $nop )
                ->setLocationId( $location_id )
                ->setUnits( $units )
                ->setExtras( $extras )
                ->setCustomFields( array() )
                ->setSlots( array( $slot ) );
            $user_data->cart->add( $cart_item );
            $user_data->setCustomer( $customer );
            $cart_info = $user_data->cart->getInfo( Lib\Entities\Payment::TYPE_LOCAL );
            $payment
                ->setCartInfo( $cart_info )
                ->setCustomerId( $customer->getId() )
                ->save();
        } else {
            // A custom service has no price list behind it — the appointment carries its
            // price, and it is the price of the visit rather than of a seat in it.
            $total = (float) $appointment->getCustomServicePrice();
            $payment
                ->setType( Lib\Entities\Payment::TYPE_LOCAL )
                ->setStatus( Lib\Entities\Payment::STATUS_PENDING )
                ->setCustomerId( $customer->getId() )
                ->setPaid( 0 )
                ->setTax( 0 )
                ->setTotal( $total )
                ->save();
        }

        $ca = new Lib\Entities\CustomerAppointment();
        $ca
            ->setCustomer( $customer )
            ->setAppointment( $appointment )
            ->setPaymentId( $payment->getId() )
            ->setOrderId( $orders_entity->getId() )
            ->setNumberOfPersons( $nop )
            ->setUnits( $units )
            ->setNotes( $notes )
            ->setExtras( json_encode( $extras ) )
            ->setCustomFields( json_encode( $custom_fields ) )
            // A queue entry keeps its own status: it is a place in the queue, not a booking.
            ->setStatus( $to_waiting_list
                ? Lib\Entities\CustomerAppointment::STATUS_WAITLISTED
                : ( $status !== null ? $status : Lib\Proxy\CustomerGroups::takeDefaultAppointmentStatus( Lib\Config::getDefaultAppointmentStatus(), $customer->getGroupId() ) ) )
            ->setCreatedFrom( 'backend' )
            ->setCreatedAt( current_time( 'mysql' ) );
        if ( $time_zone !== null ) {
            $tz = Lib\Proxy\Pro::getTimeZoneOffset( $time_zone );
            $ca
                ->setTimeZone( $tz['time_zone'] )
                ->setTimeZoneOffset( $tz['time_zone_offset'] );
        }
        $ca->save();
        // A file uploaded in the form stays unattached until the booking that refers to it is saved.
        Lib\Proxy\Files::attachCFFiles( $custom_fields, $ca );

        $order = Lib\DataHolders\Booking\Order::create( $customer );
        $order->setPayment( $payment );
        $order->setOrderId( $orders_entity->getId() );
        $item = Lib\DataHolders\Booking\Simple::create( $ca )->setAppointment( $appointment );
        if ( $service ) {
            $item->setService( $service );
        }
        $order->addItem( 0, $item );

        if ( $cart_info ) {
            $payment->setDetailsFromOrder( $order, $cart_info )->save();
        } else {
            $details = $payment->getDetailsData();
            $details->setCustomer( $customer )->setData( array( 'from_backend' => true ) );
            $app_details = new Lib\DataHolders\Details\Appointment();
            $app_details->setCa( $ca )->setPrice( (float) $payment->getTotal() );
            $details->addDetails( $app_details );
            $payment->setOrderId( $orders_entity->getId() )->save();
        }

        // Extras may make the visit longer, and the appointment lasts as long as its
        // longest booking — the same rule the card applies when a booking is edited.
        if ( Lib\Proxy\ServiceExtras::considerDuration() ) {
            $appointment
                ->setExtrasDuration( $appointment->getMaxExtrasDuration() )
                ->save();
        }
        Lib\Proxy\Shared::syncOnlineMeeting( array(), $appointment );
        Lib\Utils\Common::syncWithCalendars( $appointment );

        $notifications = array();
        if ( $notify ) {
            $notify_list = new NotificationList();
            // `true` — this is a new booking, not a change to an existing one: the
            // customer is told they are booked, not that something moved.
            Lib\Notifications\Booking\Sender::sendForOrder( $order, array(), true, $notify_list );
            $notify_list->send();
            $notifications = $notify_list->getInfo();
        }

        $created = array(
            'appointment_id' => $appointment->getId(),
            'ca_id' => $ca->getId(),
            'waiting_list' => $to_waiting_list,
            'notifications' => $notifications,
            'notified' => $notify,
        );
        // Nothing to pay for a place in the queue yet — no payment link either.
        self::addPaymentCheckout( $created, $to_waiting_list ? null : $payment );

        wp_send_json_success( $created );
    }

    /**
     * Checkout link(s) so the customer can pay this pending payment online — the same
     * links the Payments dialog offers. The Pro proxy adds 'checkout_urls' (one per
     * checkout form placed on a page); without Pro or a configured form the list is
     * empty and a hint explains what to set up.
     *
     * The hints are instructional on purpose: Bookly is a large system and not every Pro
     * user knows every part of it, so what is missing for the customer to actually pay is
     * named rather than left as an empty list.
     *
     * @param array $created
     * @param Lib\Entities\Payment|null $payment
     * @return void
     */
    protected static function addPaymentCheckout( array &$created, $payment )
    {
        $created['checkout_urls'] = array();
        $created['payment_hints'] = array();
        if ( ! $payment ) {
            return;
        }

        $created['payment_id'] = $payment->getId();
        $checkout = \Bookly\Backend\Components\Dialogs\Payment\Proxy\Shared::preparePaymentDetails( array(), $payment );
        $created['checkout_urls'] = isset( $checkout['checkout_urls'] ) ? $checkout['checkout_urls'] : array();

        $hints = array();
        if ( ! Lib\Config::proActive() ) {
            $hints[] = __( 'Online payment links require Bookly Pro with a checkout form.', 'bookly-responsive-appointment-booking-tool' );
        } else {
            $online_gateways = Lib\Utils\Common::getGateways();
            unset( $online_gateways[ Lib\Entities\Payment::TYPE_LOCAL ], $online_gateways[ Lib\Entities\Payment::TYPE_FREE ] );
            if ( ! $online_gateways ) {
                $hints[] = __( 'No online payment method is enabled — turn one on in Bookly → Settings → Payments so customers can pay online.', 'bookly-responsive-appointment-booking-tool' );
            }
            if ( ! $created['checkout_urls'] ) {
                $hints[] = __( 'No checkout form is set up — place one on a page to share a payment link.', 'bookly-responsive-appointment-booking-tool' );
            }
        }
        $created['payment_hints'] = $hints;
    }

    /**
     * Seats a staff member's service holds at a location, falling back to the base row
     * when the location has no override — the lookup the classic form's capacity check
     * does before it refuses to overflow an appointment.
     *
     * @param int $staff_id
     * @param int $service_id
     * @param int|null $location_id
     * @return int
     */
    /**
     * The waiting list of an appointment as the slot search sees it, or null when a
     * customer cannot be queued for it: the add-on or the option is off, the form shows
     * a single time slot, or the appointment has no service of the catalog (custom
     * service, task) to keep a queue for.
     *
     * Whether the customer actually lands in it is decided by the caller with the
     * number of persons: the queue opens once the seats run out and holds up to
     * `capacity` persons (null — no limit). With `despite_capacity` the persons already
     * waiting are counted as seated, so a seat freed meanwhile goes to them first.
     *
     * @param Lib\Entities\Appointment $appointment
     * @param array $ca_list rows with `status` and `number_of_persons`
     * @return array|null
     */
    private static function joinWaitingList( Lib\Entities\Appointment $appointment, array $ca_list )
    {
        if ( ! Lib\Config::waitingListActive() || ! get_option( 'bookly_waiting_list_enabled' ) || Lib\Config::showSingleTimeSlot() ) {
            return null;
        }
        $service = $appointment->getServiceId() && $appointment->getStartDate() ? Service::find( $appointment->getServiceId() ) : null;
        if ( ! $service ) {
            return null;
        }
        $waitlisted = 0;
        foreach ( $ca_list as $ca_row ) {
            if ( $ca_row['status'] === Lib\Entities\CustomerAppointment::STATUS_WAITLISTED ) {
                $waitlisted += max( 1, (int) $ca_row['number_of_persons'] );
            }
        }
        $capacity = $service->getWaitingListCapacity();

        return array(
            'waitlisted' => $waitlisted,
            'capacity' => $capacity === null ? null : (int) $capacity,
            'despite_capacity' => (bool) get_option( 'bookly_waiting_list_despite_capacity' ),
        );
    }

    protected static function staffServiceCapacity( $staff_id, $service_id, $location_id )
    {
        $query = StaffService::query( 'ss' )
            ->select( 'ss.capacity_max, ss.location_id' )
            ->where( 'ss.staff_id', $staff_id )
            ->where( 'ss.service_id', $service_id );
        $rows = $query->fetchArray();
        $capacity = 1;
        $found_base = false;
        foreach ( $rows as $row ) {
            if ( $location_id && (int) $row['location_id'] === (int) $location_id ) {
                return max( 1, (int) $row['capacity_max'] );
            }
            if ( $row['location_id'] === null && ! $found_base ) {
                $found_base = true;
                $capacity = max( 1, (int) $row['capacity_max'] );
            }
        }

        return $capacity;
    }

    /**
     * Save a reschedule: move the existing appointment to a new time (and possibly
     * another staff member) with availability re-checked right before saving.
     * The service, customers and their parameters stay untouched — the canonical
     * admin save updates the appointment and sends change notifications itself.
     */
    public static function saveBookingWizardReschedule()
    {
        // Two modes: whole-appointment move (appointment_id) and single group
        // participant move (ca_id). When the participant is the only one in the
        // appointment, the customer move degenerates into the whole move.
        $move_ca = null;
        if ( self::parameter( 'ca_id' ) ) {
            $move_ca = Lib\Entities\CustomerAppointment::find( (int) self::parameter( 'ca_id' ) );
            if ( ! $move_ca ) {
                wp_send_json_error( array( 'error' => 'not_found' ) );
            }
            $appointment = Lib\Entities\Appointment::find( (int) $move_ca->getAppointmentId() );
        } else {
            $appointment = Lib\Entities\Appointment::find( (int) self::parameter( 'appointment_id' ) );
        }
        if ( ! $appointment ) {
            wp_send_json_error( array( 'error' => 'not_found' ) );
        }
        $staff_id = (int) self::parameter( 'staff_id' );
        $datetime = self::parameter( 'datetime' );
        $notify = (bool) self::parameter( 'notify' );
        $overbook = (bool) self::parameter( 'overbook' );

        // Permission scope: the appointment being moved and its target staff member
        // must both be within the current user's reach.
        $allowed_staff = self::allowedStaffIds();
        if ( $allowed_staff !== null
            && ( ! in_array( (int) $appointment->getStaffId(), $allowed_staff, true )
                || ! in_array( $staff_id, $allowed_staff, true ) ) ) {
            wp_send_json_error( array( 'error' => 'forbidden' ) );
        }

        // Whole cascade: the chosen slot carries the stages of the new time (`legs`), and
        // every stage travels to its own. The appointments are UPDATED, not recreated —
        // bookings, payments, statuses and the token itself survive the move.
        $legs = json_decode( (string) self::parameter( 'legs', '' ), true );
        if ( is_array( $legs ) && count( $legs ) > 1 ) {
            self::rescheduleCascade( $appointment, $legs, $notify );
        }

        $ca_list = Lib\Entities\CustomerAppointment::query( 'ca' )
            ->where( 'ca.appointment_id', $appointment->getId() )
            ->fetchArray();

        // Customer mode: move a single participant, the rest of the group stays.
        // The existing customer appointment is re-attached to the target
        // appointment (never deleted/recreated), so the payment, status, token
        // and history survive the move. Sends the response itself.
        if ( $move_ca && count( $ca_list ) > 1 ) {
            if ( $move_ca->getCompoundServiceId() || $move_ca->getCollaborativeServiceId() ) {
                wp_send_json_error( array( 'error' => 'multi_stage' ) );
            }
            self::moveSingleParticipant( $move_ca, $appointment, $staff_id, $datetime, $notify, $overbook );
        }

        $customers = array();
        $nop = 0;
        $busy_statuses = Lib\Proxy\CustomStatuses::prepareBusyStatuses( array(
            Lib\Entities\CustomerAppointment::STATUS_PENDING,
            Lib\Entities\CustomerAppointment::STATUS_APPROVED,
        ) );
        $merged_extras = array();
        $units = 1;
        foreach ( $ca_list as $ca ) {
            $units = max( $units, (int) $ca['units'] );
            $extras = (array) json_decode( (string) $ca['extras'], true );
            $customers[] = array(
                'ca_id' => (int) $ca['id'],
                'id' => (int) $ca['customer_id'],
                'status' => $ca['status'],
                'number_of_persons' => (int) $ca['number_of_persons'],
                'extras' => $extras,
                'custom_fields' => (array) json_decode( (string) $ca['custom_fields'], true ),
                'notes' => (string) $ca['notes'],
                'payment_id' => $ca['payment_id'] ? (int) $ca['payment_id'] : null,
                'timezone' => null,
                // The canonical save reads these unconditionally (payment/series
                // handling); the wizard reschedule never creates either.
                'payment_for' => null,
                'payment_action' => null,
                'series_id' => $ca['series_id'] ? (int) $ca['series_id'] : null,
            );
            if ( in_array( $ca['status'], $busy_statuses ) ) {
                $nop += (int) $ca['number_of_persons'];
                foreach ( $extras as $extra_id => $qty ) {
                    $merged_extras[ (int) $extra_id ] = max( isset( $merged_extras[ (int) $extra_id ] ) ? $merged_extras[ (int) $extra_id ] : 0, (int) $qty );
                }
            }
        }

        // Length of the moved appointment. Computed after the roster is read, because a
        // task has no dates to measure and its length comes from the service times the
        // booked units — and units are known only once the bookings are in hand.
        $duration = self::appointmentDuration( $appointment, $units );
        if ( $duration <= 0 ) {
            // A custom service keeps no duration on the appointment, so a task made of
            // one cannot be given a time here — there is nothing to reserve.
            wp_send_json_error( array( 'error' => 'no_duration' ) );
        }
        $end_datetime = date( 'Y-m-d H:i:s', strtotime( $datetime ) + $duration );

        if ( ! $overbook ) {
            self::$require_unjoined = true;
            $check_item = array(
                'service_id' => $appointment->getServiceId() ? (int) $appointment->getServiceId() : null,
                'custom' => $appointment->getServiceId() ? null : array(
                    'duration' => $duration,
                ),
                'staff_id' => $staff_id,
                'datetime' => $datetime,
                'location_id' => (int) $appointment->getLocationId(),
                'nop' => max( 1, $nop ),
                'units' => $units,
                'extras' => $merged_extras,
            );
            if ( ! self::slotStillAvailable( $check_item, array( $appointment->getId() ) ) ) {
                wp_send_json_error( array( 'error' => 'slots_taken', 'conflicts' => array( 0 ) ) );
            }
        }

        // The wizard works with slot VALUES in WP time zone, while the canonical save
        // treats incoming dates as the current user's display time zone and converts
        // them to WP tz itself. Pre-convert WP → display so the round trip lands on
        // the exact instant the operator picked (matters for staff members with a
        // personal time zone; a no-op for everyone else).
        list ( $save_start, $save_end ) = self::toDisplayTz( array( $datetime, $end_datetime ) );

        $response = Lib\Utils\Appointment::save(
            $appointment->getId(),
            $staff_id,
            $appointment->getServiceId() ? (int) $appointment->getServiceId() : 0,
            (string) $appointment->getCustomServiceName(),
            (string) $appointment->getCustomServicePrice(),
            (int) $appointment->getLocationId(),
            0,
            $save_start,
            $save_end,
            array(),
            array(),
            'current',
            $customers,
            $notify ? 1 : 0,
            $appointment->getInternalNote(),
            'backend'
        );
        if ( ! empty( $response['errors'] ) ) {
            wp_send_json_error( array( 'error' => 'save_failed', 'details' => $response['errors'] ) );
        }
        // The wizard reschedule never alters customer statuses, so the collected queue
        // holds only the "new booking details" channel — dispatch it right away instead
        // of asking the operator through the queue dialog.
        $sent = array();
        if ( ! empty( $response['queue']['token'] ) ) {
            Lib\Notifications\Routine::sendNotificationsAssociatedWithQueue( array_keys( $response['queue']['all'] ), 'all', $response['queue']['token'] );
            $sent = array_values( $response['queue']['all'] );
        }

        wp_send_json_success( array(
            'appointment_id' => $appointment->getId(),
            'notifications' => $sent,
        ) );
    }

    /**
     * Move every stage of a cascade to the times of the chosen slot. Sends the response.
     *
     * Stages are matched to legs by service, repeated services in time order. Time order
     * alone is not enough: collaborative stages all start together, and a stage may have
     * been moved on its own. A slot with a different set of stages is a different
     * service, not a new time for this booking, and is refused.
     *
     * Each stage keeps its own length: it already accounts for units and extras, while a
     * leg carries only when the stage starts.
     *
     * @param Lib\Entities\Appointment $appointment  Any stage of the cascade.
     * @param array $legs
     * @param bool $notify
     */
    private static function rescheduleCascade( $appointment, array $legs, $notify )
    {
        $row = Lib\Entities\CustomerAppointment::query( 'ca' )
            ->select( 'ca.compound_token, ca.collaborative_token' )
            ->where( 'ca.appointment_id', $appointment->getId() )
            ->fetchRow();
        $token_field = null;
        if ( $row && $row['compound_token'] ) {
            $token_field = 'ca.compound_token';
            $token = $row['compound_token'];
        } elseif ( $row && $row['collaborative_token'] ) {
            $token_field = 'ca.collaborative_token';
            $token = $row['collaborative_token'];
        }
        if ( ! $token_field ) {
            wp_send_json_error( array( 'error' => 'not_in_cascade' ) );
        }

        $stages = Lib\Entities\CustomerAppointment::query( 'ca' )
            ->select( 'DISTINCT ca.appointment_id, a.start_date, a.end_date, a.service_id' )
            ->innerJoin( 'Appointment', 'a', 'a.id = ca.appointment_id' )
            ->where( $token_field, $token )
            ->whereNot( 'a.start_date', null )
            ->sortBy( 'a.start_date' )
            ->fetchArray();
        if ( count( $stages ) !== count( $legs ) ) {
            wp_send_json_error( array( 'error' => 'stages_mismatch' ) );
        }
        // Every stage is checked before any of them moves: a mismatch found halfway would
        // leave the earlier stages at the new time and the rest at the old one.
        $matched = array();
        foreach ( array_values( $legs ) as $leg ) {
            if ( ! isset( $leg['service_id'], $leg['staff_id'], $leg['datetime'] ) ) {
                wp_send_json_error( array( 'error' => 'stages_mismatch' ) );
            }
            $stage_key = null;
            foreach ( $stages as $key => $stage ) {
                if ( (int) $stage['service_id'] === (int) $leg['service_id'] ) {
                    $stage_key = $key;
                    break;
                }
            }
            if ( $stage_key === null ) {
                wp_send_json_error( array( 'error' => 'stages_mismatch' ) );
            }
            $matched[] = array( $stages[ $stage_key ], $leg );
            unset( $stages[ $stage_key ] );
        }

        $moved = array();
        $sent = array();
        foreach ( $matched as $pair ) {
            list ( $stage, $leg ) = $pair;
            $duration = strtotime( $stage['end_date'] ) - strtotime( $stage['start_date'] );
            $stage_sent = self::moveWholeAppointment(
                (int) $stage['appointment_id'],
                (int) $leg['staff_id'],
                $leg['datetime'],
                $duration,
                $notify
            );
            if ( $stage_sent === null ) {
                // Часть каскада уже переехала: расписание клиента сейчас разорвано, и
                // молчать об этом нельзя.
                wp_send_json_error( array( 'error' => 'cascade_partial', 'moved' => $moved ) );
            }
            $moved[] = (int) $stage['appointment_id'];
            $sent = array_merge( $sent, $stage_sent );
        }

        wp_send_json_success( array(
            'appointment_id' => $appointment->getId(),
            'moved' => $moved,
            'notifications' => $sent,
        ) );
    }

    /**
     * Move one appointment with everything booked in it.
     *
     * @param int $appointment_id
     * @param int $staff_id
     * @param string $datetime
     * @param int $duration
     * @param bool $notify
     * @return array|null  Notifications sent, or null when the save failed.
     */
    private static function moveWholeAppointment( $appointment_id, $staff_id, $datetime, $duration, $notify )
    {
        $appointment = Lib\Entities\Appointment::find( $appointment_id );
        if ( ! $appointment ) {
            return null;
        }

        $customers = array();
        foreach ( Lib\Entities\CustomerAppointment::query( 'ca' )->where( 'ca.appointment_id', $appointment_id )->fetchArray() as $ca ) {
            $customers[] = array(
                'ca_id' => (int) $ca['id'],
                'id' => (int) $ca['customer_id'],
                'status' => $ca['status'],
                'number_of_persons' => (int) $ca['number_of_persons'],
                'extras' => (array) json_decode( (string) $ca['extras'], true ),
                'custom_fields' => (array) json_decode( (string) $ca['custom_fields'], true ),
                'notes' => (string) $ca['notes'],
                'payment_id' => $ca['payment_id'] ? (int) $ca['payment_id'] : null,
                'timezone' => null,
                'payment_for' => null,
                'payment_action' => null,
                'series_id' => $ca['series_id'] ? (int) $ca['series_id'] : null,
            );
        }
        if ( ! $customers ) {
            return null;
        }

        list ( $save_start, $save_end ) = self::toDisplayTz( array(
            $datetime,
            date( 'Y-m-d H:i:s', strtotime( $datetime ) + $duration ),
        ) );
        $response = Lib\Utils\Appointment::save(
            $appointment_id,
            $staff_id,
            (int) $appointment->getServiceId(),
            (string) $appointment->getCustomServiceName(),
            (string) $appointment->getCustomServicePrice(),
            (int) $appointment->getLocationId(),
            0,
            $save_start,
            $save_end,
            array(),
            array(),
            'current',
            $customers,
            $notify ? 1 : 0,
            $appointment->getInternalNote(),
            'backend'
        );
        if ( ! empty( $response['errors'] ) ) {
            return null;
        }

        $sent = array();
        if ( ! empty( $response['queue']['token'] ) ) {
            Lib\Notifications\Routine::sendNotificationsAssociatedWithQueue( array_keys( $response['queue']['all'] ), 'all', $response['queue']['token'] );
            $sent = array_values( $response['queue']['all'] );
        }

        return $sent;
    }

    /**
     * Move one group participant to a new slot. The availability is re-checked
     * as a regular booking (no ignore list — partially booked slots are valid
     * join targets). The participant either joins an existing appointment with
     * the same service/staff/location/time or gets a fresh appointment cloned
     * from the source one. Both source and target appointments are re-synced
     * with external calendars; the change notification goes to the moved
     * customer only — the rest of the group is not affected.
     *
     * Always exits with a JSON response.
     *
     * @param Lib\Entities\CustomerAppointment $move_ca
     * @param Lib\Entities\Appointment $appointment  Source appointment.
     * @param int $staff_id  Target staff member.
     * @param string $datetime  Target slot start (Y-m-d H:i:s).
     * @param bool $notify
     * @param bool $overbook
     */
    private static function moveSingleParticipant( $move_ca, $appointment, $staff_id, $datetime, $notify, $overbook )
    {
        $service_id = $appointment->getServiceId() ? (int) $appointment->getServiceId() : null;
        $location_id = $appointment->getLocationId() ? (int) $appointment->getLocationId() : null;
        $nop = max( 1, (int) $move_ca->getNumberOfPersons() );
        $units = max( 1, (int) $move_ca->getUnits() );
        $extras = (array) json_decode( (string) $move_ca->getExtras(), true );
        // Units of THIS participant: a task takes its length from the service, and one
        // participant may have booked a different number of units than the rest.
        $duration = self::appointmentDuration( $appointment, $units );
        if ( $duration <= 0 ) {
            wp_send_json_error( array( 'error' => 'no_duration' ) );
        }

        // Same staff and time — nothing to move.
        if ( (int) $appointment->getStaffId() === $staff_id && $appointment->getStartDate() === $datetime ) {
            wp_send_json_success( array( 'appointment_id' => $appointment->getId(), 'notifications' => array() ) );
        }

        if ( ! $overbook ) {
            // Regular booking search: no ignore list and no unjoined requirement,
            // so a partially booked slot (including the join target) qualifies.
            $check_item = array(
                'service_id' => $service_id,
                'custom' => $service_id ? null : array(
                    'duration' => $duration,
                ),
                'staff_id' => $staff_id,
                'datetime' => $datetime,
                'location_id' => (int) $appointment->getLocationId(),
                'nop' => $nop,
                'units' => $units,
                'extras' => $extras,
            );
            if ( ! self::slotStillAvailable( $check_item, array() ) ) {
                wp_send_json_error( array( 'error' => 'slots_taken', 'conflicts' => array( 0 ) ) );
            }
        }

        $extras_duration = 0;
        if ( $service_id && $extras && Lib\Config::serviceExtrasActive() && Lib\Proxy\ServiceExtras::considerDuration() ) {
            $extras_duration = (int) Lib\Proxy\ServiceExtras::getTotalDuration( $extras );
        }

        // Join target: an appointment with the same service/staff/time — matched
        // the same way the canonical admin save finds existing appointments (no
        // location condition: one staff member is a single physical resource, so
        // the slot occupied at another location is still the same slot).
        // Custom services never join — such an appointment blocks its whole range.
        $target = null;
        if ( $service_id ) {
            $target = Lib\Entities\Appointment::query( 'a' )
                ->where( 'a.staff_id', $staff_id )
                ->where( 'a.service_id', $service_id )
                ->where( 'a.start_date', $datetime )
                ->whereNot( 'a.id', $appointment->getId() )
                ->findOne();
        }
        if ( ! $target ) {
            $service = $service_id ? Service::find( $service_id ) : null;
            $target_duration = $service ? (int) $service->getDuration() * $units : $duration;
            $target = new Lib\Entities\Appointment();
            $target
                ->setLocationId( $location_id )
                ->setStaffId( $staff_id )
                ->setServiceId( $service_id )
                ->setCustomServiceName( $appointment->getCustomServiceName() )
                ->setCustomServicePrice( $appointment->getCustomServicePrice() )
                ->setStartDate( $datetime )
                ->setEndDate( date( 'Y-m-d H:i:s', strtotime( $datetime ) + $target_duration ) )
                ->setExtrasDuration( $extras_duration )
                ->save();
        } elseif ( $extras_duration > (int) $target->getExtrasDuration() ) {
            // The moved participant's extras run longer than any already counted.
            $target->setExtrasDuration( $extras_duration )->save();
        }

        $move_ca->setAppointmentId( $target->getId() )->save();

        // The moved participant's time changed — drop their sent reminders so they
        // are re-sent for the new time. Same rule as the canonical save applies on a
        // start_date change (Utils\Appointment::_deleteSentReminders), scoped to the
        // one customer appointment that actually moved.
        Lib\Entities\SentNotification::query( 'sn' )
            ->delete( 'sn' )
            ->leftJoin( 'Notification', 'n', 'n.id = sn.notification_id' )
            ->where( 'sn.ref_id', $move_ca->getId() )
            ->whereIn( 'n.type', array(
                Lib\Entities\Notification::TYPE_APPOINTMENT_REMINDER,
                Lib\Entities\Notification::TYPE_LAST_CUSTOMER_APPOINTMENT,
            ) )
            ->where( 'n.active', 1 )
            ->execute();

        // Both ends changed their participant lists.
        Lib\Utils\Common::syncWithCalendars( $appointment );
        Lib\Utils\Common::syncWithCalendars( $target );
        // Online meeting for the target appointment (created or joined) — idempotent,
        // as in the canonical save. Alert texts are not surfaced by the wizard (the
        // whole-move path ignores them the same way).
        if ( $service_id ) {
            Lib\Proxy\Shared::syncOnlineMeeting( array(), $target );
        }

        $sent = array();
        if ( $notify ) {
            $queue = new NotificationList();
            Lib\Notifications\Booking\Sender::sendForCA( $move_ca, $target, array(), true, $queue );
            // The participant left the source appointment — a place is now free there:
            // offer it to the source waiting list (staff prompt + waitlisted customers),
            // exactly as the canonical save does after a participants change.
            $queue = Lib\Proxy\WaitingList::handleParticipantsChange( $queue, $appointment );
            foreach ( $appointment->getCustomerAppointments( true ) as $source_ca ) {
                $queue = Lib\Proxy\WaitingList::handleFreePlace( $queue, $source_ca );
            }
            if ( $queue->getList() ) {
                $queue->send();
                $sent = $queue->getInfo();
            }
        }

        wp_send_json_success( array( 'appointment_id' => $target->getId(), 'notifications' => $sent ) );
    }

    /**
     * Re-check that the picked slot is still bookable: run the same engine search
     * narrowed to the item's staff member and day, and look for the exact start
     * time among not fully booked slots. Group capacities, paddings, special days
     * and the custom-service branch are honored automatically.
     *
     * @param array $item
     * @param array $ignore_appointments
     * @return bool
     */
    /**
     * Take the items already picked into the order (not saved yet) into account
     * for a slot search. Regular items are added to the user data cart — the
     * engine treats carted slots as taken (Finder::handleCartBookings) with the
     * full booking semantics: a group booking tops up the persons count of the
     * matching slot instead of blocking it, paddings and extras duration apply.
     * Custom-service items cannot go through the cart (no service row) — they are
     * returned as pseudo-bookings to inject after prepare(); with no matching
     * service they block the whole range for their staff member, same as a saved
     * custom-service appointment. Waiting-list items are skipped: a queue entry
     * does not occupy capacity.
     *
     * @param Lib\UserBookingData $userData
     * @param array $items  Items in the save-endpoint shape.
     * @return array [ [ staff_id, Lib\Slots\Booking ], ... ] pseudo-bookings for addStaffBooking()
     */
    private static function applyOrderItems( Lib\UserBookingData $userData, array $items )
    {
        $custom_bookings = array();
        foreach ( $items as $item ) {
            // An item that holds no time cannot make another item of the same order
            // unavailable — it has nothing to overlap with.
            if ( ! is_array( $item ) || ! empty( $item['waiting_list'] ) || ! Proxy\Shared::itemHoldsTime( true, $item ) ) {
                continue;
            }
            $location_id = (int) ( isset( $item['location_id'] ) ? $item['location_id'] : 0 ) ?: null;
            if ( ! empty( $item['custom'] ) ) {
                $staff_id = (int) ( isset( $item['staff_id'] ) ? $item['staff_id'] : 0 );
                $datetime = isset( $item['datetime'] ) ? $item['datetime'] : null;
                if ( $staff_id && $datetime ) {
                    $duration = min( max( (int) $item['custom']['duration'], 300 ), DAY_IN_SECONDS );
                    $range = Lib\Slots\Range::fromDates( $datetime, $datetime )->resize( $duration );
                    $custom_bookings[] = array( $staff_id, new Lib\Slots\Booking(
                        $location_id,
                        null,
                        1,
                        0,
                        $range->start()->format( 'Y-m-d H:i:s' ),
                        $range->end()->format( 'Y-m-d H:i:s' ),
                        0,
                        0,
                        0,
                        true,
                        false
                    ) );
                }
                continue;
            }
            $extras = array();
            foreach ( (array) ( isset( $item['extras'] ) ? $item['extras'] : array() ) as $extra_id => $qty ) {
                if ( (int) $qty > 0 ) {
                    $extras[ (int) $extra_id ] = (int) $qty;
                }
            }
            $slots = array();
            foreach ( (array) ( isset( $item['legs'] ) ? $item['legs'] : array() ) as $leg ) {
                $slots[] = array( (int) $leg['service_id'], (int) $leg['staff_id'], $leg['datetime'], $location_id );
            }
            if ( ! $slots ) {
                continue;
            }
            $cart_item = new Lib\CartItem();
            $cart_item
                ->setType( Lib\CartItem::TYPE_APPOINTMENT )
                ->setStaffIds( array( (int) $item['staff_id'] ) )
                ->setServiceId( (int) $item['service_id'] )
                ->setNumberOfPersons( max( 1, (int) ( isset( $item['nop'] ) ? $item['nop'] : 1 ) ) )
                ->setLocationId( $location_id )
                ->setUnits( max( 1, (int) ( isset( $item['units'] ) ? $item['units'] : 1 ) ) )
                ->setExtras( $extras )
                ->setCustomFields( array() )
                ->setSlots( $slots );
            $userData->cart->add( $cart_item );
        }

        return $custom_bookings;
    }

    private static function slotStillAvailable( array $item, array $ignore_appointments = array(), $for_waiting_list = false, array $order_items = array() )
    {
        $datetime = isset( $item['datetime'] ) ? $item['datetime'] : null;
        $staff_id = (int) ( isset( $item['staff_id'] ) ? $item['staff_id'] : 0 );
        if ( ! $datetime || ! $staff_id ) {
            return false;
        }
        $date = substr( $datetime, 0, 10 );
        $location_id = (int) ( isset( $item['location_id'] ) ? $item['location_id'] : 0 ) ?: null;
        $units = 1;
        $extras = array();

        if ( ! empty( $item['custom'] ) ) {
            $synthetic = new Service();
            $synthetic
                ->setDuration( min( max( (int) $item['custom']['duration'], 300 ), DAY_IN_SECONDS ) )
                ->setPaddingLeft( 0 )
                ->setPaddingRight( 0 );
            $chain_item = new CustomServiceChainItem();
            $chain_item->setCustomService( $synthetic );
            $chain_item->setServiceId( null );
        } else {
            $service = Service::find( (int) $item['service_id'] );
            if ( ! $service ) {
                return false;
            }
            if ( $service->getType() === Service::TYPE_SIMPLE ) {
                $units = min( max( (int) ( isset( $item['units'] ) ? $item['units'] : 1 ), $service->getUnitsMin() ), $service->getUnitsMax() );
            }
            foreach ( (array) ( isset( $item['extras'] ) ? $item['extras'] : array() ) as $extra_id => $qty ) {
                if ( (int) $qty > 0 ) {
                    $extras[ (int) $extra_id ] = (int) $qty;
                }
            }
            $chain_item = new Lib\ChainItem();
            $chain_item->setServiceId( $service->getId() );
        }
        $chain_item
            ->setStaffIds( array( $staff_id ) )
            ->setNumberOfPersons( max( 1, (int) ( isset( $item['nop'] ) ? $item['nop'] : 1 ) ) )
            ->setQuantity( 1 )
            ->setUnits( $units )
            ->setExtras( $extras )
            ->setLocationId( $location_id );
        $chain = new Lib\Chain();
        $chain->add( $chain_item );

        $userData = new Lib\UserBookingData( null );
        $userData->resetChain();
        $userData->chain = $chain;
        $userData->setDays( array( 1, 2, 3, 4, 5, 6, 7 ) );
        $userData->setDateFrom( $date );
        // Items of the same order saved before this one occupy their slots: without
        // this, two mutually overlapping items would each pass the check against the
        // database alone and both save (a double booking).
        $custom_bookings = $order_items ? self::applyOrderItems( $userData, $order_items ) : array();

        $finder = new Finder(
            $userData,
            function ( DatePoint $client_dp ) { return $client_dp->format( 'Y-m-d' ); },
            function ( DatePoint $client_dp, $groups_count, $slots_count ) {
                return $groups_count >= 1 ? 2 : 0;
            },
            // Normal items require a truly free slot: with the waiting list disabled the
            // WAITING_LIST_STARTED state never appears, so a full slot fails !fullyBooked().
            // A queue item enables it — state 4 (or a slot freed meanwhile) passes.
            $for_waiting_list,
            $ignore_appointments,
            true
        );
        // The check looks for the chosen staff member in the alternatives chain, so it has to
        // see the same candidates the feed offered: without this a queue entry for a staff
        // member whose colleague is free would be refused here as "time was taken".
        $finder->keepAllCandidates();
        $finder->prepare();
        foreach ( $custom_bookings as $custom_booking ) {
            $finder->addStaffBooking( $custom_booking[0], $custom_booking[1] );
        }
        $window_start = DatePoint::fromStrInClientTz( $date );
        $now = DatePoint::now()->toClientTz();
        if ( $now->gt( $window_start ) ) {
            $window_start = $now;
        }
        $window_end = DatePoint::fromStrInClientTz( $date )->modify( '+1 day' );
        $finder->client_start_dp = $window_start;
        $finder->start_dp = $window_start->toWpTz();
        $finder->client_end_dp = $window_end;
        $finder->end_dp = $window_end->toWpTz();
        $finder->load();

        foreach ( $finder->getSlots() as $group_slots ) {
            /** @var Lib\Slots\Range $slot */
            foreach ( $group_slots as $slot ) {
                $s = $slot;
                $guard = 0;
                do {
                    if ( $s->staffId() == $staff_id
                        && $s->start()->value()->format( 'Y-m-d H:i:s' ) === $datetime
                        && ! $s->fullyBooked()
                        && ( ! self::$require_unjoined || ! $s->partiallyBooked() )
                    ) {
                        return true;
                    }
                    $s = $s->hasAltSlot() ? $s->altSlot() : null;
                } while ( $s && $guard++ < 500 );
            }
        }

        return false;
    }

    /**
     * Stable key for a leg's price: identifies a (staff, service, location, time, weekday)
     * tuple so identical legs across alternatives share one adjustPrices entry.
     *
     * @param int $staff_id
     * @param int $service_id
     * @param int|null $location_id
     * @param \DateTime $leg_start
     * @return string
     */
    private static function legKey( $staff_id, $service_id, $location_id, $leg_start )
    {
        return $staff_id . ':' . $service_id . ':' . ( $location_id ?: 0 )
            . ':' . $leg_start->format( 'H:i:s' ) . ':' . ( (int) $leg_start->format( 'w' ) + 1 );
    }

    /**
     * Resolve the staff_services price for a leg by its location — the way the cart
     * does (CartItem): the location-specific row applies only when the staff member
     * has custom services at that location (Proxy\Locations::prepareStaffLocationId,
     * cached per request), the base row (location NULL) otherwise.
     *
     * @param array $base_prices service_id => staff_id => location_id (0 = base) => price
     * @param int $service_id
     * @param int $staff_id
     * @param int $location_id 0 = no location
     * @return float
     */
    private static function resolveLegPrice( array $base_prices, $service_id, $staff_id, $location_id )
    {
        if ( ! isset( $base_prices[ $service_id ][ $staff_id ] ) ) {
            return 0.0;
        }
        $by_location = $base_prices[ $service_id ][ $staff_id ];
        $eff = 0;
        if ( $location_id && count( $by_location ) > 1 ) {
            $eff = (int) Lib\Proxy\Locations::prepareStaffLocationId( $location_id, $staff_id ) ?: 0;
        }
        if ( isset( $by_location[ $eff ] ) ) {
            return $by_location[ $eff ];
        }

        return isset( $by_location[0] ) ? $by_location[0] : 0.0;
    }

    /**
     * Walk a slot's legs (nextSlot cascade) and collect the special-hours price items,
     * keyed by legKey and deduplicated. $items is filled in place with entries the
     * batch SpecialHours::adjustPrices() consumes.
     *
     * @param Lib\Slots\Range $slot
     * @param int $service_id
     * @param int|null $location_id
     * @param array $base_prices stage_service_id => [staff_id => staff_services price]
     * @param array $items collected: legKey => ['price','staff_id','service_id','location_id','start_time','week_day']
     */
    private static function collectLegPrices( $slot, $service_id, $location_id, array $base_prices, array &$items )
    {
        $s = $slot;
        $guard = 0;
        while ( $s && $guard++ < 20 ) {
            $leg_start = $s->start()->value();
            $leg_staff = $s->staffId();
            $leg_service = $s->serviceId() ?: $service_id;
            // The leg's own location wins over the request-level one: an "any location"
            // search returns slots pinned to concrete locations, and both the base
            // price and the special-hours rules are location-specific.
            $leg_location = (int) ( $s->locationId() ?: $location_id );
            $key = self::legKey( $leg_staff, $leg_service, $leg_location, $leg_start );
            if ( ! isset( $items[ $key ] ) ) {
                $items[ $key ] = array(
                    'price' => self::resolveLegPrice( $base_prices, $leg_service, $leg_staff, $leg_location ),
                    'staff_id' => $leg_staff,
                    'service_id' => $leg_service,
                    'location_id' => $leg_location,
                    'start_time' => $leg_start->format( 'H:i:s' ),
                    'week_day' => (int) $leg_start->format( 'w' ) + 1,
                );
            }
            $s = $s->data()->hasNextSlot() ? $s->nextSlot() : null;
        }
    }

    /**
     * Serialize a slot (or an alternative from the chain) for the wizard response.
     *
     * Multi-stage services (compound/collaborative) come from the engine as a cascade
     * of legs linked via nextSlot(); the price is the sum over the legs, each adjusted
     * for its own time (special hours).
     *
     * Prices come from $price_map (keyed by legKey), pre-computed once for the whole
     * response via SpecialHours::adjustPrices — no per-leg query here.
     *
     * @param Lib\Slots\Range $slot
     * @param int $service_id
     * @param int|null $location_id
     * @param array $price_map legKey => special-hours-adjusted staff price
     * @param int $units
     * @param float $extras_price total price of the chosen extras (staff-independent),
     *        already computed once via ServiceExtras::getTotalPrice (respects multiply-nop)
     * @param float|null $flat_service_price non-null => multi-stage flat price: use this
     *        for the slot instead of summing legs (combined price method != 'nested')
     * @param int $nop number of persons
     * @return array
     */
    /**
     * A slot is busy when ANY leg of its chain is fully booked — the whole chain must be
     * bookable for the slot to be offered. Single source of truth shared by serializeSlot()
     * (the `busy` flag) and the slots stop-callback (its available-day counter), so the two
     * can never drift — e.g. a compound slot with a free head leg but a booked later leg is
     * busy in both, not "available" in one and "busy" in the other.
     *
     * @param Lib\Slots\Range $slot
     * @return bool
     */
    /**
     * Reschedule moves an existing appointment as a whole: it can not join another
     * partially booked appointment (that would leave two overlapping appointments),
     * so in this mode partially booked slots count as busy — consistently in
     * serializeSlot(), the stop-callback (via slotBusy) and slotStillAvailable().
     */
    private static $require_unjoined = false;

    private static function slotBusy( $slot )
    {
        $s = $slot;
        $guard = 0;
        while ( $s && $guard++ < 20 ) {
            if ( $s->fullyBooked() || ( self::$require_unjoined && $s->partiallyBooked() ) ) {
                return true;
            }
            $s = $s->data()->hasNextSlot() ? $s->nextSlot() : null;
        }
        return false;
    }

    /**
     * Convert WP-tz datetime strings to the current user's display time zone.
     * Used to pre-compensate Lib\Utils\Appointment::save(), which treats incoming
     * dates as display-tz and converts them back to WP tz itself. A no-op unless
     * the user is a staff member with a personal time zone.
     *
     * @param string[] $datetimes
     * @return string[]
     */
    private static function toDisplayTz( array $datetimes )
    {
        $display_tz = Lib\Utils\Common::getCurrentUserTimeZone();
        $wp_tz = Lib\Config::getWPTimeZone();
        if ( $display_tz === $wp_tz ) {
            return $datetimes;
        }
        $result = array();
        foreach ( $datetimes as $datetime ) {
            $result[] = Lib\Utils\DateTime::convertTimeZone( $datetime, $wp_tz, $display_tz );
        }

        return $result;
    }

    private static function serializeSlot( $slot, $service_id, $location_id, array $price_map, $units = 1, $extras_price = 0.0, $flat_service_price = null, $nop = 1 )
    {
        $legs = array();
        $price = 0.0;
        $busy = self::slotBusy( $slot );
        $s = $slot;
        $guard = 0;
        while ( $s && $guard++ < 20 ) {
            $leg_start = $s->start()->value();
            $leg_staff = $s->staffId();
            $leg_service = $s->serviceId() ?: $service_id;
            // Sum legs only when the price is nested; flat compound price is added once below.
            if ( $flat_service_price === null ) {
                // Same leg-location key as collectLegPrices — see the note there.
                $key = self::legKey( $leg_staff, $leg_service, (int) ( $s->locationId() ?: $location_id ), $leg_start );
                $leg_price = isset( $price_map[ $key ] ) ? $price_map[ $key ] : 0.0;
                $price += $leg_price * $units;
            }
            // `datetime` is the slot VALUE (WP time zone — goes back into save as is);
            // `time`/`display` are for the operator's eyes in the display time zone.
            $leg_display = $s->start()->toClientTz();
            $legs[] = array(
                'service_id' => $leg_service,
                'staff_id' => $leg_staff,
                'time' => $leg_display->format( 'H:i' ),
                'datetime' => $leg_start->format( 'Y-m-d H:i:s' ),
                'display' => $leg_display->format( 'Y-m-d H:i:s' ),
            );
            $s = $s->data()->hasNextSlot() ? $s->nextSlot() : null;
        }
        if ( $flat_service_price !== null ) {
            $price = $flat_service_price;
        }

        // Same as the cart (ServiceExtras::prepareServicePrice): service price × nop,
        // then + total extras price. $extras_price is precomputed once (getTotalPrice
        // does a query — must not be called per slot/alternative).
        $start = $slot->start()->value();
        $start_display = $slot->start()->toClientTz();
        $data = array(
            'time' => $start_display->format( 'H:i' ),
            'datetime' => $start->format( 'Y-m-d H:i:s' ),
            'display' => $start_display->format( 'Y-m-d H:i:s' ),
            'staff_id' => $slot->staffId(),
            'price' => round( $price * $nop + $extras_price, 2 ),
            'busy' => $busy,
        );
        // Waiting list: the whole chain must be joinable — a slot is offered as a queue
        // entry only when no leg is hard-blocked and some leg is in WAITING_LIST_STARTED
        // (its matching booking is full but the queue is open). `waiting_list` carries how
        // many are already in the queue (0 = empty queue, still joinable).
        if ( ! $busy ) {
            $wl = null;
            $s = $slot;
            $guard = 0;
            while ( $s && $guard++ < 20 ) {
                if ( $s->waitingListStarted() ) {
                    $wl = max( (int) $wl, (int) $s->data()->onWaitingList() );
                }
                $s = $s->data()->hasNextSlot() ? $s->nextSlot() : null;
            }
            if ( $wl !== null ) {
                $data['waiting_list'] = $wl;
            }
        }
        if ( count( $legs ) > 1 ) {
            $data['legs'] = $legs;
        }
        if ( $slot->data()->capacity() > 1 ) {
            $data['nop'] = $slot->data()->nop();
            $data['capacity'] = $slot->data()->capacity();
        }

        return $data;
    }
}
