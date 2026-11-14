<?php
namespace Bookly\Backend\Components\Dialogs\Appointment\Card;

use Bookly\Lib;
use Bookly\Backend\Components\Dialogs\Queue\NotificationList;
use Bookly\Lib\Entities\CustomerAppointment;
use Bookly\Lib\Notifications\Booking\Sender;

class Ajax extends Lib\Base\Ajax
{
    /**
     * @inheritDoc
     */
    protected static function permissions()
    {
        return array( '_default' => array( 'staff', 'supervisor' ) );
    }

    /**
     * Stop the request when the appointment belongs to another staff member.
     *
     * The answer repeats the "not found" wording of a missing record on purpose: a staff
     * member learns nothing about the appointments of a colleague, not even that they exist.
     *
     * @param int $appointment_id
     */
    private static function denyForeignAppointment( $appointment_id )
    {
        $appointment = Lib\Entities\Appointment::find( $appointment_id );
        if ( ! $appointment || ! Lib\Utils\Common::currentUserCanManageStaff( $appointment->getStaffId() ) ) {
            wp_send_json_error( array( 'message' => __( 'Appointment not found', 'bookly-responsive-appointment-booking-tool' ) ) );
        }
    }

    /**
     * Save the arrangement of the appointment card.
     *
     * The layout is stored as it comes, without checking blocks against the add-ons that
     * happen to be active: a layout outlives any particular set of add-ons, and dropping
     * what is unavailable today would throw away the user's arrangement of a block whose
     * add-on comes back tomorrow. Which blocks are actually shown is decided when the card
     * renders. Only the shape is validated here, so nothing but a layout reaches user meta.
     */
    public static function updateAppointmentCardSettings()
    {
        update_user_meta(
            get_current_user_id(),
            Dialog::LAYOUT_META_KEY,
            Dialog::sanitizeLayout( self::parameter( 'layout', array() ) )
        );

        wp_send_json_success();
    }

    /**
     * Change the status of a single booking.
     *
     * The card is a screen of its own, not a part of the appointment form: a status picked
     * here is written straight away, without saving the whole appointment around it.
     *
     * Notifications follow the booking wizard rather than the older queue dialog: the user
     * decides whether to notify, the optional reason travels into the message, everything
     * is sent right away, and what actually went out comes back so the operator can see it.
     * The switch remembers its last state in the same user meta the appointment form uses.
     */
    public static function changeBookingStatus()
    {
        $ca = new CustomerAppointment();
        if ( ! $ca->load( self::parameter( 'ca_id' ) ) ) {
            wp_send_json_error( array( 'message' => __( 'Booking not found', 'bookly-responsive-appointment-booking-tool' ) ) );
        }
        self::denyForeignAppointment( $ca->getAppointmentId() );

        $status = self::parameter( 'status' );
        // Statuses are core's six plus whatever the Custom Statuses add-on defines, so the
        // list is asked for rather than spelled out.
        if ( ! in_array( $status, CustomerAppointment::getStatuses(), true ) ) {
            wp_send_json_error( array( 'message' => __( 'Unknown status', 'bookly-responsive-appointment-booking-tool' ) ) );
        }

        $notify = (bool) self::parameter( 'notify' );
        // Remembered for the next time this window opens — the same meta the appointment
        // form and the booking wizard share.
        update_user_meta( get_current_user_id(), 'bookly_appointment_form_send_notifications', $notify ? '1' : '0' );

        if ( $status === $ca->getStatus() ) {
            wp_send_json_success( array( 'notifications' => array(), 'notified' => false ) );
        }

        // A status decides whether the booking holds a seat: approving somebody from the
        // waiting list into a full appointment overflows it as surely as adding seats does.
        self::failIfOverCapacity( Lib\Entities\Appointment::find( $ca->getAppointmentId() ), $ca, $status, $ca->getNumberOfPersons() );

        $ca->setStatus( $status )->save();

        $notifications = array();
        if ( $notify ) {
            $notify_list = new NotificationList();
            Sender::sendForCA(
                $ca,
                null,
                array( 'cancellation_reason' => self::parameter( 'reason' ) ),
                false,
                $notify_list
            );
            $notify_list->send();
            $notifications = $notify_list->getInfo();
        }

        wp_send_json_success( array( 'notifications' => $notifications, 'notified' => $notify ) );
    }

