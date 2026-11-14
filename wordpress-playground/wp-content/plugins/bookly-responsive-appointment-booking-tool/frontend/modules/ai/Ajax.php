<?php
namespace Bookly\Frontend\Modules\Ai;

use Bookly\Frontend\Modules\Payment\Request as PaymentRequest;
use Bookly\Lib;

/**
 * AJAX handlers for the Cloud AI round trip.
 *
 * aiSendMessage() queues an AiJob and fires a fire-and-forget loopback
 * request; aiWorker() (running in that separate request) does the actual
 * tool-loop and self-chains via another loopback if it runs long; aiPoll()
 * reads status from the DB. This avoids holding one blocking connection
 * open for the whole tool-loop, which matters on tight shared-hosting
 * execution limits.
 *
 * Registered by Lib\Base\Ajax::init() as:
 *   - wp_ajax_bookly_ai_send_message      (method aiSendMessage)
 *   - wp_ajax_bookly_ai_worker            (method aiWorker)
 *   - wp_ajax_bookly_ai_poll              (method aiPoll)
 *   - wp_ajax_bookly_ai_transcribe        (method aiTranscribe)
 *   - wp_ajax_bookly_ai_checkout          (method aiCheckout)
 *   - wp_ajax_bookly_ai_order_status      (method aiOrderStatus)
 *   - wp_ajax_bookly_ai_checkout_response (method aiCheckoutResponse)
 *   - wp_ajax_bookly_ai_payment_event     (method aiPaymentEvent)
 *
 * The three checkout actions run in the visitor's own request, never in the worker: paying
 * is something the browser drives, and Gateway::createIntent() has to be able to redirect it.
 */
class Ajax extends Lib\Base\Ajax
{
    // aiWorker() stops starting new tool-loop rounds once less than
    // WORKER_MARGIN seconds are left in its WORKER_TIME_BUDGET and hands off
    // to a fresh loopback request instead — see respawnWorker().
    const WORKER_TIME_BUDGET = 20;
    const WORKER_MARGIN      = 5;

    // Hard cap on model<->tool round trips per AiJob (per user message, not
    // per whole conversation), independent of the time budget — guards
    // against a model stuck calling tools forever.
    const MAX_STEPS = 12;

    const MAX_MESSAGE_LENGTH = 2000;

    // Which Cloud agent definition serves this widget — see
    // AIBundle/Resources/config/agents.yml in bookly-cloud.
    const AGENT = 'booking';

    protected static function permissions()
    {
        return array( '_default' => 'anonymous' );
    }

    /**
     * Start (or continue) a conversation: persists the user's message,
     * queues an AiJob, and fires a fire-and-forget loopback request to run
     * it (spawnWorker()) — then returns immediately. The browser is expected
     * to start polling aiPoll() with the returned token.
     *
     * Expects (via json_data): message, token (optional), form_slug (optional),
     * time_zone (optional, IANA name of the visitor's browser time zone),
     * choice (optional, id of the offer_choices option the message was a click on).
     */
    public static function aiSendMessage()
    {
        $text      = trim( (string) self::parameter( 'message', '' ) );
        $choice    = (string) self::parameter( 'choice', '' );
        $token     = (string) self::parameter( 'token', '' );
        $form_slug = (string) self::parameter( 'form_slug', '' );
        $time_zone = (string) self::parameter( 'time_zone', '' );

        if ( $text === '' ) {
            wp_send_json( array( 'success' => false, 'error' => 'ERROR_EMPTY_MESSAGE' ) );
        }
        if ( mb_strlen( $text, 'UTF-8' ) > self::MAX_MESSAGE_LENGTH ) {
            wp_send_json( array( 'success' => false, 'error' => 'ERROR_MESSAGE_TOO_LONG' ) );
        }

        if ( $token !== '' ) {
            $conversation = self::authorizeConversation();
            if ( ! $conversation ) {
                wp_send_json( array( 'success' => false, 'error' => 'ERROR_UNKNOWN_CONVERSATION' ) );
            }
        } else {
            $conversation = new Lib\Entities\AiConversation();
        }
        $conversation->setStatus( Lib\Entities\AiConversation::STATUS_PROCESSING )->save();

        // Before the message, so they go out together with it - see buildCompletionPayload().
        Checkout::noteCardState( $conversation );
        Checkout::notePaymentChange( $conversation );
        BookingTools\ChoicePicks::notePick( $conversation, $choice );

        $message = new Lib\Entities\AiMessage();
        $message
            ->setConversationId( $conversation->getId() )
            ->setRole( Lib\Entities\AiMessage::ROLE_USER )
            ->setContent( $text )
            ->save();

        $job = new Lib\Entities\AiJob();
        $job
            ->setConversationId( $conversation->getId() )
            ->setStatus( Lib\Entities\AiJob::STATUS_QUEUED )
            ->save();

        self::spawnWorker( $job, $form_slug, $time_zone );

        wp_send_json( array(
            'success'         => true,
            'token'           => $conversation->getToken(),
            'last_message_id' => $message->getId(),
        ) );
    }

