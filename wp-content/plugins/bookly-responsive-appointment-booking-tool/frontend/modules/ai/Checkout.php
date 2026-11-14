<?php
namespace Bookly\Frontend\Modules\Ai;

use Bookly\Backend\Modules\Appearance\ModernAppearance;
use Bookly\Frontend\Modules\Booking\Proxy as BookingProxy;
use Bookly\Frontend\Modules\Payment\Request as PaymentRequest;
use Bookly\Lib;
use Bookly\Lib\Entities;

/**
 * Turns the booking draft a conversation carries into the objects the regular payment flow
 * expects, so the AI chat can hand the customer to Lib\Base\Gateway exactly like the booking
 * form does.
 *
 * The draft itself is nothing but the arguments create_booking was called with (see
 * AiConversation::getBookingDataArray()). Nothing here writes an appointment - that happens
 * inside Gateway::createIntent(), where the booking form does it too, and is undone by
 * Gateway::fail() if the customer never pays.
 */
class Checkout
{
    // Name of the tool call the plugin puts into the history for a payment's outcome
    // (report()). Not a real tool: the model never calls it, it only reads its result.
    const STATUS_TOOL = 'payment_status';

    protected static $supported = array(
        Entities\Payment::TYPE_LOCAL,
        Entities\Payment::TYPE_CLOUD_STRIPE,
        Entities\Payment::TYPE_PAYPAL,
        Entities\Payment::TYPE_MOLLIE,
        Entities\Payment::TYPE_2CHECKOUT,
        Entities\Payment::TYPE_PAYUBIZ,
        Entities\Payment::TYPE_PAYULATAM,
        Entities\Payment::TYPE_PAYSON,
        Entities\Payment::TYPE_SQUARE,
        Entities\Payment::TYPE_WOOCOMMERCE,
    );

    /**
     * Build a session-less UserBookingData from a conversation's draft.
     *
     * Mirrors the two existing session-less builders - the modern-form branch of
     * Frontend\Modules\Payment\Request::getUserData() and BooklyPro's checkoutFormPay() -
     * rather than loading a FormSession: the chat visitor never had one, and the request
     * that pays is not the request that built the draft.
     *
     * @param Entities\AiConversation $conversation
     * @return Lib\UserBookingData|false false when the draft is unusable (service, staff or
     *                                   customer disappeared since create_booking ran)
     */
    public static function buildUserData( Entities\AiConversation $conversation )
    {
        $draft = $conversation->getBookingDataArray();
        if ( ! $draft ) {
            return false;
        }

        $customer = Entities\Customer::find( $draft['customer_id'] );
        if ( ! $customer ) {
            return false;
        }

        $service = Entities\Service::find( $draft['service_id'] );
        $staff = Entities\Staff::find( $draft['staff_id'] );
        if ( ! $service || ! $staff ) {
            return false;
        }

        // current_user_id = 0, not null: whoever happens to be logged into WordPress in this
        // browser is not the person the chat collected a name and an email from.
        $userData = new Lib\UserBookingData( null, 0 );

        $location_id = empty( $draft['location_id'] ) ? null : (int) $draft['location_id'];
        $slot = array( array(
            (int) $draft['service_id'],
            (int) $draft['staff_id'],
            $draft['start_date'],
            $location_id,
        ) );

        $cart_item = new Lib\CartItem();
        $cart_item
            ->setType( Lib\CartItem::TYPE_APPOINTMENT )
            ->setServiceId( (int) $draft['service_id'] )
            ->setStaffIds( array( (int) $draft['staff_id'] ) )
            ->setLocationId( $location_id )
            ->setNumberOfPersons( isset( $draft['number_of_persons'] ) ? (int) $draft['number_of_persons'] : 1 )
            ->setUnits( 1 )
            ->setExtras( isset( $draft['extras'] ) && is_array( $draft['extras'] ) ? $draft['extras'] : array() )
            ->setCustomFields( array() )
            ->setSlots( $slot );

        $userData->cart->add( $cart_item );
        $userData->setSlots( array( $slot ) );

        $userData
            ->setCustomer( $customer )
            ->setFullName( $customer->getFullName() )
            ->setFirstName( $customer->getFirstName() )
            ->setLastName( $customer->getLastName() )
            ->setEmail( $customer->getEmail() )
            ->setPhone( $customer->getPhone() );

        if ( ! empty( $draft['notes'] ) ) {
            $userData->fillData( array( 'notes' => $draft['notes'] ) );
        }

        return $userData;
    }