    /**
     * Remove one booking from its appointment.
     *
     * Notifications follow the delete dialog, which is what this operation is: before the
     * booking goes, its status is moved to the one that matches — rejected for a booking
     * still awaiting confirmation, cancelled for a confirmed one — so the customer is told
     * what actually happened to it. The optional reason travels into the message.
     *
     * A booking that is already cancelled, rejected or done is deleted in silence: the
     * customer has been told once already, and a second "your appointment is cancelled"
     * for something that ended days ago reads as a mistake. This is the rule the plugin's
     * own deletion follows (see Utils\Appointment::delete).
     *
     * What is sent goes out immediately and comes back for the operator to see, as in the
     * booking wizard; the appointment itself stays even when its last booking leaves, the
     * same as when bookings are deleted from the appointments list.
     *
     * A booking that repeats or spans a cascade takes with it whatever the operator said it
     * should — see `scopedBookings`. The bookings that go are the same set the reschedule
     * question offers, only they are not replaced afterwards.
     */
    public static function removeBooking()
    {
        $ca = new CustomerAppointment();
        if ( ! $ca->load( self::parameter( 'ca_id' ) ) ) {
            wp_send_json_error( array( 'message' => __( 'Booking not found', 'bookly-responsive-appointment-booking-tool' ) ) );
        }
        self::denyForeignAppointment( $ca->getAppointmentId() );

        // The scope may reach past this booking into a series or a cascade, and those can
        // run through other staff members. Checked before anything is touched.
        $victims = self::scopedBookings( $ca );
        $appointment_ids = array();
        foreach ( $victims as $victim ) {
            $appointment_ids[] = $victim->getAppointmentId();
        }
        self::failUnlessCanManage( $appointment_ids );

        $notify = (bool) self::parameter( 'notify' );
        update_user_meta( get_current_user_id(), 'bookly_appointment_form_send_notifications', $notify ? '1' : '0' );

        $reason = self::parameter( 'reason' );
        $notifications = array();
        $notify_list = $notify ? new NotificationList() : null;
        $removed = 0;
        foreach ( $victims as $victim ) {
            if ( $notify_list && self::markCancelled( $victim ) ) {
                Sender::sendForCA( $victim, null, array( 'cancellation_reason' => $reason ), false, $notify_list );
            }
            $victim->deleteCascade();
            $removed ++;
        }
        if ( $notify_list ) {
            $notify_list->send();
            $notifications = $notify_list->getInfo();
        }

        wp_send_json_success( array( 'notifications' => $notifications, 'notified' => $notify, 'removed' => $removed ) );
    }

