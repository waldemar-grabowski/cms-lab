<?php
namespace Bookly\Frontend\Modules\Ai\BookingTools;

use Bookly\Frontend\Modules\Ai;
use Bookly\Frontend\Modules\Ai\Checkout;
use Bookly\Frontend\Modules\Booking\Proxy as BookingProxy;
use Bookly\Lib;

class CreateBooking implements ToolInterface
{
    /** @var Lib\Entities\AiConversation|null */
    private $conversation;

    /**
     * @param Lib\Entities\AiConversation|null $conversation Passed by the worker only; schema building needs none.
     */
    public function __construct( $conversation = null )
    {
        $this->conversation = $conversation;
    }

    /**
     * @inheritDoc
     */
    public function getName()
    {
        return 'create_booking';
    }

    /**
     * @inheritDoc
     */
    public function getSchema()
    {
        $properties = array(
            'service_id' => array( 'type' => 'integer', 'description' => 'Service id, from the catalog.' ),
            'staff_id' => array( 'type' => 'integer', 'description' => 'Staff member id, from the catalog.' ),
            'start_date' => array( 'type' => 'string', 'description' => 'Appointment start date/time, format "YYYY-MM-DD HH:MM:SS" (24-hour).' ),
        );

        // Only where the business has locations (Concepts).
        if ( Ai\Concepts::forSite()->has( Ai\Concepts::LOCATION ) ) {
            $properties['location_id'] = array( 'type' => 'integer', 'description' => Locations::ARGUMENT );
        }

        $properties['full_name'] = array( 'type' => 'string', 'description' => "Customer's full name." );
        $properties['email'] = array( 'type' => 'string', 'description' => "Customer's email address. Provide this or phone (or both)." );
        $properties['phone'] = array( 'type' => 'string', 'description' => "Customer's phone number. Provide this or email (or both)." );
        $properties['notes'] = array( 'type' => 'string', 'description' => 'Optional free-text note from the customer about this booking.' );
        // For a customer who says how they pay instead of pressing a button on the payment
        // card ("cash", "through Square") - also before any card was shown. Which online ways
        // a booking can be paid depends on its service, staff member and customer, so their
        // names come from create_booking's own result for this booking; paying at the
        // appointment is named here, as a customer often says it up front. Without online
        // gateways there is no card and nothing to choose (Concepts::PAYMENT_*).
        if ( Ai\Concepts::forSite()->payment() === Ai\Concepts::PAYMENT_ONLINE ) {
            $properties['pay_with'] = array(
                'type' => 'string',
                'description' => 'Only when the customer has said in their own words how they will pay, before or after the payment card is shown: '
                    . ( Ai\Concepts::forSite()->has( Ai\Concepts::PAY_LOCALLY ) ? '"' . Lib\Entities\Payment::TYPE_LOCAL . '" for paying at the appointment; for another way, ' : '' )
                    . 'the code of the matching payment card button from a previous create_booking result. Omit otherwise.',
            );
        }
        if ( Ai\Concepts::forSite()->has( Ai\Concepts::EXTRA ) ) {
            $properties['extras'] = array(
                'type' => 'array',
                'description' => 'The extras the customer chose. Omit or pass an empty array if none.',
                'items' => array(
                    'type' => 'object',
                    'properties' => array(
                        'extra_id' => array( 'type' => 'integer', 'description' => 'Extra id, from the service\'s extras list.' ),
                        'quantity' => array( 'type' => 'integer', 'description' => 'How many of this extra.' ),
                    ),
                    'required' => array( 'extra_id', 'quantity' ),
                ),
            );
        }
        if ( Ai\Concepts::forSite()->has( Ai\Concepts::GROUP ) ) {
            $properties['persons'] = array( 'type' => 'integer', 'description' => SlotLookup::PERSONS_ARGUMENT );
        }

        return array(
            'name' => $this->getName(),
            'description' => 'Make a booking, once the customer has picked a start time and given their full name and an email or a phone number. It checks the start time itself and, if it is taken, returns the nearest free ones. Its result says whether the booking is confirmed or what is still needed - read it carefully and tell the customer what it actually says.',
            'parameters' => array(
                'type' => 'object',
                'properties' => $properties,
                'required' => array( 'service_id', 'staff_id', 'start_date', 'full_name' ),
            ),
        );
    }