    /**
     * Gateways this cart can actually be paid with, in the order configured under
     * Settings -> Payments.
     *
     * Same three filters the booking form applies in Booking\Ajax::getGateways(): the gateway
     * has to be enabled, allowed for the customer's group, and allowed for every service and
     * every staff member in the cart - the last one is Service::getGateways() /
     * Staff::getGateways(), applied by BookingProxy\Pro::filterGateways().
     *
     * @param Lib\UserBookingData $userData
     * @return array[] [ [ name, title, logo_url ], ... ]
     */
    public static function getGateways( Lib\UserBookingData $userData )
    {
        $gateways = self::enabledGateways();

        foreach ( array_keys( $gateways ) as $type ) {
            if ( BookingProxy\CustomerGroups::allowedGateway( $type, $userData ) === false ) {
                unset( $gateways[ $type ] );
            }
        }

        $gateways = BookingProxy\Pro::filterGateways( $gateways, $userData );
        $l10n = ModernAppearance::getAppearance( Entities\Form::TYPE_AI_ASSISTANT, null );
        $l10n = isset( $l10n['l10n'] ) ? $l10n['l10n'] : array();

        $list = array();
        foreach ( self::orderGateways( array_keys( $gateways ) ) as $type ) {
            $alias = isset( $l10n[ 'payment_system_' . $type ] ) ? $l10n[ 'payment_system_' . $type ] : '';
            $label = $gateways[ $type ]['label_option_name']
                ? Lib\Utils\Common::getTranslatedOption( $gateways[ $type ]['label_option_name'] )
                : '';
            $logo_url = $gateways[ $type ]['logo_url'];
            if ( $type === Entities\Payment::TYPE_WOOCOMMERCE ) {
                $image = Entities\Payment::typeToImage( $type );
                $logo_url = $image['src'];
            } elseif ( $logo_url === 'default' ) {
                $logo_url = plugins_url( 'frontend/resources/images/payments.svg', Lib\Plugin::getMainFile() );
            }

            $list[] = array(
                'name' => $type,
                'title' => $alias !== '' ? $alias : ( $label !== '' ? $label : $gateways[ $type ]['title'] ),
                'logo_url' => $logo_url,
            );
        }

        return $list;
    }

    /**
     * Gateways switched on in Settings -> Payments that the chat can take, before anything
     * about a particular booking narrows them - what Concepts tells the model about paying.
     *
     * @return string[] Payment types, Entities\Payment::TYPE_*
     */
    public static function enabledGatewayTypes()
    {
        return array_keys( self::enabledGateways() );
    }

    /**
     * @return array[] [ type => [ title, label_option_name, logo_url ] ]
     */
    private static function enabledGateways()
    {
        return array_intersect_key( Lib\Utils\Common::getEnabledGateways(), array_flip( self::$supported ) );
    }