    /**
     * The actual agent loop — runs in its OWN php-fpm process (a loopback
     * request, never the browser's own request), so it can safely take up
     * to WORKER_TIME_BUDGET seconds. Does not echo anything the browser
     * depends on — the browser only reads progress via aiPoll().
     *
     * One iteration = one Cloud /complete call + (if the model asked for
     * tools) executing every requested tool and persisting its result. Runs
     * until either: the model stops requesting tools (done), an error
     * occurs (failed), MAX_STEPS is hit (failed), or the time budget is
     * nearly spent (hands off to a fresh loopback via respawnWorker()).
     *
     * Expects (via json_data): job_id, form_slug (optional), time_zone (optional).
     */
    public static function aiWorker()
    {
        $job_id    = (int) self::parameter( 'job_id', 0 );
        $form_slug = (string) self::parameter( 'form_slug', '' );
        $time_zone = (string) self::parameter( 'time_zone', '' );

        // A job of the setup assistant is run by its own worker - never claim it here.
        $agent = Lib\Entities\AiJob::query( 'j' )
            ->select( 'c.agent' )
            ->leftJoin( 'AiConversation', 'c', 'c.id = j.conversation_id' )
            ->where( 'j.id', $job_id )
            ->fetchVar( 'agent' );
        if ( $agent !== Lib\Entities\AiConversation::AGENT_BOOKING ) {
            wp_die();
        }

        // Atomic claim: only the request that flips queued->running "wins" —
        // a duplicate/replayed loopback just no-ops here instead of running
        // the loop twice.
        $claimed = Lib\Entities\AiJob::query()
            ->update()
            ->setRaw( '`status` = %s, `heartbeat_at` = %s, `updated_at` = %s, `attempts` = `attempts` + 1', array(
                Lib\Entities\AiJob::STATUS_RUNNING,
                current_time( 'mysql' ),
                current_time( 'mysql' ),
            ) )
            ->where( 'id', $job_id )
            ->where( 'status', Lib\Entities\AiJob::STATUS_QUEUED )
            ->execute();

        if ( ! $claimed ) {
            wp_die();
        }

        $job          = Lib\Entities\AiJob::find( $job_id );
        $conversation = $job ? Lib\Entities\AiConversation::find( $job->getConversationId() ) : false;

        if ( ! $job || ! $conversation || $conversation->getAgent() !== Lib\Entities\AiConversation::AGENT_BOOKING ) {
            wp_die();
        }

        // No browser is waiting on this request — keep working even after
        // the initiator returns, and don't let the host's default execution
        // limit cut the loop off before WORKER_TIME_BUDGET does.
        ignore_user_abort( true );
        @set_time_limit( 0 );

        Catalog::forConversation( $conversation );

        $deadline = microtime( true ) + self::WORKER_TIME_BUDGET;
        $step     = $job->getStep();

        while ( $step < self::MAX_STEPS ) {
            $step++;

            $payload = self::buildCompletionPayload( $conversation, $form_slug, $time_zone );
            $client  = Lib\Cloud\API::getInstance()->getProduct( Lib\Cloud\Account::PRODUCT_AI );
            $raw     = $client->completeRaw( $payload );
            $body    = json_decode( $raw['body'], true );

            if ( $raw['status'] !== 200 || empty( $body['success'] ) ) {
                // On the failure path $body['message'] is Cloud's human-readable
                // detail string, not the {role,content,tool_calls} object it is
                // on the success path below.
                $error_code   = isset( $body['error'] ) ? $body['error'] : 'ERROR_PROXY_CONNECTION';
                $error_detail = isset( $body['message'] ) && is_string( $body['message'] ) ? $body['message'] : null;
                self::failJob( $job, $conversation, $error_code, $error_detail );

                return;
            }

            $assistant_message = new Lib\Entities\AiMessage();
            $assistant_message
                ->setConversationId( $conversation->getId() )
                ->setRole( Lib\Entities\AiMessage::ROLE_ASSISTANT )
                ->setContent( isset( $body['message']['content'] ) ? $body['message']['content'] : null )
                ->setToolCalls( ! empty( $body['message']['tool_calls'] ) ? wp_json_encode( $body['message']['tool_calls'] ) : null )
                ->save();

            $job->setStep( $step )->setHeartbeatAt( current_time( 'mysql' ) )->save();

            if ( $body['finish_reason'] !== 'tool_calls' || empty( $body['message']['tool_calls'] ) ) {
                // Model gave a final answer — conversation turn is done.
                self::setConversationStatus( $conversation, Lib\Entities\AiConversation::STATUS_DONE );
                $job->setStatus( Lib\Entities\AiJob::STATUS_DONE )->save();

                return;
            }

            // Execute every requested tool and persist each result as its
            // own 'tool' message BEFORE the next /complete call — Cloud
            // expects one {role:"tool", tool_call_id, name, content} entry
            // per tool_call echoed back in "messages" (verified against the
            // real Cloud contract, see http/rest-ai.http in bookly-cloud).
            $choices_only = true;
            foreach ( $body['message']['tool_calls'] as $tool_call ) {
                $tool_result = self::executeToolForModel( $tool_call['name'], (array) $tool_call['arguments'], $conversation );

                $tool_message = new Lib\Entities\AiMessage();
                $tool_message
                    ->setConversationId( $conversation->getId() )
                    ->setRole( Lib\Entities\AiMessage::ROLE_TOOL )
                    ->setToolCallId( $tool_call['id'] )
                    ->setToolName( $tool_call['name'] )
                    ->setContent( $tool_result )
                    ->save();

                $shown_choices = $tool_call['name'] === BookingTools\OfferChoices::NAME
                    && BookingTools\ChoiceCards::present( $tool_result );
                $choices_only  = $choices_only && $shown_choices;
            }

            // The reply is already written and its options are on screen as
            // cards - nothing is left for the model to do until the customer
            // picks one, so end the turn here instead of paying for a round
            // that could only repeat the text. Without text of its own (the
            // model called the tool first) it still gets that round to write it.
            if ( $choices_only && trim( (string) $assistant_message->getContent() ) !== '' ) {
                self::setConversationStatus( $conversation, Lib\Entities\AiConversation::STATUS_DONE );
                $job->setStatus( Lib\Entities\AiJob::STATUS_DONE )->save();

                return;
            }

            if ( microtime( true ) > $deadline - self::WORKER_MARGIN ) {
                self::respawnWorker( $job, $form_slug, $time_zone );

                return;
            }
        }

        self::failJob( $job, $conversation, 'ERROR_MAX_STEPS' );
    }