    /**
     * Remove the bookings selected in the appointments list.
     *
     * The list's rows are bookings, and so is what this removes: the same operation as
     * `removeBooking`, with the same notifications and the same report, applied to a
     * selection. A row without a customer stands for an appointment nobody is booked into,
     * and that appointment goes — provided it is still empty.
     *
     * How far a removal reaches into a series or a cascade is asked only when a single
     * booking is selected. Each booking of a wider selection has a series of its own, and
     * one answer given for all of them would be a promise nobody has seen the cost of.
     *
     * Everything is checked before anything is touched: a selection that reaches an
     * appointment of another staff member is refused as a whole.
     */
    public static function removeBookings()
    {
        $ca_ids = array_values( array_unique( array_map( 'intval', (array) self::parameter( 'ca_ids', array() ) ) ) );
        $empty_ids = array_values( array_unique( array_map( 'intval', (array) self::parameter( 'appointment_ids', array() ) ) ) );

        $victims = array();
        if ( count( $ca_ids ) === 1 && ! $empty_ids ) {
            $ca = CustomerAppointment::find( $ca_ids[0] );
            if ( ! $ca ) {
                wp_send_json_error( array( 'message' => __( 'Booking not found', 'bookly-responsive-appointment-booking-tool' ) ) );
            }
            self::denyForeignAppointment( $ca->getAppointmentId() );
            $victims = self::scopedBookings( $ca );
        } elseif ( $ca_ids ) {
            // A booking deleted elsewhere in the meantime is already what was asked for.
            $victims = CustomerAppointment::query()->whereIn( 'id', $ca_ids )->find();
        }

        $appointment_ids = $empty_ids;
        foreach ( $victims as $victim ) {
            $appointment_ids[] = $victim->getAppointmentId();
        }
        $appointment_ids = array_values( array_unique( array_map( 'intval', $appointment_ids ) ) );
        foreach ( $appointment_ids as $appointment_id ) {
            if ( Lib\Entities\Appointment::find( $appointment_id ) ) {
                self::denyForeignAppointment( $appointment_id );
            }
        }
        self::failUnlessCanManage( $appointment_ids );

        $notify = (bool) self::parameter( 'notify' );
        update_user_meta( get_current_user_id(), 'bookly_appointment_form_send_notifications', $notify ? '1' : '0' );

        $reason = self::parameter( 'reason' );
        $notify_list = $notify ? new NotificationList() : null;
        $removed = 0;
        foreach ( $victims as $victim ) {
            if ( $notify_list && self::markCancelled( $victim ) ) {
                Sender::sendForCA( $victim, null, array( 'cancellation_reason' => $reason ), false, $notify_list );
            }
            $victim->deleteCascade();
            $removed ++;
        }

        // Somebody may have booked into it since the list was drawn — then it is not the
        // empty appointment the operator chose to delete.
        foreach ( $empty_ids as $appointment_id ) {
            if ( ! CustomerAppointment::query()->where( 'appointment_id', $appointment_id )->count() ) {
                Lib\Utils\Appointment::delete( $appointment_id, false );
                $removed ++;
            }
        }

        $notifications = array();
        if ( $notify_list ) {
            $notify_list->send();
            $notifications = $notify_list->getInfo();
        }

        wp_send_json_success( array( 'notifications' => $notifications, 'notified' => $notify, 'removed' => $removed ) );
    }

    /**
     * Move a booking to the status that says what happened to it, before it goes.
     *
     * Not saved: the row is about to be deleted, and the status is set only so the message
     * built from it says "cancelled" rather than "pending". A booking already cancelled,
     * rejected or done is left alone and told nothing — the customer has been told once,
     * and a second cancellation of something long over reads as a mistake.
     *
     * @param CustomerAppointment $ca
     * @return bool Whether the customer is to be told at all
     */
    private static function markCancelled( CustomerAppointment $ca )
    {
        switch ( $ca->getStatus() ) {
            case CustomerAppointment::STATUS_CANCELLED:
            case CustomerAppointment::STATUS_REJECTED:
            case CustomerAppointment::STATUS_DONE:
                return false;
            case CustomerAppointment::STATUS_PENDING:
            case CustomerAppointment::STATUS_WAITLISTED:
                $ca->setStatus( CustomerAppointment::STATUS_REJECTED );
                break;
            case CustomerAppointment::STATUS_APPROVED:
                $ca->setStatus( CustomerAppointment::STATUS_CANCELLED );
                break;
            default:
                $ca->setStatus(
                    in_array( $ca->getStatus(), Lib\Proxy\CustomStatuses::prepareBusyStatuses( array() ) )
                        ? CustomerAppointment::STATUS_CANCELLED
                        : CustomerAppointment::STATUS_REJECTED
                );
        }

        return true;
    }