    /**
     * What the chat shows the customer: the priced draft plus the gateways to choose from.
     * The price comes from CartInfo, so it is the same number the payment system will be
     * asked for - taxes, deposit and any discounts included.
     *
     * @param Entities\AiConversation $conversation
     * @return array|false
     */
    public static function getPaymentOptions( Entities\AiConversation $conversation )
    {
        $userData = self::buildUserData( $conversation );
        if ( ! $userData ) {
            return false;
        }

        $gateways = self::getGateways( $userData );
        if ( ! $gateways ) {
            return false;
        }

        // The customer named an online method in words (create_booking's pay_with): the card
        // keeps only its button. Should it have stopped being allowed since, the card falls
        // back to the full list rather than to none.
        $draft = $conversation->getBookingDataArray();
        if ( ! empty( $draft['gateway'] ) ) {
            $chosen = array();
            foreach ( $gateways as $gateway ) {
                if ( $gateway['name'] === $draft['gateway'] ) {
                    $chosen[] = $gateway;
                }
            }
            $gateways = $chosen ?: $gateways;
        }

        $cart_info = $userData->cart->getInfo();
        $service = Entities\Service::find( $draft['service_id'] );
        $staff = Entities\Staff::find( $draft['staff_id'] );

        // With deposit payments on, what is charged now is not the price of the service.
        // Showing only one of the two numbers is how a customer ends up surprised either at
        // checkout or at the appointment, so the card carries both.
        $total = $cart_info->getTotal();
        $pay_now = $cart_info->getPayNow();

        return array(
            // New on every create_booking call (see there); unset on drafts from before it.
            'card' => isset( $draft['card'] ) ? $draft['card'] : null,
            'title' => $service->getTranslatedTitle(),
            'staff' => $staff->getTranslatedName(),
            'start_date' => $draft['start_date'],
            // Raw, for callers that compare it - a zero total has no checkout to run.
            'pay_now_raw' => $pay_now,
            'total' => Lib\Utils\Price::format( $total ),
            'due_now' => Lib\Utils\Price::format( $pay_now ),
            'deposit' => $pay_now < $total,
            'gateways' => $gateways,
        );
    }

    /**
     * What the chat says once the payment system has answered.
     *
     * Composed here, not asked of the model: the plugin knows exactly what was booked, and a
     * round trip to Cloud would only cost money and give the model a chance to get the
     * details wrong. Wording follows the appearance texts so a site can translate it.
     *
     * @param Entities\AiConversation $conversation
     * @param string                  $status Lib\Base\Gateway::STATUS_*
     * @return string
     */
    public static function getResultText( Entities\AiConversation $conversation, $status )
    {
        if ( $status !== Lib\Base\Gateway::STATUS_COMPLETED && $status !== Lib\Base\Gateway::STATUS_PROCESSING ) {
            return self::getText( 'paymentFailed' );
        }

        $draft = $conversation->getBookingDataArray();
        $service = isset( $draft['service_id'] ) ? Entities\Service::find( $draft['service_id'] ) : null;
        $staff = isset( $draft['staff_id'] ) ? Entities\Staff::find( $draft['staff_id'] ) : null;

        // "Pay locally" ends the checkout successfully but takes no money: the payment stays
        // pending until the customer turns up. Saying "payment received" there would be a
        // plain untruth, so that case gets its own line.
        //
        // No dedicated payment_id column: Gateway::createIntent() puts at most one Payment
        // against an order (Gateway::fail() removes the order along with it on any failed
        // attempt), so the order_id already on the conversation resolves it on its own.
        $paid = true;
        if ( $conversation->getOrderId() ) {
            $payment = new Entities\Payment();
            $paid = $payment->loadBy( array( 'order_id' => $conversation->getOrderId() ) )
                && $payment->getStatus() === Entities\Payment::STATUS_COMPLETED;
        }

        if ( $status === Lib\Base\Gateway::STATUS_PROCESSING ) {
            // The gateway took the customer through but has not told us about the money
            // yet - it will, through its webhook. The booking itself is real, so this says
            // so, and does not claim a payment that may still fail.
            $key = 'paymentAwaiting';
        } else {
            $key = $paid ? 'paymentCompleted' : 'paymentPending';
        }

        return strtr( self::getText( $key ), array(
            '{service_name}' => $service ? $service->getTranslatedTitle() : '',
            '{staff_name}' => $staff ? $staff->getTranslatedName() : '',
            '{appointment_date}' => isset( $draft['start_date'] )
                ? Lib\Utils\DateTime::formatDate( $draft['start_date'] )
                : '',
            '{appointment_time}' => isset( $draft['start_date'] )
                ? Lib\Utils\DateTime::formatTime( $draft['start_date'] )
                : '',
        ) );
    }