    /**
     * @inheritDoc
     */
    public function execute( array $arguments )
    {
        $service_id  = isset( $arguments['service_id'] ) ? (int) $arguments['service_id'] : 0;
        $staff_id    = isset( $arguments['staff_id'] ) ? (int) $arguments['staff_id'] : 0;
        $start_date  = isset( $arguments['start_date'] ) ? trim( (string) $arguments['start_date'] ) : '';
        $location_id = isset( $arguments['location_id'] ) ? (int) $arguments['location_id'] : 0;
        $full_name  = isset( $arguments['full_name'] ) ? trim( (string) $arguments['full_name'] ) : '';
        $email      = isset( $arguments['email'] ) ? trim( (string) $arguments['email'] ) : '';
        $phone      = isset( $arguments['phone'] ) ? trim( (string) $arguments['phone'] ) : '';
        $notes      = isset( $arguments['notes'] ) ? trim( (string) $arguments['notes'] ) : '';
        $extras_arg = isset( $arguments['extras'] ) && is_array( $arguments['extras'] ) ? $arguments['extras'] : array();
        $pay_with = isset( $arguments['pay_with'] ) ? trim( (string) $arguments['pay_with'] ) : '';

        if ( $full_name === '' ) {
            return 'Error: the customer\'s full name is missing. Ask them for it, then call create_booking again.';
        }
        if ( $email === '' && $phone === '' ) {
            return 'Error: a contact is missing. Ask the customer for their email or phone number, then call create_booking again.';
        }

        $service = Lib\Entities\Service::find( $service_id );
        if ( ! $service || $service->getType() !== Lib\Entities\Service::TYPE_SIMPLE || $service->getVisibility() !== Lib\Entities\Service::VISIBILITY_PUBLIC ) {
            return 'Error: unknown service_id. Use a service id from the catalog.';
        }

        $staff = Lib\Entities\Staff::find( $staff_id );
        if ( ! $staff || $staff->getVisibility() !== 'public' ) {
            return 'Error: unknown staff_id. Use a staff member id from the catalog.';
        }

        if ( $location_id && ! Lib\Proxy\Locations::findById( $location_id ) ) {
            return 'Error: unknown location_id. Use a location id from the catalog.';
        }
        $not_there = Locations::checkStaff( $staff, $location_id );
        if ( $not_there !== null ) {
            return $not_there;
        }

        $link = new Lib\Entities\StaffService();
        $link->loadBy( array( 'staff_id' => $staff_id, 'service_id' => $service_id ) );
        if ( ! $link->isLoaded() ) {
            return 'Error: this staff member does not provide this service. The catalog lists the ones who do in the service\'s staff_ids or price_by_staff.';
        }

        try {
            $extras = ExtrasInput::parse( $service_id, $extras_arg );
        } catch ( \Exception $e ) {
            return 'Error: ' . $e->getMessage();
        }

        $nop = isset( $arguments['persons'] ) ? max( 1, (int) $arguments['persons'] ) : 1;
        $wrong_persons = SlotLookup::checkPersons( $service, $staff_id, $location_id, $nop );
        if ( $wrong_persons !== null ) {
            return $wrong_persons;
        }

        $start = \DateTime::createFromFormat( 'Y-m-d H:i:s', $start_date );
        if ( ! $start ) {
            return 'Error: start_date must be in the format YYYY-MM-DD HH:MM:SS. Call create_booking again with it in that format.';
        }
        $end_date = ( clone $start )->modify( '+' . $service->getDuration() . ' seconds' )->format( 'Y-m-d H:i:s' );

        // Before the slot check: the booking the payment card already made holds this very
        // time, and the model asks for it again to check "is it booked?" - "not available"
        // would tell the customer it is not.
        $booked = $this->describeBooked( $service, $staff, $start_date );
        if ( $booked !== null ) {
            return $booked;
        }

        // Also before the slot check: the order of a payment still in its window already holds
        // its time, and "not available" would send the customer after another one for nothing.
        if ( $this->conversation && $this->conversation->checkoutInProgress() ) {
            return 'Error: the customer has not finished paying for the previous booking yet, nothing new was booked. Ask them to complete or cancel that payment in its window first.';
        }

        // Check the slot here, not only in check_availability: the model is not asked to call that
        // first, and time passes between calls. Done before the customer lookup so a refusal
        // leaves no customer record behind.
        $day_times = array();
        if ( ! SlotLookup::isBookable( $service, $staff_id, $start, $location_id ?: null, $extras, $day_times, $nop ) ) {
            return 'Error: this time is not available (anymore), nothing was booked. ' . SlotLookup::describeAlternatives( $day_times, $start, $service ) . ' Once the customer picks one, call create_booking again with it.';
        }

        // Find-or-create customer, same exact-match lookup as Validator::postValidateCustomer()
        // minus the WP-account/verification machinery (anonymous chat visitor).
        $customer = new Lib\Entities\Customer();
        if ( $email !== '' ) {
            $customer->loadBy( array( 'email' => $email ) );
        }
        if ( ! $customer->isLoaded() && $phone !== '' ) {
            $customer->loadBy( array( 'phone' => $phone ) );
        }
        if ( ! $customer->isLoaded() ) {
            $customer->setFullName( $full_name )->setEmail( $email )->setPhone( $phone )->save();
        }
        if ( ! $customer->getId() ) {
            return 'Error: the booking system could not save the customer\'s details, nothing was booked. Apologise and suggest contacting the business directly.';
        }

        // The only rule SlotLookup can't check without a customer (Pro; no-op otherwise).
        if ( $service->appointmentsLimitReached( $customer->getId(), array( $start_date ) ) ) {
            return 'Error: this customer has reached the maximum number of appointments allowed for this service and cannot book another one. Tell them so; they can contact the business if they need more.';
        }

        // Draft for the checkout to rebuild the booking later; nothing is written to the
        // appointment tables until Gateway::createIntent() (or book() below).
        $draft = array(
            'customer_id' => $customer->getId(),
            'service_id' => $service_id,
            'staff_id' => $staff_id,
            'location_id' => $location_id ?: null,
            'start_date' => $start_date,
            'extras' => $extras,
            'notes' => $notes,
            'number_of_persons' => $nop,
        );

        // No conversation to attach a draft to - book outright.
        if ( ! $this->conversation ) {
            return self::book( $customer, $service, $staff, $draft, $end_date );
        }

        // No payment system switched on, or a customer group that skips payment (what
        // Payment\Request::getGateway() answers with a NullGateway) - nothing to charge.
        if ( Lib\Config::paymentStepDisabled() || BookingProxy\CustomerGroups::getSkipPayment( $customer ) ) {
            return self::book( $customer, $service, $staff, $draft, $end_date );
        }

        // Every call puts a card of its own in the chat, under the reply that explains it - also
        // with the same details, which is how the model shows the card again when asked.
        $draft['card'] = uniqid();

        $this->conversation
            ->setBookingDataArray( $draft )
            ->setBookingStatus( Lib\Entities\AiConversation::BOOKING_PENDING )
            ->setOrderId( null )
            ->save();

        $options = Checkout::getPaymentOptions( $this->conversation );

        if ( ! $options ) {
            $this->conversation->resetBooking()->save();

            return 'Error: this business accepts no payment method that works for this service and this staff member together. Suggest a different service or staff member.';
        }

        // Priced at zero after taxes/discounts - nothing to pay.
        if ( $options['pay_now_raw'] <= 0 ) {
            $this->conversation->resetBooking()->save();

            return self::book( $customer, $service, $staff, $draft, $end_date, 'nothing to pay' );
        }

        $names = wp_list_pluck( $options['gateways'], 'name' );
        $at_appointment = in_array( Lib\Entities\Payment::TYPE_LOCAL, $names, true );

        // Paying at the appointment - said in words, or the only gateway, which needs no choice:
        // the same auto-select as the modern booking form (ModernBookingForm\Lib\Request::checkStep()),
        // which keeps its step for coupons and gift cards; the chat's payment card has neither.
        // Booked the way the card's button does, with a "local" payment as the form makes it.
        if ( $at_appointment && ( $pay_with === Lib\Entities\Payment::TYPE_LOCAL || count( $names ) === 1 ) ) {
            $booked = Checkout::payLocally( $this->conversation )
                ? Checkout::bookedResult( $this->conversation )
                : null;

            return $booked !== null
                ? $booked
                : 'Error: the booking system could not save this booking, nothing was booked. Apologise and suggest trying again in a moment or contacting the business directly.';
        }

        // Quote deposit and full price separately, or a deposit reads as a discount.
        $amount = $options['deposit']
            ? sprintf( '%s now (deposit) of %s total', $options['due_now'], $options['total'] )
            : $options['total'];
        $what = sprintf( '%s for %s with %s at %s', $amount, $service->getTitle(), $staff->getFullName(), $start_date );

        // An online method named in words: the card keeps only its button. Paying still takes
        // a press - the payment window may only be opened from the customer's own click.
        if ( in_array( $pay_with, $names, true ) ) {
            $draft['gateway'] = $pay_with;
            $this->conversation->setBookingDataArray( $draft )->save();

            return sprintf(
                'Not booked yet — waiting for the online payment: %s. The payment card now shows just the button "%s"; ask the customer to press it and pay in the window it opens. The booking is confirmed only once the payment goes through, and the chat then says so by itself. If they change their mind, call create_booking again with the same details and the other way, or without pay_with to show all of them again.',
                $what, self::buttonTitle( $options['gateways'], $pay_with )
            );
        }

        $unavailable = '';
        if ( $pay_with !== '' ) {
            $unavailable = $pay_with === Lib\Entities\Payment::TYPE_LOCAL
                ? 'Paying at the appointment is not possible for this booking (its service, staff member or customer does not allow it) - tell the customer so. '
                : 'The way the customer named is not one this booking can be paid with - tell them so. ';
        }

        return sprintf(
            'Not booked yet — payment needed: %s. The chat now shows a payment card where the customer chooses how to pay and pays. %sTell them the amount and to choose on the card; do not list or ask about payment methods, the card shows them. To show the card again, call create_booking again with the same details. The card\'s buttons, for matching what the customer says if they name a way to pay in words: %s. If they do, call create_booking again with the same details and pay_with set to the matching one; if their words fit more than one button, ask which of those they mean.',
            $what,
            $unavailable,
            self::describeButtons( $options['gateways'] )
        );
    }