    /**
     * Delete the appointment with everything booked in it.
     *
     * The card asks and reports the same way it does for a status change or for removing
     * one customer: notifications go out immediately and what went out comes back, instead
     * of the queue dialog the appointments list and the calendar open. Three operations of
     * one window must not make three different promises — "already sent, here is what" in
     * two of them and "here is what will be sent, press Send" in the third.
     *
     * Which bookings are told what is not decided here: the deletion itself belongs to the
     * plugin and is shared with the list, the calendar and the mobile cabinet, so it is
     * asked to collect into our list rather than into the queue.
     *
     * Named after removeBooking rather than after the operation, and deliberately not
     * `deleteAppointment`: an action's name is the method's name in snake case, and that
     * one already belongs to the delete dialog — both handlers would run on one request.
     *
     * An appointment that repeats or is a stage of a cascade reaches further than itself,
     * and how far is the operator's answer — see `scopedAppointments`.
     */
    public static function removeAppointment()
    {
        $appointment = new Lib\Entities\Appointment();
        if ( ! $appointment->load( self::parameter( 'appointment_id' ) ) ) {
            wp_send_json_error( array( 'message' => __( 'Appointment not found', 'bookly-responsive-appointment-booking-tool' ) ) );
        }
        self::denyForeignAppointment( $appointment->getId() );

        // Same as for a booking: the scope may reach appointments of other staff members.
        $ids = self::scopedAppointments( $appointment );
        self::failUnlessCanManage( $ids );

        $notify = (bool) self::parameter( 'notify' );
        update_user_meta( get_current_user_id(), 'bookly_appointment_form_send_notifications', $notify ? '1' : '0' );

        $notify_list = new NotificationList();
        foreach ( $ids as $id ) {
            Lib\Utils\Appointment::delete( $id, $notify, self::parameter( 'reason' ), $notify_list );
        }

        $notifications = array();
        if ( $notify ) {
            $notify_list->send();
            $notifications = $notify_list->getInfo();
        }

        wp_send_json_success( array( 'notifications' => $notifications, 'notified' => $notify, 'removed' => count( $ids ) ) );
    }

    /**
     * What a repeating or compound appointment belongs to, for the delete window to ask
     * with.
     *
     * The same two questions the booking wizard asks before moving an appointment — this
     * stage or all of them, this visit or the ones after it too — asked by the window that
     * deletes. Same shapes, so the two screens read the series and the cascade the same
     * way; deletion adds `bookings`, the number of customers behind each visit, because
     * deleting reaches people that moving would only have moved.
     *
     * Answered lazily, when the delete window opens, rather than carried by the card: the
     * card is opened to look at an appointment far more often than to delete one, and this
     * costs two queries per look that most looks would not use.
     */
    public static function getDeletionScope()
    {
        $ca = null;
        if ( self::parameter( 'ca_id' ) ) {
            $ca = CustomerAppointment::find( (int) self::parameter( 'ca_id' ) );
            if ( ! $ca ) {
                wp_send_json_error( array( 'message' => __( 'Booking not found', 'bookly-responsive-appointment-booking-tool' ) ) );
            }
            $appointment = Lib\Entities\Appointment::find( $ca->getAppointmentId() );
        } else {
            $appointment = Lib\Entities\Appointment::find( (int) self::parameter( 'appointment_id' ) );
        }
        if ( ! $appointment ) {
            wp_send_json_error( array( 'message' => __( 'Appointment not found', 'bookly-responsive-appointment-booking-tool' ) ) );
        }
        self::denyForeignAppointment( $appointment->getId() );

        $context = Lib\Proxy\RecurringAppointments::seriesContext( array(), $appointment, $ca );
        $series = isset( $context['series'] ) ? $context['series'] : null;
        $cascade = Lib\Utils\Appointment::cascadeContext( $appointment, $ca );
        // Only for a whole-appointment delete: removing one customer removes one booking per
        // visit and no more, and a count of everybody else there would be a promise about
        // people who stay.
        if ( ! $ca ) {
            if ( $series ) {
                $series['visits'] = self::countBookings( $series['visits'] );
            }
            if ( $cascade ) {
                $cascade['stages'] = self::countBookings( $cascade['stages'] );
            }
        }

        wp_send_json_success( array(
            'series' => $series,
            'cascade' => $cascade,
        ) );
    }