    /**
     * One of the chat's own lines (the appearance's `l10n` texts), as the owner wrote it.
     *
     * Single source of truth for the wording: the same defaults the appearance settings
     * form shows and pre-fills, so a site that never touched these fields and a site that
     * cleared one back to blank see identical text.
     *
     * @param string $key
     * @return string
     */
    public static function getText( $key )
    {
        $l10n = ModernAppearance::getAppearance( Entities\Form::TYPE_AI_ASSISTANT, null );
        if ( isset( $l10n['l10n'][ $key ] ) && $l10n['l10n'][ $key ] !== '' ) {
            return $l10n['l10n'][ $key ];
        }

        $defaults = ModernAppearance::getAiAssistantDefaults();

        return isset( $defaults['l10n'][ $key ] ) ? $defaults['l10n'][ $key ] : '';
    }

    /**
     * Book the conversation's draft with "pay locally" for a customer who said so in words -
     * the same order, appointment and pending payment the card's button makes through
     * Ajax::aiCheckout() and Ajax::aiOrderStatus(), only without the browser: nothing about
     * paying at the appointment needs a window or a redirect.
     *
     * @param Entities\AiConversation $conversation With a pending draft
     * @return Lib\DataHolders\Booking\Order|false false when the checkout could not be made;
     *                                               the draft is then pending again
     */
    public static function payLocally( Entities\AiConversation $conversation )
    {
        $userData = self::buildUserData( $conversation );
        if ( ! $userData || ! self::claim( $conversation ) ) {
            return false;
        }

        // The worker may book twice in one request (two services in one turn), and the
        // payment request keeps its gateway and its priced cart from the first time.
        PaymentRequest::deleteFromCache( 'getInstance' );
        $request = PaymentRequest::getInstance();
        $request->setUserData( $userData );
        $request->setGatewayName( Entities\Payment::TYPE_LOCAL );
        $request->setResponseAction( 'bookly_ai_checkout_response' );

        $gateway = null;
        try {
            $gateway = $request->getGateway();
            $gateway->createIntent();
            $status = $gateway->retrieve();
        } catch ( \Error $e ) {
            Lib\Utils\Log::error( 'AI checkout failed: ' . $e->getMessage(), $e->getFile(), $e->getLine() );
            $status = Lib\Base\Gateway::STATUS_FAILED;
        } catch ( \Exception $e ) {
            Lib\Utils\Log::error( 'AI checkout failed: ' . $e->getMessage(), $e->getFile(), $e->getLine() );
            $status = Lib\Base\Gateway::STATUS_FAILED;
        }

        $order = $gateway ? $gateway->getOrder() : null;
        if ( $status !== Lib\Base\Gateway::STATUS_COMPLETED || ! $order ) {
            if ( $gateway ) {
                try {
                    $gateway->fail();
                } catch ( \Exception $ignore ) {
                } catch ( \Error $ignore ) {
                }
            }
            self::setState( $conversation, Entities\AiConversation::BOOKING_PENDING, null );

            return false;
        }

        self::setState( $conversation, Entities\AiConversation::BOOKING_PAID, $order->getOrderId() );

        return $order;
    }

    /**
     * Take the pending draft for one checkout: only the request that flips pending->processing
     * goes on to create the order, so two of them never book the slot twice.
     *
     * @param Entities\AiConversation $conversation
     * @return bool
     */
    public static function claim( Entities\AiConversation $conversation )
    {
        $claimed = Entities\AiConversation::query()
            ->update()
            ->set( 'booking_status', Entities\AiConversation::BOOKING_PROCESSING )
            ->set( 'updated_at', current_time( 'mysql' ) )
            ->where( 'id', $conversation->getId() )
            ->where( 'booking_status', Entities\AiConversation::BOOKING_PENDING )
            ->execute();
        if ( $claimed ) {
            $conversation->setBookingStatus( Entities\AiConversation::BOOKING_PROCESSING );
        }

        return (bool) $claimed;
    }