    /**
     * Read-only status/transcript poll for the browser. Only surfaces
     * 'assistant' messages (and 'user' ones when restoring, after_id = 0) —
     * 'tool' turns are an implementation detail of the loop.
     */
    public static function aiPoll()
    {
        $after_id = (int) self::parameter( 'after_id', 0 );

        $conversation = self::authorizeConversation();
        if ( ! $conversation ) {
            wp_send_json( array( 'success' => false, 'error' => 'ERROR_UNKNOWN_CONVERSATION' ) );
        }

        // after_id = 0 is the widget restoring the conversation after a page reload: then
        // the customer's own messages are needed too.
        $roles = $after_id === 0
            ? array( Lib\Entities\AiMessage::ROLE_ASSISTANT, Lib\Entities\AiMessage::ROLE_USER )
            : array( Lib\Entities\AiMessage::ROLE_ASSISTANT );
        /** @var Lib\Entities\AiMessage[] $messages */
        $messages = Lib\Entities\AiMessage::query()
            ->where( 'conversation_id', $conversation->getId() )
            ->whereGt( 'id', $after_id )
            ->whereIn( 'role', $roles )
            ->sortBy( 'id' )
            ->find();

        // Cards from the turn's offer_choices call, if it made one - read
        // off its 'tool' message, which the transcript otherwise keeps to
        // itself. Its id is past the assistant message that requested it, so
        // it still comes back on the tick after that message was seen.
        $choices = null;
        /** @var Lib\Entities\AiMessage $choices_message */
        $choices_message = Lib\Entities\AiMessage::query()
            ->where( 'conversation_id', $conversation->getId() )
            ->whereGt( 'id', $after_id )
            ->where( 'role', Lib\Entities\AiMessage::ROLE_TOOL )
            ->where( 'tool_name', BookingTools\OfferChoices::NAME )
            ->sortBy( 'id' )
            ->order( Lib\Query::ORDER_DESCENDING )
            ->limit( 1 )
            ->findOne();
        // Restoring, only cards nobody has answered yet: the customer's message after them
        // has closed them.
        if ( $choices_message && $after_id === 0 ) {
            foreach ( $messages as $message ) {
                if ( $message->getRole() === Lib\Entities\AiMessage::ROLE_USER && $message->getId() > $choices_message->getId() ) {
                    $choices_message = null;
                    break;
                }
            }
        }
        if ( $choices_message ) {
            $choices = BookingTools\ChoiceCards::present( $choices_message->getContent() );
        }
        // And the answered ones, which the widget had kept in the log, closed, until the reload.
        $answered_choices = $after_id === 0 ? BookingTools\ChoicePicks::answered( $conversation ) : array();

        $payment = null;
        if ( $conversation->getBookingStatus() === Lib\Entities\AiConversation::BOOKING_PENDING ) {
            $payment = Checkout::getPaymentOptions( $conversation ) ?: null;
        }

        wp_send_json( array(
            'success'    => true,
            'status'     => $conversation->getStatus(),
            'error_code' => $conversation->getStatus() === Lib\Entities\AiConversation::STATUS_ERROR ? $conversation->getErrorCode() : null,
            'payment'    => $payment,
            'choices'    => $choices,
            'answered_choices' => $answered_choices,
            'messages'   => array_map( function ( Lib\Entities\AiMessage $message ) {
                return array(
                    'id'      => $message->getId(),
                    'role'    => $message->getRole(),
                    'content' => $message->getContent(),
                    'time'    => strtotime( get_gmt_from_date( $message->getCreatedAt() ) . ' UTC' ) * 1000,
                );
            }, $messages ),
        ) );
    }

    /**
     * Speech to text for the microphone button. The recording goes to Cloud as the browser
     * made it, nothing here decodes it, and nothing is stored: the text goes back into the
     * widget's input field, and the customer sends it as an ordinary message.
     *
     * Expects (via json_data): audio (base64), format (webm|mp4|ogg - checked by Cloud).
     */
    public static function aiTranscribe()
    {
        $client = Lib\Cloud\API::getInstance()->getProduct( Lib\Cloud\Account::PRODUCT_AI );
        $raw    = $client->transcribeRaw( (string) self::parameter( 'audio', '' ), (string) self::parameter( 'format', '' ) );
        $body   = json_decode( $raw['body'], true );

        if ( $raw['status'] !== 200 || empty( $body['success'] ) ) {
            wp_send_json( array( 'success' => false, 'error' => isset( $body['error'] ) ? $body['error'] : 'ERROR_PROXY_CONNECTION' ) );
        }

        wp_send_json( array( 'success' => true, 'text' => (string) $body['text'] ) );
    }