    /**
     * Say how many bookings each appointment of a list holds.
     *
     * The delete window states how many customers go with the answer — it is the whole
     * point of asking — and only the server can tell: the card holds the one appointment
     * it was opened on and knows nothing about the rest of the series.
     *
     * @param array[] $rows Each with an `appointment_id`
     * @return array[] The same rows, each with `bookings`
     */
    private static function countBookings( array $rows )
    {
        $ids = array();
        foreach ( $rows as $row ) {
            $ids[] = (int) $row['appointment_id'];
        }
        $counts = array();
        foreach ( CustomerAppointment::query( 'ca' )
                      ->select( 'ca.appointment_id, COUNT(*) AS total' )
                      ->whereIn( 'ca.appointment_id', $ids )
                      ->groupBy( 'ca.appointment_id' )
                      ->fetchArray() as $row ) {
            $counts[ (int) $row['appointment_id'] ] = (int) $row['total'];
        }
        foreach ( $rows as &$row ) {
            $row['bookings'] = isset( $counts[ $row['appointment_id'] ] ) ? $counts[ $row['appointment_id'] ] : 0;
        }
        unset( $row );

        return $rows;
    }

    /**
     * Statuses in which a booking holds a seat — core's pending and approved, plus whatever
     * the Custom Statuses add-on marks as busy.
     *
     * @return string[]
     */
    private static function busyStatuses()
    {
        return Lib\Proxy\CustomStatuses::prepareBusyStatuses( array(
            CustomerAppointment::STATUS_PENDING,
            CustomerAppointment::STATUS_APPROVED,
        ) );
    }

    /**
     * Refuse when the appointment would hold more seats than the staff member's service
     * allows, this booking counted with the status and seats it is about to get.
     *
     * Every way the card changes a booking goes through here — the status window and the
     * booking window alike — or one would refuse what the other lets through.
     *
     * Only a change that leaves the booking holding a seat can be refused. Cancelling,
     * rejecting or moving somebody to the waiting list frees a seat, and an appointment that
     * is already over its capacity (the booking wizard can overbook on purpose) must still
     * let the operator do that.
     *
     * @param Lib\Entities\Appointment $appointment
     * @param CustomerAppointment $ca
     * @param string $status
     * @param int $number_of_persons
     */
    private static function failIfOverCapacity( Lib\Entities\Appointment $appointment, CustomerAppointment $ca, $status, $number_of_persons )
    {
        $busy_statuses = self::busyStatuses();
        if ( ! in_array( $status, $busy_statuses ) || ! $appointment->getServiceId() ) {
            return;
        }

        // Capacity is a property of the staff member's service, and it counts only the
        // bookings that hold a seat.
        $taken = (int) $number_of_persons;
        foreach ( $appointment->getCustomerAppointments() as $other ) {
            if ( $other->getId() != $ca->getId() && in_array( $other->getStatus(), $busy_statuses ) ) {
                $taken += $other->getNumberOfPersons();
            }
        }

        $staff_service = new Lib\Entities\StaffService();
        if ( $staff_service->loadBy( array(
            'staff_id' => $appointment->getStaffId(),
            'service_id' => $appointment->getServiceId(),
            'location_id' => null,
        ) ) && $taken > $staff_service->getCapacityMax() ) {
            wp_send_json_error( array(
                'message' => sprintf(
                    __( 'The number of persons exceeds the service capacity (%d)', 'bookly-responsive-appointment-booking-tool' ),
                    (int) $staff_service->getCapacityMax()
                ),
            ) );
        }
    }

    /**
     * Refuse an operation whose scope reaches an appointment the current user may not act on.
     *
     * The scope is the operator's choice — this visit, the rest of the series, every stage of
     * a cascade — and a series or a collaborative service can run through several staff
     * members. The operation is refused as a whole rather than trimmed to what is allowed:
     * carrying out part of what was asked, without saying so, is worse than saying no.
     *
     * @param int[] $appointment_ids
     */
    private static function failUnlessCanManage( array $appointment_ids )
    {
        $allowed = Lib\Utils\Common::getCurrentUserStaffIds();
        if ( ! $appointment_ids || $allowed === null ) {
            return;
        }
        $staff_ids = Lib\Entities\Appointment::query()
            ->whereIn( 'id', array_map( 'intval', $appointment_ids ) )
            ->fetchCol( 'staff_id' );
        foreach ( $staff_ids as $staff_id ) {
            if ( ! in_array( (int) $staff_id, $allowed, true ) ) {
                wp_send_json_error( array( 'message' => __( 'The selected scope includes appointments of other staff members', 'bookly-responsive-appointment-booking-tool' ) ) );
            }
        }
    }