    /**
     * Write the checkout's outcome onto the conversation without touching anything else on
     * the row: the AI worker owns `status` on it for the whole turn, the visitor's request
     * owns the checkout, and whichever finishes second must not undo the other.
     *
     * @param Entities\AiConversation $conversation
     * @param string|null             $booking_status
     * @param int|null                $order_id
     */
    public static function setState( Entities\AiConversation $conversation, $booking_status, $order_id )
    {
        Entities\AiConversation::query()
            ->update()
            ->set( 'booking_status', $booking_status )
            ->set( 'order_id', $order_id )
            ->set( 'updated_at', current_time( 'mysql' ) )
            ->where( 'id', $conversation->getId() )
            ->execute();

        $conversation->setBookingStatus( $booking_status )->setOrderId( $order_id );
    }

    /**
     * The button the card shows for a gateway, as the customer saw it.
     *
     * @param Entities\AiConversation $conversation
     * @param string                  $name
     * @return string The gateway's name when it is not on the card
     */
    public static function gatewayTitle( Entities\AiConversation $conversation, $name )
    {
        $userData = self::buildUserData( $conversation );
        if ( $userData ) {
            foreach ( self::getGateways( $userData ) as $gateway ) {
                if ( $gateway['name'] === $name ) {
                    return $gateway['title'];
                }
            }
        }

        return $name;
    }

    /**
     * Note something that happened to the payment card in the conversation. The model reads it
     * with the customer's next message (Ajax::buildCompletionPayload()), and so does Cloud's
     * log of that request - the card's buttons work without the model, and without these a
     * transcript cannot tell whether the customer ever pressed one.
     *
     * The same event right after itself is not noted again: a button pressed over and over
     * into a blocked payment window says nothing new the second time.
     *
     * @param Entities\AiConversation $conversation
     * @param string                  $text
     * @param string                  $about What the note is about, said before it
     */
    public static function addEvent( Entities\AiConversation $conversation, $text, $about = 'Payment card' )
    {
        $content = $about . ': ' . $text;

        /** @var Entities\AiMessage $last */
        $last = Entities\AiMessage::query()
            ->where( 'conversation_id', $conversation->getId() )
            ->sortBy( 'id' )
            ->order( Lib\Query::ORDER_DESCENDING )
            ->limit( 1 )
            ->findOne();
        if ( $last && $last->getRole() === Entities\AiMessage::ROLE_EVENT && $last->getContent() === $content ) {
            return;
        }

        $event = new Entities\AiMessage();
        $event
            ->setConversationId( $conversation->getId() )
            ->setRole( Entities\AiMessage::ROLE_EVENT )
            ->setContent( $content )
            ->save();
    }

    /**
     * Tell the model how a payment on the card ended, as the result of a tool call
     * (Ajax::buildCompletionPayload() puts the call in): the model takes a tool's result as
     * fact, while a line of the chat's own it rechecks with create_booking - and then reads
     * its own appointment holding the time as "not available".
     *
     * @param Entities\AiConversation $conversation
     * @param string                  $text
     */
    public static function report( Entities\AiConversation $conversation, $text )
    {
        $message = new Entities\AiMessage();
        $message
            ->setConversationId( $conversation->getId() )
            ->setRole( Entities\AiMessage::ROLE_TOOL )
            ->setToolName( self::STATUS_TOOL )
            ->setContent( $text )
            ->save();
    }