    /**
     * Start a checkout for the conversation's pending booking.
     *
     * Runs in the visitor's own request - Gateway::createIntent() creates the Order, the
     * Appointment and a pending Payment here, exactly as it does for the booking form, and
     * Gateway::fail() removes all three again if the customer never pays.
     *
     * Expects (via json_data): token, gateway.
     */
    public static function aiCheckout()
    {
        $conversation = self::authorizeConversation();
        if ( ! $conversation || $conversation->getBookingStatus() !== Lib\Entities\AiConversation::BOOKING_PENDING ) {
            wp_send_json( array( 'success' => false, 'error' => 'ERROR_NO_PENDING_BOOKING' ) );
        }

        $gateway_name = (string) self::parameter( 'gateway', '' );
        $userData = Checkout::buildUserData( $conversation );
        if ( ! $userData ) {
            wp_send_json( array( 'success' => false, 'error' => 'ERROR_NO_PENDING_BOOKING' ) );
        }

        // The slot was free when create_booking drafted this booking, but minutes pass before
        // the customer picks a gateway, and the booking form (or another chat) may have taken
        // it since. Every entry point of the booking form runs this same check right before it
        // creates the order (Payment\Ajax, Booking\Ajax, WooCommerce\Controller::addToCart(),
        // ModernBookingForm's Request::checkStep()) - without it createIntent() would put a
        // second appointment on top of the first.
        //
        // The press is noted first, whatever comes of it: the outcome reaches the transcript
        // on its own, as the assistant message dropTakenBooking() or finishCheckout() writes.
        Checkout::addEvent( $conversation, sprintf( 'the customer pressed "%s".', Checkout::gatewayTitle( $conversation, $gateway_name ) ) );
        if ( $userData->cart->getFailedKey() !== null ) {
            wp_send_json( array_merge(
                array( 'success' => false, 'error' => 'ERROR_SLOT_NOT_AVAILABLE' ),
                self::dropTakenBooking( $conversation )
            ) );
        }

        // Only a gateway this cart may actually be paid with - the list the customer was
        // shown is advisory, the check that matters is this one.
        $allowed = wp_list_pluck( Checkout::getGateways( $userData ), 'name' );
        if ( ! in_array( $gateway_name, $allowed ) ) {
            wp_send_json( array( 'success' => false, 'error' => 'ERROR_GATEWAY_NOT_ALLOWED' ) );
        }

        if ( $gateway_name === Lib\Entities\Payment::TYPE_WOOCOMMERCE ) {
            if ( ! Lib\Config::wooCommerceEnabled() ) {
                wp_send_json( array( 'success' => false, 'error' => 'ERROR_PAYMENT' ) );
            }
            // WooCommerce itself lives in Bookly Pro - the cart goes over through the proxy,
            // which says false when an item of the cart is no longer available.
            if ( ! Lib\Proxy\Pro::addToWooCommerceCart( false, $userData ) ) {
                wp_send_json( array( 'success' => false, 'error' => 'ERROR_PAYMENT' ) );
            }
            wp_send_json( array( 'success' => true, 'target_url' => wc_get_cart_url() ) );
        }

        // Atomic claim, like aiWorker()'s on the job. The status read at the top is not
        // enough on its own - two clicks on a gateway button that both got past it would
        // each run createIntent() and book the slot twice.
        if ( ! Checkout::claim( $conversation ) ) {
            wp_send_json( array( 'success' => false, 'error' => 'ERROR_NO_PENDING_BOOKING' ) );
        }

        $request = PaymentRequest::getInstance();
        // setGatewayName() returns void, so these do not chain.
        $request->setUserData( $userData );
        $request->setGatewayName( $gateway_name );
        $request->setResponseAction( 'bookly_ai_checkout_response' );

        try {
            $gateway = $request->getGateway();
            $data = $gateway->isOnSite()
                ? $gateway->createIntent()
                : $gateway->createCheckout();
        } catch ( \Error $e ) {
            self::failCheckout( $conversation, $request, $e );
            wp_send_json( array( 'success' => false, 'error' => 'ERROR_PAYMENT' ) );
        } catch ( \Exception $e ) {
            self::failCheckout( $conversation, $request, $e );
            wp_send_json( array( 'success' => false, 'error' => 'ERROR_PAYMENT' ) );
        }

        $order = $request->getGateway()->getOrder();
        $order_id = $order ? $order->getOrderId() : null;

        Checkout::setState( $conversation, Lib\Entities\AiConversation::BOOKING_PROCESSING, $order_id );

        // createCheckout() hands bookly_order back on its own; createIntent() (the on-site
        // gateways - here that is only "pay locally") does not, and the widget needs it to
        // ask for the order's status once the payment is over.
        if ( ! isset( $data['bookly_order'] ) && $order_id ) {
            $data['bookly_order'] = Lib\Entities\Order::find( $order_id )->getToken();
        }

        wp_send_json( array_merge( array( 'success' => true ), $data ) );
    }

    /**
     * Where the payment window ends up on success or cancel. Renders nothing the visitor
     * reads - it hands the result to the chat in the window that opened it and closes.
     *
     * Same shape as BooklyPro's bookly_pro_checkout_response.
     */
    public static function aiCheckoutResponse()
    {
        $result = self::retrieveOrderResult();

        printf(
            '<script>if (window.opener && window.opener.BooklyAiAssistant) { window.opener.BooklyAiAssistant.setBookingResult(%s, %s); } window.close();</script>',
            wp_json_encode( $result['status'] ),
            wp_json_encode( $result['data'] )
        );
        exit;
    }