    /**
     * The appointments a whole-appointment delete covers, the one asked for included.
     *
     * Two independent answers, and either may widen the set: `cascade_scope` = cascade takes
     * every stage of the compound or collaborative service, `scope` = next or all takes the
     * series from this visit on, or all of it. Both are the wizard's own words for the same
     * two questions, so an operator who has moved a series once already knows what they mean.
     *
     * The set is read from the same context the window was drawn from — asked again rather
     * than passed in as a list of ids: what the browser was shown a minute ago is a picture,
     * and deleting by a picture deletes what the picture had, not what exists.
     *
     * @param Lib\Entities\Appointment $appointment
     * @return int[]
     */
    private static function scopedAppointments( Lib\Entities\Appointment $appointment )
    {
        $ids = array( (int) $appointment->getId() );

        if ( self::parameter( 'cascade_scope' ) === 'cascade' ) {
            $cascade = Lib\Utils\Appointment::cascadeContext( $appointment );
            if ( $cascade ) {
                foreach ( $cascade['stages'] as $stage ) {
                    $ids[] = (int) $stage['appointment_id'];
                }
            }
        }

        $scope = self::parameter( 'scope' );
        if ( $scope === 'next' || $scope === 'all' ) {
            $context = Lib\Proxy\RecurringAppointments::seriesContext( array(), $appointment, null );
            if ( isset( $context['series'] ) ) {
                foreach ( $context['series']['visits'] as $visit ) {
                    if ( $scope === 'all' || $visit['datetime'] >= $appointment->getStartDate() ) {
                        $ids[] = (int) $visit['appointment_id'];
                    }
                }
            }
        }

        return array_values( array_unique( $ids ) );
    }

    /**
     * The bookings a one-customer delete covers, the one asked for included.
     *
     * The booking-level twin of `scopedAppointments`, and it differs in what it reaches for:
     * bookings, not appointments. Removing a customer from a series must not empty the
     * visits — the others booked into them stay, and an appointment left with nobody is
     * deleted by `deleteCascade` on its own.
     *
     * @param CustomerAppointment $ca
     * @return CustomerAppointment[]
     */
    private static function scopedBookings( CustomerAppointment $ca )
    {
        $ids = array( (int) $ca->getId() );
        $appointment = Lib\Entities\Appointment::find( $ca->getAppointmentId() );
        if ( ! $appointment ) {
            return array( $ca );
        }

        if ( self::parameter( 'cascade_scope' ) === 'cascade' ) {
            $cascade = Lib\Utils\Appointment::cascadeContext( $appointment, $ca );
            if ( $cascade ) {
                foreach ( $cascade['stages'] as $stage ) {
                    $ids[] = (int) $stage['ca_id'];
                }
            }
        }

        $scope = self::parameter( 'scope' );
        if ( $scope === 'next' || $scope === 'all' ) {
            $context = Lib\Proxy\RecurringAppointments::seriesContext( array(), $appointment, $ca );
            if ( isset( $context['series'] ) ) {
                foreach ( $context['series']['visits'] as $visit ) {
                    if ( $scope === 'all' || $visit['datetime'] >= $appointment->getStartDate() ) {
                        $ids[] = (int) $visit['ca_id'];
                    }
                }
            }
        }

        $ids = array_values( array_unique( $ids ) );
        $bookings = array();
        foreach ( $ids as $id ) {
            $booking = CustomerAppointment::find( $id );
            if ( $booking ) {
                $bookings[] = $booking;
            }
        }

        return $bookings;
    }