    /**
     * The booking this conversation's checkout has made, when it is the one asked for.
     * Also while its payment window is still open, once the webhook has completed the payment:
     * the conversation itself only learns that when the window closes.
     *
     * @param Lib\Entities\Service $service
     * @param Lib\Entities\Staff   $staff
     * @param string               $start_date
     * @return string|null null when the conversation has made no such booking
     */
    private function describeBooked( Lib\Entities\Service $service, Lib\Entities\Staff $staff, $start_date )
    {
        if ( ! $this->conversation || ! $this->conversation->getOrderId() ) {
            return null;
        }
        if ( $this->conversation->checkoutInProgress() ) {
            $payment = new Lib\Entities\Payment();
            if ( ! $payment->loadBy( array( 'order_id' => $this->conversation->getOrderId() ) ) || $payment->getStatus() !== Lib\Entities\Payment::STATUS_COMPLETED ) {
                return null;
            }
        } elseif ( ! in_array( $this->conversation->getBookingStatus(), array(
            Lib\Entities\AiConversation::BOOKING_PAID,
            Lib\Entities\AiConversation::BOOKING_AWAITING,
        ), true ) ) {
            return null;
        }
        $draft = $this->conversation->getBookingDataArray();
        // Loose: the draft keeps the ids as the model passed them.
        if ( $draft['service_id'] != $service->getId() || $draft['staff_id'] != $staff->getId() || $draft['start_date'] !== $start_date ) {
            return null;
        }

        $booked = Checkout::bookedResult( $this->conversation );

        return $booked === null
            ? null
            : 'Already booked in this conversation, nothing new was booked. ' . $booked;
    }