    /**
     * Asked by the widget when the payment window closed without reporting anything - the
     * visitor closed it by hand, or a redirect went somewhere unexpected.
     *
     * Expects (via json_data): token.
     */
    public static function aiOrderStatus()
    {
        $conversation = self::authorizeConversation();
        if ( ! $conversation ) {
            wp_send_json( array( 'success' => false, 'error' => 'ERROR_UNKNOWN_CONVERSATION' ) );
        }

        wp_send_json( array_merge( array( 'success' => true ), self::retrieveOrderResult( $conversation ) ) );
    }

    /**
     * Reported by the widget when a press on the payment card went nowhere the server could
     * see: the browser blocked the payment window, so aiCheckout() never ran. Noted for the
     * model and the log like every other card event (Checkout::addEvent()).
     *
     * Expects (via json_data): token, gateway.
     */
    public static function aiPaymentEvent()
    {
        $conversation = self::authorizeConversation();
        if ( ! $conversation || $conversation->getBookingStatus() !== Lib\Entities\AiConversation::BOOKING_PENDING ) {
            wp_send_json( array( 'success' => false, 'error' => 'ERROR_NO_PENDING_BOOKING' ) );
        }

        Checkout::addEvent( $conversation, sprintf(
            'the customer pressed "%s", but their browser blocked the payment window; nothing was charged and the card is still open.',
            Checkout::gatewayTitle( $conversation, (string) self::parameter( 'gateway', '' ) )
        ) );

        wp_send_json( array( 'success' => true ) );
    }

    /**
     * Ask the payment system where the order stands, and record the answer on the
     * conversation. Shared by aiCheckoutResponse() (which has no conversation to hand, and
     * finds it from the order token) and aiOrderStatus().
     *
     * @param Lib\Entities\AiConversation|null $conversation
     * @return array { status, data }
     */
    private static function retrieveOrderResult( $conversation = null )
    {
        $request = PaymentRequest::getInstance();

        if ( $conversation === null ) {
            $conversation = self::findConversationByOrderToken( $request->get( 'bookly_order' ) );
        }
        if ( $conversation === null ) {
            return array( 'status' => Lib\Base\Gateway::STATUS_FAILED, 'data' => array() );
        }

        // Rebuild the cart before touching the gateway: fail() empties it and complete()
        // reads the customer off it, and neither can find one in a request that carries
        // nothing but a redirect from the payment system.
        $userData = Checkout::buildUserData( $conversation );
        if ( ! $userData ) {
            // The draft cannot be rebuilt any more (the service or the staff member is gone).
            // Nothing to retry, so this one really is terminal.
            Checkout::setState( $conversation, Lib\Entities\AiConversation::BOOKING_FAILED, null );

            return array( 'status' => Lib\Base\Gateway::STATUS_FAILED, 'data' => array() );
        }

        $request->setUserData( $userData );
        $request->setResponseAction( 'bookly_ai_checkout_response' );

        try {
            $gateway = $request->getGateway();
            if ( $request->get( 'bookly_event' ) === Lib\Base\Gateway::EVENT_CANCEL ) {
                $status = Lib\Base\Gateway::STATUS_FAILED;
                $gateway->fail();
            } else {
                $status = $gateway->retrieve();
            }
        } catch ( \Exception $e ) {
            Lib\Utils\Log::error( $e->getMessage(), $e->getFile(), $e->getLine() );
            $status = Lib\Base\Gateway::STATUS_FAILED;
        }

        return array(
            'status' => $status,
            'data' => self::finishCheckout( $conversation, $status ),
        );
    }

    /**
     * Record the outcome on the conversation and, when it went through, write the
     * confirmation into the transcript.
     *
     * The confirmation is composed here rather than asked of the model: the plugin already
     * knows what was booked, so an extra Cloud round trip would only add cost and a chance
     * of the model getting the details wrong. Writing it as a real assistant message also
     * keeps the transcript honest for whatever the customer asks next.
     *
     * @param Lib\Entities\AiConversation $conversation
     * @param string                      $status
     * @return array
     */
    private static function finishCheckout( Lib\Entities\AiConversation $conversation, $status )
    {
        $text = Checkout::getResultText( $conversation, $status );

        if ( $status === Lib\Base\Gateway::STATUS_COMPLETED || $status === Lib\Base\Gateway::STATUS_PROCESSING ) {
            // PROCESSING is not a failure and not something to keep waiting on: the order,
            // the appointment and a pending payment are already in the database, and an
            // async gateway will report the money through its webhook rather than through
            // the redirect that brought us here. PayPal Payments Standard is always this
            // case - only its IPN carries payment data. The booking form draws the same
            // line (PaymentFlow::isSuccessStatus counts processing as success), so leaving
            // the customer looking at a spinner here would be both a lie and a divergence.
            Checkout::setState(
                $conversation,
                $status === Lib\Base\Gateway::STATUS_COMPLETED
                    ? Lib\Entities\AiConversation::BOOKING_PAID
                    : Lib\Entities\AiConversation::BOOKING_AWAITING,
                $conversation->getOrderId()
            );
        } else {
            // Gateway::fail() has already removed the order, the appointment and the payment,
            // but the draft is still perfectly good - put it back to "pending" so the
            // customer can pick another method instead of starting the conversation over.
            Checkout::setState( $conversation, Lib\Entities\AiConversation::BOOKING_PENDING, null );
        }

        $booked = Checkout::bookedResult( $conversation );
        Checkout::report( $conversation, $booked !== null
            ? $booked
            : 'The payment did not go through: nothing was booked or charged. The payment card is open again for the same booking, to pay another way.'
        );

        $message = new Lib\Entities\AiMessage();
        $message
            ->setConversationId( $conversation->getId() )
            ->setRole( Lib\Entities\AiMessage::ROLE_ASSISTANT )
            ->setToolName( Lib\Entities\AiMessage::FROM_PLUGIN )
            ->setContent( $text )
            ->save();

        return array( 'message' => $text, 'message_id' => $message->getId() );
    }