    /**
     * Carry out what the payment and package dialogs decided.
     *
     * Those dialogs belong to the appointment form and behave accordingly: they do not
     * save anything, they WRITE AN INTENTION into the booking object — "create a payment
     * of this much", "attach that package" — and the form carries it out later, when the
     * whole appointment is saved. The card has no such moment: it opens a dialog over a
     * booking that already exists, the operator presses Apply, and the intention dies with
     * the window. That is why the same three lines the form would run at save time are run
     * here instead, against the one booking the dialog was opened for.
     *
     * Nothing is invented: attaching is `setPaymentId`, creating goes through the Pro
     * proxy and packages through the Packages proxy — the very calls
     * Entities\Appointment::saveCustomerAppointments makes.
     */
    public static function applyBookingAttachment()
    {
        $ca = new CustomerAppointment();
        if ( ! $ca->load( self::parameter( 'ca_id' ) ) ) {
            wp_send_json_error( array( 'message' => __( 'Booking not found', 'bookly-responsive-appointment-booking-tool' ) ) );
        }
        self::denyForeignAppointment( $ca->getAppointmentId() );

        $payment_action = self::parameter( 'payment_action' );
        $payment_for = self::parameter( 'payment_for' ) === 'series' ? 'series' : 'current';
        $data = array(
            'id' => $ca->getCustomerId(),
            'payment_action' => $payment_action,
            'payment_for' => $payment_for,
            'payment_id' => (int) self::parameter( 'payment_id' ),
            'payment_price' => self::parameter( 'payment_price' ),
            'payment_tax' => self::parameter( 'payment_tax' ),
        );

        if ( $payment_action === 'attach' || $payment_action === 'create' ) {
            // "For the whole series" is a different operation with a different owner: the
            // payment covers every booking of that customer in the series, and only the
            // recurring add-on knows how to spread it over them.
            $series = $payment_for === 'series' && $ca->getSeriesId()
                ? Lib\Entities\Series::find( $ca->getSeriesId() )
                : null;
            if ( $series ) {
                if ( $payment_action === 'attach' ) {
                    Lib\Proxy\RecurringAppointments::attachBackendPayment( $series, $data );
                } else {
                    Lib\Proxy\RecurringAppointments::createBackendPayment( $series, $data );
                }
            } elseif ( $payment_action === 'attach' ) {
                $ca->setPaymentId( $data['payment_id'] ?: null )->save();
            } else {
                Lib\Proxy\Pro::createBackendPayment( $data, $ca );
            }
        }

        if ( self::parameter( 'package_action' ) ) {
            $appointment = new Lib\Entities\Appointment();
            if ( ! $appointment->load( $ca->getAppointmentId() ) ) {
                wp_send_json_error( array( 'message' => __( 'Appointment not found', 'bookly-responsive-appointment-booking-tool' ) ) );
            }
            Lib\Proxy\Packages::attachPackages(
                $ca,
                array(
                    'package_action' => self::parameter( 'package_action' ),
                    'package_action_id' => (int) self::parameter( 'package_action_id' ),
                ),
                $appointment->getStaffId(),
                $appointment->getLocationId()
            );
        }

        wp_send_json_success();
    }

    /**
     * Save the internal note of an appointment.
     *
     * The note is the one thing on the card filled in from the card itself: it carries no
     * consequences — nobody is notified, nothing is rescheduled — so it needs neither a
     * procedure nor the whole appointment form around it.
     */
    public static function updateAppointmentNote()
    {
        $appointment = new Lib\Entities\Appointment();
        if ( ! $appointment->load( self::parameter( 'appointment_id' ) ) ) {
            wp_send_json_error( array( 'message' => __( 'Appointment not found', 'bookly-responsive-appointment-booking-tool' ) ) );
        }
        self::denyForeignAppointment( $appointment->getId() );

        $appointment->setInternalNote( self::parameter( 'internal_note', '' ) )->save();

        wp_send_json_success();
    }