    /**
     * The card's buttons as the model needs them to match a customer's words: the title the
     * customer reads, the value to pass back as pay_with.
     *
     * @param array[] $gateways Checkout::getGateways()
     * @return string
     */
    private static function describeButtons( array $gateways )
    {
        return implode( '; ', array_map( function ( $gateway ) {
            return sprintf( '"%s" = %s (%s)', $gateway['title'], $gateway['name'], $gateway['name'] === Lib\Entities\Payment::TYPE_LOCAL
                ? 'paid at the appointment, booked right away'
                : 'online' );
        }, $gateways ) );
    }

    /**
     * @param array[] $gateways
     * @param string  $name
     * @return string
     */
    private static function buttonTitle( array $gateways, $name )
    {
        foreach ( $gateways as $gateway ) {
            if ( $gateway['name'] === $name ) {
                return $gateway['title'];
            }
        }

        return $name;
    }

    /**
     * Write the appointment straight away, with no payment attached.
     *
     * @param Lib\Entities\Customer $customer
     * @param Lib\Entities\Service  $service
     * @param Lib\Entities\Staff    $staff
     * @param array                 $draft
     * @param string                $end_date Service-only end; save() adds extras duration itself.
     * @param string|null           $money What is paid or to pay, in words; null - the booking's total price
     * @return string
     */
    private static function book( $customer, $service, $staff, array $draft, $end_date, $money = null )
    {
        $customers = array( array(
            'id'                => $customer->getId(),
            'status'            => Lib\Config::getDefaultAppointmentStatus(),
            'number_of_persons' => $draft['number_of_persons'],
            'extras'            => $draft['extras'],
            'custom_fields'     => array(),
            'notes'             => $draft['notes'],
            'payment_id'        => null,
            'payment_for'       => null,
            'payment_action'    => null,
            'series_id'         => null,
            'timezone'          => null,
        ) );

        // save() does not check the slot (execute() did) and adds extras duration itself,
        // so $end_date must stay the plain service-only end.
        $result = Lib\Utils\Appointment::save(
            0,                        // appointment_id — 0 = create new
            $draft['staff_id'],
            $draft['service_id'],
            null,                     // custom_service_name — always a real service_id
            null,                     // custom_service_price
            $draft['location_id'],    // location_id — null on single-location sites
            false,                    // skip_date
            $draft['start_date'],
            $end_date,
            array(),                  // repeat — no recurrence support
            null,                     // schedule
            null,                     // reschedule_type — irrelevant for a new appointment
            $customers,
            true,                     // notification — customer/staff get the normal new-booking emails
            'Created via AI chat',    // internal_note
            'frontend'                // created_from
        );

        if ( empty( $result['success'] ) || empty( $result['appointments'] ) ) {
            Lib\Utils\Log::error( 'AI create_booking failed: ' . wp_json_encode( isset( $result['errors'] ) ? $result['errors'] : $result ), __FILE__, __LINE__ );

            return 'Error: the booking system could not save this booking, nothing was booked. Apologise and suggest another time or contacting the business directly.';
        }

        if ( ! empty( $result['queue']['token'] ) ) {
            Lib\Notifications\Routine::sendNotificationsAssociatedWithQueue( array_keys( $result['queue']['all'] ), 'all', $result['queue']['token'] );
        }

        $ca = new Lib\Entities\CustomerAppointment();
        $ca->loadBy( array( 'appointment_id' => $result['appointments'][0]['id'], 'customer_id' => $customer->getId() ) );
        if ( $money === null ) {
            $money = 'total price ' . Lib\Utils\Price::format( ServicePrice::forBooking( $service, $staff->getId(), $draft['location_id'], $draft['start_date'], $draft['extras'], $draft['number_of_persons'] ) );
        }
        $booking = $ca->isLoaded() ? Checkout::bookingLine( $ca, $money ) : null;

        return $booking === null
            ? 'Booking confirmed: appointment #' . $result['appointments'][0]['id'] . '.'
            : Checkout::bookedHeadline( $ca ) . ': ' . $booking . '.';
    }
}