    /**
     * Undo a checkout that threw before it could hand the customer to the payment system,
     * and put the booking back to "pending" so they can try another gateway.
     *
     * @param Lib\Entities\AiConversation $conversation
     * @param PaymentRequest              $request
     * @param \Exception|\Error           $e
     */
    private static function failCheckout( $conversation, $request, $e )
    {
        try {
            $request->getGateway()->fail();
        } catch ( \Exception $ignore ) {
        } catch ( \Error $ignore ) {
        }

        Checkout::setState( $conversation, Lib\Entities\AiConversation::BOOKING_PENDING, null );

        Lib\Utils\Log::error( 'AI checkout failed: ' . $e->getMessage(), $e->getFile(), $e->getLine() );
    }

    /**
     * Forget a draft whose slot went to someone else while the customer was choosing how to
     * pay, and say so in the chat.
     *
     * Said in the chat for the customer and reported to the model (Checkout::report()), the
     * way finishCheckout() does it for a payment, so the model offers another time instead of
     * insisting on a booking that no longer exists. The widget shows the same text at once
     * and locks the card.
     *
     * Same targeted update as Checkout::setState(): the AI worker may own `status` on this
     * row right now, and a full save() would overwrite it.
     *
     * @param Lib\Entities\AiConversation $conversation
     * @return array {message, message_id}
     */
    private static function dropTakenBooking( $conversation )
    {
        Lib\Entities\AiConversation::query()
            ->update()
            ->set( 'booking_data', null )
            ->set( 'booking_status', null )
            ->set( 'order_id', null )
            ->set( 'updated_at', current_time( 'mysql' ) )
            ->where( 'id', $conversation->getId() )
            ->execute();

        $conversation->resetBooking();

        Checkout::report( $conversation, 'Not booked: someone else took this time before the customer paid. Nothing was charged and the payment card is closed; offer other times.' );

        $text = Checkout::getText( 'paymentSlotTaken' );

        $message = new Lib\Entities\AiMessage();
        $message
            ->setConversationId( $conversation->getId() )
            ->setRole( Lib\Entities\AiMessage::ROLE_ASSISTANT )
            ->setToolName( Lib\Entities\AiMessage::FROM_PLUGIN )
            ->setContent( $text )
            ->save();

        return array( 'message' => $text, 'message_id' => $message->getId() );
    }

    /**
     * Write the turn's outcome onto the conversation without touching anything else on the
     * row.
     *
     * aiWorker() holds one AiConversation object for the whole turn, which can run for
     * minutes across several loopback requests. In that time the visitor's own request may
     * have started and finished a checkout on the same row - saving the whole entity from a
     * copy loaded before that would quietly roll the checkout back, leaving a paid
     * appointment with no trace of it on the conversation.
     *
     * @param Lib\Entities\AiConversation $conversation
     * @param string                      $status
     * @param string|null                 $error_code
     */
    private static function setConversationStatus( $conversation, $status, $error_code = null )
    {
        Lib\Entities\AiConversation::query()
            ->update()
            ->set( 'status', $status )
            ->set( 'error_code', $error_code )
            ->set( 'updated_at', current_time( 'mysql' ) )
            ->where( 'id', $conversation->getId() )
            ->execute();

        $conversation->setStatus( $status )->setErrorCode( $error_code );
    }

    /**
     * The conversation this request is allowed to touch, or null.
     *
     * Conversation ids are sequential, so they never leave the server - and a transcript now
     * holds a name, a phone number, an email address and a checkout. The token issued with
     * the first message (bookly_ai_conversations.token, UNIQUE) is the only handle the
     * browser gets, and is what proves this browser owns the conversation.
     *
     * @return Lib\Entities\AiConversation|null
     */
    private static function authorizeConversation()
    {
        $token = (string) self::parameter( 'token', '' );

        if ( $token === '' ) {
            return null;
        }

        $conversation = new Lib\Entities\AiConversation();

        // These endpoints are anonymous: a token of a setup-assistant conversation (wp-admin)
        // must not open it here.
        return $conversation->loadBy( array( 'token' => $token, 'agent' => Lib\Entities\AiConversation::AGENT_BOOKING ) )
            ? $conversation
            : null;
    }

    /**
     * @param string $order_token
     * @return Lib\Entities\AiConversation|null
     */
    private static function findConversationByOrderToken( $order_token )
    {
        if ( ! $order_token ) {
            return null;
        }

        $order_id = Lib\Entities\Order::query()->where( 'token', $order_token )->fetchVar( 'id' );
        if ( ! $order_id ) {
            return null;
        }

        $conversation = new Lib\Entities\AiConversation();

        return $conversation->loadBy( array( 'order_id' => $order_id ) ) ? $conversation : null;
    }