    /**
     * The booking the conversation's checkout made, as it stands now: the payment's status
     * is read every time, since a gateway's webhook can complete it long after the checkout.
     *
     * @param Entities\AiConversation $conversation
     * @return string|null null when the conversation has no booking made
     */
    public static function describeBooking( Entities\AiConversation $conversation )
    {
        $ca = new Entities\CustomerAppointment();
        if ( ! $conversation->getOrderId() || ! $ca->loadBy( array( 'order_id' => $conversation->getOrderId() ) ) ) {
            return null;
        }

        $payment = new Entities\Payment();
        if ( ! $payment->loadBy( array( 'order_id' => $conversation->getOrderId() ) ) ) {
            $paid = 'nothing to pay';
        } elseif ( $payment->getType() === Entities\Payment::TYPE_LOCAL && $payment->getStatus() !== Entities\Payment::STATUS_COMPLETED ) {
            $paid = sprintf( '%s to be paid at the appointment', Lib\Utils\Price::format( $payment->getTotal() ) );
        } elseif ( $payment->getStatus() === Entities\Payment::STATUS_PENDING ) {
            $paid = 'the payment system has not confirmed the payment yet';
        } elseif ( $payment->getStatus() !== Entities\Payment::STATUS_COMPLETED ) {
            $paid = 'payment ' . $payment->getStatus();
        } elseif ( $payment->getPaid() < $payment->getTotal() ) {
            $paid = sprintf( '%s paid, the rest of %s total at the appointment', Lib\Utils\Price::format( $payment->getPaid() ), Lib\Utils\Price::format( $payment->getTotal() ) );
        } else {
            $paid = sprintf( '%s paid', Lib\Utils\Price::format( $payment->getPaid() ) );
        }

        return self::bookingLine( $ca, $paid );
    }

    /**
     * The booking the conversation's checkout made, as create_booking and the checkout's
     * report put it: bookedHeadline() and the booking.
     *
     * @param Entities\AiConversation $conversation
     * @return string|null null when the conversation has no booking made
     */
    public static function bookedResult( Entities\AiConversation $conversation )
    {
        $booking = self::describeBooking( $conversation );
        $ca = new Entities\CustomerAppointment();
        if ( $booking === null || ! $ca->loadBy( array( 'order_id' => $conversation->getOrderId() ) ) ) {
            return null;
        }

        return self::bookedHeadline( $ca ) . ': ' . $booking . '.';
    }

    /**
     * One booking as the model reads it - the same words in create_booking's result, the
     * checkout's report and the booking updates, so one booking never reads as two:
     * "appointment #N — <service> with <staff>, <start> (ends <end>), <money>[, <status>]".
     * notePaymentChange() finds what the model last read by the part before " — ".
     *
     * @param Entities\CustomerAppointment $ca
     * @param string                       $money What is paid or to pay, in words
     * @return string|null null when the appointment is gone
     */
    public static function bookingLine( Entities\CustomerAppointment $ca, $money )
    {
        $appointment = Entities\Appointment::find( $ca->getAppointmentId() );
        $service = $appointment ? Entities\Service::find( $appointment->getServiceId() ) : null;
        $staff = $appointment ? Entities\Staff::find( $appointment->getStaffId() ) : null;
        if ( ! $service || ! $staff ) {
            return null;
        }

        $extras = json_decode( (string) $ca->getExtras(), true );
        $end = date( 'Y-m-d H:i:s', strtotime( $appointment->getEndDate() ) + (int) $appointment->getExtrasDuration() );
        $status = self::statusWords( $ca->getStatus() );

        $nop = (int) $ca->getNumberOfPersons();

        return sprintf(
            'appointment #%d — %s%s%s with %s, %s (ends %s), %s%s',
            $appointment->getId(), $service->getTitle(), $extras ? ' (with extras)' : '', $nop > 1 ? ' for ' . $nop . ' people' : '', $staff->getFullName(),
            $appointment->getStartDate(), $end, $money, $status === null ? '' : ', ' . $status
        );
    }

    /**
     * What create_booking and the checkout say a booking is: "confirmed" only when it is
     * approved - a business can have new bookings wait for its approval (Settings ->
     * Appointments -> Default appointment status), or give them a custom status.
     *
     * @param Entities\CustomerAppointment $ca
     * @return string
     */
    public static function bookedHeadline( Entities\CustomerAppointment $ca )
    {
        return $ca->getStatus() === Entities\CustomerAppointment::STATUS_APPROVED
            ? 'Booking confirmed'
            : 'Booked, not confirmed yet';
    }