    /**
     * Save the details of a single booking: status, seats, time zone, notes, custom fields,
     * extras.
     *
     * Notifications are NOT sent from here even when the status changes: telling the
     * customer is a decision of its own and is asked for by the status window
     * (see changeBookingStatus). A form that says nothing about notifications must not
     * send them silently.
     *
     * Only this booking is touched, but two things belong to the appointment around it and
     * are kept in step: the seats taken must still fit the service capacity, and the extras
     * duration of the appointment is the longest among its bookings.
     */
    public static function updateBooking()
    {
        $ca = new CustomerAppointment();
        if ( ! $ca->load( self::parameter( 'ca_id' ) ) ) {
            wp_send_json_error( array( 'message' => __( 'Booking not found', 'bookly-responsive-appointment-booking-tool' ) ) );
        }
        self::denyForeignAppointment( $ca->getAppointmentId() );

        $appointment = new Lib\Entities\Appointment();
        if ( ! $appointment->load( $ca->getAppointmentId() ) ) {
            wp_send_json_error( array( 'message' => __( 'Booking not found', 'bookly-responsive-appointment-booking-tool' ) ) );
        }

        $service_id = $appointment->getServiceId();
        $number_of_persons = max( 1, (int) self::parameter( 'number_of_persons', 1 ) );

        // Extras are stored per booking, but only those the service actually offers —
        // the same filter the appointment form applies before saving.
        $allowed_extras = array();
        $extras_consider_duration = false;
        if ( Lib\Config::serviceExtrasActive() ) {
            foreach ( Lib\Proxy\ServiceExtras::findByServiceId( $service_id ) ?: array() as $extra ) {
                $allowed_extras[] = $extra->getId();
            }
            $extras_consider_duration = (bool) ( $allowed_extras && Lib\Proxy\ServiceExtras::considerDuration() );
        }
        $extras = array();
        foreach ( (array) json_decode( self::parameter( 'extras', '{}' ), true ) as $id => $qty ) {
            if ( in_array( $id, $allowed_extras ) && $qty > 0 ) {
                $extras[ $id ] = $qty;
            }
        }

        $status = self::parameter( 'status', $ca->getStatus() );
        if ( ! in_array( $status, CustomerAppointment::getStatuses(), true ) ) {
            $status = $ca->getStatus();
        }

        self::failIfOverCapacity( $appointment, $ca, $status, $number_of_persons );

        // The appointment takes the longest extras duration among the bookings that hold a
        // seat, this one counted with its NEW status and extras.
        $busy_statuses = self::busyStatuses();
        $max_extras_duration = 0;
        if ( $extras_consider_duration ) {
            if ( in_array( $status, $busy_statuses ) ) {
                $max_extras_duration = (int) Lib\Proxy\ServiceExtras::getTotalDuration( $extras );
            }
            foreach ( $appointment->getCustomerAppointments() as $other ) {
                if ( $other->getId() == $ca->getId() || ! in_array( $other->getStatus(), $busy_statuses ) ) {
                    continue;
                }
                $duration = (int) Lib\Proxy\ServiceExtras::getTotalDuration( (array) json_decode( $other->getExtras(), true ) );
                if ( $duration > $max_extras_duration ) {
                    $max_extras_duration = $duration;
                }
            }
        }

        $ca
            ->setStatus( $status )
            ->setNumberOfPersons( $number_of_persons )
            ->setNotes( self::parameter( 'notes', '' ) )
            ->setExtras( json_encode( $extras ) )
            ->setCustomFields( self::parameter( 'custom_fields', '[]' ) );

        $time_zone = self::parameter( 'time_zone' );
        if ( Lib\Config::proActive() ) {
            $ca->setTimeZone( $time_zone ?: null );
        }
        $ca->save();
        // A file uploaded in the form stays unattached until the booking that refers to it is saved.
        Lib\Proxy\Files::attachCFFiles( (array) json_decode( $ca->getCustomFields(), true ), $ca );

        // Extras can lengthen an appointment, and the appointment takes the longest of its
        // bookings. Left alone it would keep a duration nobody asked for.
        if ( $extras_consider_duration && (int) $appointment->getExtrasDuration() !== $max_extras_duration ) {
            $appointment->setExtrasDuration( $max_extras_duration )->save();
        }

        wp_send_json_success();
    }
}