    /**
     * Fire the fire-and-forget loopback request that runs aiWorker() in a
     * separate php-fpm process. 'blocking' => false means this only
     * initiates the request and returns immediately. Public so
     * Routines::handleAiJobsWatchdog() (stuck-job recovery) can call it too.
     *
     * The nonce must be created as a fully anonymous (uid 0, no session)
     * visitor, not as whoever is logged into the calling request.
     * wp_create_nonce() folds in two things tied to the calling request's
     * login state, both of which must be neutralized:
     *   1. get_current_user_id() — handled by wp_set_current_user( 0 ).
     *   2. wp_get_session_token() — reads $_COOKIE[ LOGGED_IN_COOKIE ]
     *      directly and is unaffected by wp_set_current_user(), so that
     *      cookie must also be hidden for the duration of this call.
     * Without this, aiWorker() rejects the nonce (uid 0 + real session token
     * mismatch).
     *
     * Like form_slug, the visitor's time zone travels with the loopback
     * rather than being stored: it is a property of the browser session,
     * not of the conversation, and the worker is the only reader.
     *
     * @param Lib\Entities\AiJob $job
     * @param string $form_slug
     * @param string $time_zone IANA name from the visitor's browser, '' when unknown
     */
    public static function spawnWorker( $job, $form_slug = '', $time_zone = '' )
    {
        $current_user_id = get_current_user_id();
        $logged_in_cookie = isset( $_COOKIE[ LOGGED_IN_COOKIE ] ) ? $_COOKIE[ LOGGED_IN_COOKIE ] : null;

        wp_set_current_user( 0 );
        unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
        $nonce = wp_create_nonce( 'bookly' );
        wp_set_current_user( $current_user_id );
        if ( $logged_in_cookie !== null ) {
            $_COOKIE[ LOGGED_IN_COOKIE ] = $logged_in_cookie;
        }

        wp_remote_post( admin_url( 'admin-ajax.php' ), array(
            'blocking'   => false,
            'timeout'    => 5,
            'sslverify'  => false,
            'body'       => array(
                'action'     => 'bookly_ai_worker',
                'csrf_token' => $nonce,
                'job_id'     => $job->getId(),
                'form_slug'  => $form_slug,
                'time_zone'  => $time_zone,
            ),
        ) );
    }

    /**
     * Hand a job back to 'queued' and spawn a fresh worker for it — the
     * self-chaining handoff used when aiWorker() runs low on time budget.
     *
     * Known gap: if the new loopback never starts (host blocks
     * self-requests), the job sits at 'queued' forever — the watchdog
     * (Routines::handleAiJobsWatchdog()) only catches status='running' with
     * a stale heartbeat, not this case.
     *
     * @param Lib\Entities\AiJob $job
     * @param string $form_slug
     * @param string $time_zone
     */
    private static function respawnWorker( $job, $form_slug = '', $time_zone = '' )
    {
        $job->setStatus( Lib\Entities\AiJob::STATUS_QUEUED )->save();
        self::spawnWorker( $job, $form_slug, $time_zone );
    }

    /**
     * Mark a job (and its conversation) as failed and log why.
     *
     * @param Lib\Entities\AiJob          $job
     * @param Lib\Entities\AiConversation $conversation
     * @param string                      $error_code   Machine-readable code, persisted on the
     *                                                   conversation so aiPoll() can surface it to
     *                                                   the frontend.
     * @param string|null                 $error_detail Human-readable detail (Cloud's $body['message']
     *                                                   on the failure path) — logged only, never
     *                                                   sent to the browser.
     */
    private static function failJob( $job, $conversation, $error_code, $error_detail = null )
    {
        $job->setStatus( Lib\Entities\AiJob::STATUS_FAILED )->save();
        self::setConversationStatus( $conversation, Lib\Entities\AiConversation::STATUS_ERROR, $error_code );

        Lib\Utils\Log::error( 'AI worker job #' . $job->getId() . ' failed: ' . ( $error_detail ? ( $error_code . ': ' . $error_detail ) : $error_code ), __FILE__, __LINE__ );
    }

    /**
     * Execute a tool by name and return a plain string — the shape Cloud
     * expects as a 'tool' message's "content". Never throws — an unknown
     * tool or a runtime error both become a short "Error: ..." string the
     * model can react to instead of aborting the whole conversation turn.
     *
     * @param string                      $name
     * @param array                       $arguments
     * @param Lib\Entities\AiConversation $conversation
     * @return string
     */
    private static function executeToolForModel( $name, array $arguments, $conversation )
    {
        $tools = Tools::all( $conversation );

        if ( $name === '' || ! isset( $tools[ $name ] ) ) {
            return 'Error: no such tool "' . $name . '".';
        }

        // What broke is for the log, not for the model: an exception message describes the
        // code, and retold to the customer it is neither true to them nor useful.
        try {
            return (string) $tools[ $name ]->execute( $arguments );
        } catch ( \Error $e ) {
            Lib\Utils\Log::error( 'AI tool ' . $name . ' failed: ' . $e->getMessage(), $e->getFile(), $e->getLine() );
        } catch ( \Exception $e ) {
            Lib\Utils\Log::error( 'AI tool ' . $name . ' failed: ' . $e->getMessage(), $e->getFile(), $e->getLine() );
        }

        return 'Error: the booking system failed while running ' . $name . ', nothing was changed. Apologise, and suggest trying again in a moment or contacting the business directly.';
    }