    /**
     * @param string $status Entities\CustomerAppointment::STATUS_* or a custom status
     * @return string|null null for approved, which needs no words
     */
    private static function statusWords( $status )
    {
        if ( $status === Entities\CustomerAppointment::STATUS_APPROVED ) {
            return null;
        }
        if ( $status === Entities\CustomerAppointment::STATUS_PENDING ) {
            return 'waiting for the business to approve it';
        }

        return 'status "' . Entities\CustomerAppointment::statusToString( $status ) . '"';
    }

    /**
     * Note, with the customer's message, that the booking's payment has moved on since the
     * model last read about it - a webhook confirming the money after the checkout said
     * "not confirmed yet", or while the payment window is still open: the conversation only
     * learns how that window ended once it closes, and the webhook may come first. The note
     * does not mention the window: the model reads "still open" as "not paid" whatever the
     * payment says (conversation 91). Added at the end, never rewritten into what the model
     * already has: the history before it stays the same for Cloud's prompt cache.
     *
     * @param Entities\AiConversation $conversation
     */
    public static function notePaymentChange( Entities\AiConversation $conversation )
    {
        if ( ! in_array( $conversation->getBookingStatus(), array( Entities\AiConversation::BOOKING_PROCESSING, Entities\AiConversation::BOOKING_PAID, Entities\AiConversation::BOOKING_AWAITING ), true ) ) {
            return;
        }
        $booking = self::describeBooking( $conversation );
        if ( $booking === null ) {
            return;
        }

        // The latest the model read about this booking: the checkout's report() or an earlier note.
        $marker = strstr( $booking, ' — ', true );
        /** @var Entities\AiMessage $said */
        $said = Entities\AiMessage::query()
            ->where( 'conversation_id', $conversation->getId() )
            ->whereIn( 'role', array( Entities\AiMessage::ROLE_TOOL, Entities\AiMessage::ROLE_EVENT ) )
            ->whereLike( 'content', '%' . $marker . ' %' )
            ->sortBy( 'id' )
            ->order( Lib\Query::ORDER_DESCENDING )
            ->limit( 1 )
            ->findOne();
        if ( $said && strpos( $said->getContent(), $booking ) !== false ) {
            return;
        }

        self::addEvent( $conversation, $booking . '.', 'Booking update' );
    }

    /**
     * Note, with the customer's message, that the payment card is still unpressed. A customer
     * who reads the buttons and types one of them has not pressed anything - which is what
     * dialog 602 ran into six times.
     *
     * Once per card (one create_booking call): any event since already says where it stands -
     * this note itself, or a press, whose outcome the transcript then carries on its own.
     *
     * @param Entities\AiConversation $conversation
     */
    public static function noteCardState( Entities\AiConversation $conversation )
    {
        if ( $conversation->getBookingStatus() !== Entities\AiConversation::BOOKING_PENDING ) {
            return;
        }

        /** @var Entities\AiMessage $card */
        $card = Entities\AiMessage::query()
            ->where( 'conversation_id', $conversation->getId() )
            ->where( 'role', Entities\AiMessage::ROLE_TOOL )
            ->where( 'tool_name', 'create_booking' )
            ->sortBy( 'id' )
            ->order( Lib\Query::ORDER_DESCENDING )
            ->limit( 1 )
            ->findOne();
        $noted = $card && Entities\AiMessage::query()
            ->where( 'conversation_id', $conversation->getId() )
            ->where( 'role', Entities\AiMessage::ROLE_EVENT )
            ->whereGt( 'id', $card->getId() )
            ->count();
        if ( ! $noted ) {
            self::addEvent( $conversation, 'still open, nothing pressed on it yet. The booking is not made yet.' );
        }
    }

    /**
     * @param array $gateways
     * @return array
     */
    protected static function orderGateways( array $gateways )
    {
        $ordered = array();
        foreach ( (array) Lib\Config::getGatewaysPreference() as $type ) {
            if ( in_array( $type, $gateways ) ) {
                $ordered[] = $type;
            }
        }
        foreach ( $gateways as $type ) {
            if ( ! in_array( $type, $ordered ) ) {
                $ordered[] = $type;
            }
        }

        return $ordered;
    }
}