    /**
     * Build one /complete request body from a conversation's full message
     * history — system prompt and tool schema first (stable across the
     * whole tool-loop), history last (the only part that grows turn to
     * turn).
     *
     * @param Lib\Entities\AiConversation $conversation
     * @param string $form_slug Appearance the widget was published with - picks up its
     *                           business description; '' falls back to the first AI form.
     * @param string $time_zone IANA name of the visitor's browser time zone, '' when unknown.
     * @return array
     */
    private static function buildCompletionPayload( $conversation, $form_slug = '', $time_zone = '' )
    {
        $messages = array();

        /** @var Lib\Entities\AiMessage[] $history */
        $history = Lib\Entities\AiMessage::query()
            ->where( 'conversation_id', $conversation->getId() )
            ->sortBy( 'id' )
            ->find();

        // Card events since the previous customer message, sent along with the next one.
        // Pinned to a message the same way the date is, they read the same on every later call.
        $events = array();
        // The checkout's outcomes (Checkout::report()), put in as tool calls right before the
        // next customer message - never in the middle of a turn's tool loop, which the
        // customer may pay through. Each takes the events before it along, in its result.
        $reports = array();

        foreach ( $history as $message ) {
            switch ( $message->getRole() ) {
                case Lib\Entities\AiMessage::ROLE_EVENT:
                    $events[] = '(' . $message->getContent() . ')';
                    break;

                case Lib\Entities\AiMessage::ROLE_USER:
                    foreach ( $reports as $report ) {
                        $messages[] = array(
                            'role'       => 'assistant',
                            'content'    => null,
                            'tool_calls' => array( array( 'id' => $report['id'], 'name' => Checkout::STATUS_TOOL, 'arguments' => new \stdClass() ) ),
                        );
                        $messages[] = array(
                            'role'         => 'tool',
                            'tool_call_id' => $report['id'],
                            'name'         => Checkout::STATUS_TOOL,
                            'content'      => $report['content'],
                        );
                    }
                    $reports = array();

                    // The clock rides with every customer message, not in
                    // the system prompt and not only with the latest one:
                    // Cloud caches the prompt prefix, and a value that
                    // changes between calls invalidates everything after it.
                    // Pinned to the message's own created_at, each message
                    // reads the same on all 3-5 calls of one tool loop and
                    // on every later turn, so the cached prefix survives
                    // across turns as well. See SystemPrompt::dateLine().
                    $events[] = SystemPrompt::dateLine( $message->getCreatedAt(), $time_zone );
                    $messages[] = array(
                        'role'    => 'user',
                        'content' => $message->getContent() . "\n\n" . implode( "\n", $events ),
                    );
                    $events = array();
                    break;

                case Lib\Entities\AiMessage::ROLE_ASSISTANT:
                    // Written by the plugin for the customer, not by the model: what it says the
                    // model reads as Checkout::report()'s result instead.
                    if ( $message->getToolName() === Lib\Entities\AiMessage::FROM_PLUGIN ) {
                        break;
                    }
                    $entry = array( 'role' => 'assistant', 'content' => $message->getContent() );
                    if ( $message->getToolCalls() ) {
                        $tool_calls = json_decode( $message->getToolCalls(), true );
                        // A tool called with no arguments round-trips as an empty
                        // PHP array, which wp_json_encode() would re-serialize as
                        // "[]" instead of "{}" — Anthropic rejects that echoed-back
                        // empty array as an invalid tool_use.input. Force it back
                        // to an object.
                        foreach ( $tool_calls as &$tool_call ) {
                            if ( isset( $tool_call['arguments'] ) && $tool_call['arguments'] === array() ) {
                                $tool_call['arguments'] = new \stdClass();
                            }
                        }
                        unset( $tool_call );
                        $entry['tool_calls'] = $tool_calls;
                    }
                    $messages[] = $entry;
                    break;

                case Lib\Entities\AiMessage::ROLE_TOOL:
                    if ( $message->getToolName() === Checkout::STATUS_TOOL ) {
                        $events[] = $message->getContent();
                        $reports[] = array( 'id' => 'plugin_' . $message->getId(), 'content' => implode( "\n", $events ) );
                        $events = array();
                        break;
                    }
                    $messages[] = array(
                        'role'         => 'tool',
                        'tool_call_id' => $message->getToolCallId(),
                        'name'         => $message->getToolName(),
                        'content'      => $message->getContent(),
                    );
                    break;
            }
        }

        return array(
            // Picks the agent definition on the Cloud side (prompt, provider,
            // model, token cap). Sending it explicitly rather than relying on
            // Cloud's default keeps this widget's traffic identifiable in
            // Cloud's logs once other agents exist.
            'agent' => self::AGENT,
            // Facts about the site; Cloud writes the prompt from them (SystemPrompt::site()).
            'site' => SystemPrompt::site( $form_slug ),
            // Schemas are ours to define and Cloud passes them through as they
            // arrive: which tools exist here depends on this plugin's version
            // and on the active add-ons, so the set is only knowable on this
            // side, and it is current by construction.
            'tools' => array_values( array_map( function( BookingTools\ToolInterface $tool ) {
                return $tool->getSchema();
            }, Tools::all() ) ),
            'messages' => $messages,
        );
    }

    /**
     * @inheritDoc
     */
    protected static function csrfTokenValid( $action = null )
    {
        return $action === 'aiCheckoutResponse' || parent::csrfTokenValid( $action );
    }
}
