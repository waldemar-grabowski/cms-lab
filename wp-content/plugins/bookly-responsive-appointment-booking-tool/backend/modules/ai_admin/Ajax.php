<?php
namespace Bookly\Backend\Modules\AiAdmin;

use Bookly\Frontend\Modules\Ai\SystemPrompt;
use Bookly\Lib;
use Bookly\Lib\AiAdmin\Knowledge;
use Bookly\Lib\AiAdmin\Proposal;

/**
 * AJAX handlers of the setup assistant in wp-admin. The round trip is the one of the booking
 * chat (frontend/modules/ai/Ajax.php): a message queues an AiJob, a loopback request runs the
 * tool loop against Cloud /complete (agent "admin"), the browser polls. Apply and Undo run in
 * the admin's own request — the loop never writes.
 *
 *   - wp_ajax_bookly_ai_admin_send    (aiAdminSend)
 *   - wp_ajax_bookly_ai_admin_worker  (aiAdminWorker, loopback)
 *   - wp_ajax_bookly_ai_admin_poll    (aiAdminPoll)
 *   - wp_ajax_bookly_ai_admin_apply   (aiAdminApply)
 *   - wp_ajax_bookly_ai_admin_undo    (aiAdminUndo)
 *   - wp_ajax_bookly_ai_admin_transcribe (aiAdminTranscribe)
 */
class Ajax extends Lib\Base\Ajax
{
    const WORKER_TIME_BUDGET = 20;
    const WORKER_MARGIN      = 5;
    const MAX_STEPS          = 16;
    /** Tool results of earlier questions longer than this go to the model as a stub. */
    const STUB_FROM          = 300;

    /** Event message with a note for the model, sent along with the next user message. */
    const NOTE = 'ai_admin_note';
    /** Event message with the site line (see siteLine()) as it was when it last changed. */
    const SITE = 'ai_admin_site';

    protected static function permissions()
    {
        return array( '_default' => 'admin', 'aiAdminWorker' => 'anonymous' );
    }

    /**
     * Expects: message, token (optional — a new conversation without it).
     */
    public static function aiAdminSend()
    {
        $text = trim( (string) self::parameter( 'message', '' ) );
        if ( $text === '' ) {
            wp_send_json_error( array( 'error' => 'ERROR_EMPTY_MESSAGE' ) );
        }
        if ( ! Knowledge::available() ) {
            wp_send_json_error( array( 'error' => 'ERROR_PRODUCT_NOT_ACTIVE' ) );
        }
        // json_data turns an empty string into null — compare as a string.
        if ( (string) self::parameter( 'token', '' ) !== '' ) {
            $conversation = self::authorizeConversation();
            if ( ! $conversation ) {
                wp_send_json_error( array( 'error' => 'ERROR_UNKNOWN_CONVERSATION' ) );
            }
        } else {
            $conversation = new Lib\Entities\AiConversation();
            $conversation
                ->setAgent( Lib\Entities\AiConversation::AGENT_ADMIN )
                ->setWpUserId( get_current_user_id() );
        }
        $conversation->setStatus( Lib\Entities\AiConversation::STATUS_PROCESSING )->save();

        self::pinSiteLine( $conversation->getId() );

        $message = new Lib\Entities\AiMessage();
        $message
            ->setConversationId( $conversation->getId() )
            ->setRole( Lib\Entities\AiMessage::ROLE_USER )
            ->setContent( $text )
            ->save();

        self::startTurn( $conversation );

        wp_send_json_success( array( 'token' => $conversation->getToken(), 'last_message_id' => $message->getId() ) );
    }

    /**
     * The tool loop, in its own loopback request. Runs as the admin who owns the conversation,
     * so tools check the same rights as the interface. Expects: job_id.
     */
    public static function aiAdminWorker()
    {
        $job_id = (int) self::parameter( 'job_id', 0 );
        $agent  = Lib\Entities\AiJob::query( 'j' )
            ->select( 'c.agent' )
            ->leftJoin( 'AiConversation', 'c', 'c.id = j.conversation_id' )
            ->where( 'j.id', $job_id )
            ->fetchVar( 'agent' );
        if ( $agent !== Lib\Entities\AiConversation::AGENT_ADMIN ) {
            wp_die();
        }
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
        $conversation = Lib\Entities\AiConversation::find( $job->getConversationId() );
        wp_set_current_user( (int) $conversation->getWpUserId() );

        ignore_user_abort( true );
        @set_time_limit( 0 );

        $deadline = microtime( true ) + self::WORKER_TIME_BUDGET;
        $step     = $job->getStep();
        $tools    = Tools::all( $conversation );

        while ( $step < self::MAX_STEPS ) {
            $step++;
            $client = Lib\Cloud\API::getInstance()->getProduct( Lib\Cloud\Account::PRODUCT_AI_ADMIN );
            $raw    = $client->completeRaw( self::buildCompletionPayload( $conversation ) );
            $body   = json_decode( $raw['body'], true );

            if ( $raw['status'] !== 200 || empty( $body['success'] ) ) {
                $error_code   = isset( $body['error'] ) ? $body['error'] : 'ERROR_PROXY_CONNECTION';
                $error_detail = isset( $body['message'] ) && is_string( $body['message'] ) ? $body['message'] : null;
                self::failJob( $job, $conversation, $error_code, $error_detail );

                return;
            }

            $assistant = new Lib\Entities\AiMessage();
            $assistant
                ->setConversationId( $conversation->getId() )
                ->setRole( Lib\Entities\AiMessage::ROLE_ASSISTANT )
                ->setContent( isset( $body['message']['content'] ) ? $body['message']['content'] : null )
                ->setToolCalls( ! empty( $body['message']['tool_calls'] ) ? wp_json_encode( $body['message']['tool_calls'] ) : null )
                ->save();
            $job->setStep( $step )->setHeartbeatAt( current_time( 'mysql' ) )->save();

            if ( $body['finish_reason'] !== 'tool_calls' || empty( $body['message']['tool_calls'] ) ) {
                self::finish( $job, $conversation );

                return;
            }

            // A turn ends with a card or a question: after a shown propose or offer_choices there
            // is nothing for the model to add until the user acts.
            $turn_over = false;
            foreach ( $body['message']['tool_calls'] as $tool_call ) {
                $name   = isset( $tool_call['name'] ) ? $tool_call['name'] : '';
                $result = self::executeTool( $tools, $name, (array) $tool_call['arguments'] );

                $tool_message = new Lib\Entities\AiMessage();
                $tool_message
                    ->setConversationId( $conversation->getId() )
                    ->setRole( Lib\Entities\AiMessage::ROLE_TOOL )
                    ->setToolCallId( $tool_call['id'] )
                    ->setToolName( $name )
                    ->setContent( $result )
                    ->save();

                if ( self::endsTurn( $name, $result ) ) {
                    $turn_over = true;
                }
            }
            if ( $turn_over ) {
                self::finish( $job, $conversation );

                return;
            }
            if ( microtime( true ) > $deadline - self::WORKER_MARGIN ) {
                $job->setStatus( Lib\Entities\AiJob::STATUS_QUEUED )->save();
                self::spawnWorker( $job );

                return;
            }
        }

        self::failJob( $job, $conversation, 'ERROR_MAX_STEPS' );
    }

    /**
     * Messages of both sides, cards and questions after after_id. Expects: token, after_id.
     */
    public static function aiAdminPoll()
    {
        $conversation = self::authorizeConversation();
        if ( ! $conversation ) {
            wp_send_json_error( array( 'error' => 'ERROR_UNKNOWN_CONVERSATION' ) );
        }
        /** @var Lib\Entities\AiMessage[] $messages */
        $messages = Lib\Entities\AiMessage::query()
            ->where( 'conversation_id', $conversation->getId() )
            ->whereGt( 'id', (int) self::parameter( 'after_id', 0 ) )
            ->sortBy( 'id' )
            ->find();

        $items = array();
        foreach ( $messages as $message ) {
            // Time as a UTC timestamp in ms, for the browser to show in its own time zone.
            $item = array(
                'id'   => $message->getId(),
                'time' => strtotime( get_gmt_from_date( $message->getCreatedAt() ) . ' UTC' ) * 1000,
            );
            if ( $message->getRole() === Lib\Entities\AiMessage::ROLE_USER ) {
                // The user's own messages are only needed to restore the conversation
                // (after_id = 0) — e.g. after the admin moves to another page.
                $item['type'] = 'user';
                $item['text'] = $message->getContent();
            } elseif ( $message->getRole() === Lib\Entities\AiMessage::ROLE_ASSISTANT && trim( (string) $message->getContent() ) !== '' ) {
                $item['type'] = 'text';
                $item['text'] = $message->getContent();
            } elseif ( $message->getRole() === Lib\Entities\AiMessage::ROLE_EVENT && $message->getToolName() === Tools\Propose::CARD ) {
                $card         = json_decode( $message->getContent(), true );
                $item['type'] = 'card';
                $item['card'] = array(
                    'status' => $card['status'],
                    'reason' => $card['reason'],
                    'items'  => $card['items'],
                    // Cards saved before the grouping have none: the browser shows the rows then.
                    'groups' => isset( $card['groups'] ) ? $card['groups'] : null,
                    'errors' => isset( $card['errors'] ) ? $card['errors'] : array(),
                );
            } elseif ( $message->getRole() === Lib\Entities\AiMessage::ROLE_TOOL && $message->getToolName() === Tools\OfferChoices::NAME ) {
                $item['type']    = 'choices';
                $item['choices'] = json_decode( $message->getContent(), true );
            } else {
                $item['type'] = 'skip';
            }
            $items[] = $item;
        }

        wp_send_json_success( array(
            'status'     => $conversation->getStatus(),
            'error_code' => $conversation->getStatus() === Lib\Entities\AiConversation::STATUS_ERROR ? $conversation->getErrorCode() : null,
            'items'      => $items,
        ) );
    }

    /**
     * Apply a card. Expects: token, card_id.
     */
    public static function aiAdminApply()
    {
        $card = self::card();
        $data = json_decode( $card->getContent(), true );
        if ( $data['status'] !== 'pending' ) {
            // The browser may still show the card as pending (replaced by a newer one meanwhile).
            wp_send_json_error( array( 'error' => 'ERROR_CARD_STATE', 'status' => $data['status'] ) );
        }
        $result = Proposal::apply( $data['changes'], $data['items'] );
        if ( $result['errors'] ) {
            $data['status'] = 'failed';
            $data['errors'] = $result['errors'];
            $card->setContent( wp_json_encode( $data ) )->save();
            self::note( $card->getConversationId(), 'The user pressed Apply, but the check failed (settings changed meanwhile): ' . implode( '; ', $result['errors'] ) );
            wp_send_json_error( array( 'error' => 'ERROR_CHECK_FAILED', 'errors' => $result['errors'] ) );
        }
        $data['status']   = 'applied';
        $data['snapshot'] = $result['snapshot'];
        if ( ! empty( $result['notices'] ) ) {
            $data['notices'] = $result['notices'];
        }
        $card->setContent( wp_json_encode( $data ) )->save();
        self::note( $card->getConversationId(), empty( $result['notices'] )
            ? 'The user applied the proposal; the changes are saved.'
            : 'The user applied the proposal; the changes are saved, but saving reported errors: ' . implode( '; ', $result['notices'] ) );

        $response = array( 'status' => 'applied' );
        if ( ! empty( $result['notices'] ) ) {
            $response['notices'] = $result['notices'];
        }
        // A card that is one part of the request: the model goes on with the next part without
        // waiting for the user to write. Not while a turn is running — the note rides with it.
        if ( ! empty( $data['continues'] ) ) {
            $conversation = Lib\Entities\AiConversation::find( $card->getConversationId() );
            if ( $conversation->getStatus() !== Lib\Entities\AiConversation::STATUS_PROCESSING ) {
                $conversation->setStatus( Lib\Entities\AiConversation::STATUS_PROCESSING )->save();
                self::pinSiteLine( $conversation->getId() );
                self::startTurn( $conversation );
                $response['processing'] = true;
            }
        }

        wp_send_json_success( $response );
    }

    /**
     * Undo an applied card. Expects: token, card_id.
     */
    public static function aiAdminUndo()
    {
        $card = self::card();
        $data = json_decode( $card->getContent(), true );
        if ( $data['status'] !== 'applied' ) {
            wp_send_json_error( array( 'error' => 'ERROR_CARD_STATE' ) );
        }
        Proposal::undo( $data['snapshot'] );
        $data['status'] = 'undone';
        $card->setContent( wp_json_encode( $data ) )->save();
        self::note( $card->getConversationId(), 'The user undid the applied proposal; the previous values are back.' );

        wp_send_json_success( array( 'status' => 'undone' ) );
    }

    /**
     * Speech to text for the panel's microphone button: the text goes back to the field, the
     * admin sends it. Expects: audio (base64 of the recording), format (webm|mp4|ogg).
     */
    public static function aiAdminTranscribe()
    {
        if ( ! Knowledge::available() ) {
            wp_send_json_error( array( 'error' => 'ERROR_PRODUCT_NOT_ACTIVE' ) );
        }
        /** @var Lib\Cloud\AiAdmin $client */
        $client = Lib\Cloud\API::getInstance()->getProduct( Lib\Cloud\Account::PRODUCT_AI_ADMIN );
        $raw    = $client->transcribeRaw( (string) self::parameter( 'audio', '' ), (string) self::parameter( 'format', '' ) );
        $body   = json_decode( $raw['body'], true );

        if ( $raw['status'] !== 200 || empty( $body['success'] ) ) {
            wp_send_json_error( array( 'error' => isset( $body['error'] ) ? $body['error'] : 'ERROR_PROXY_CONNECTION' ) );
        }

        wp_send_json_success( array( 'text' => (string) $body['text'] ) );
    }

    /**
     * Loopback that runs aiAdminWorker() — the nonce is created as an anonymous visitor, as in
     * the booking chat (see Frontend\Modules\Ai\Ajax::spawnWorker()).
     *
     * @param Lib\Entities\AiJob $job
     */
    public static function spawnWorker( $job )
    {
        $current_user_id  = get_current_user_id();
        $logged_in_cookie = isset( $_COOKIE[ LOGGED_IN_COOKIE ] ) ? $_COOKIE[ LOGGED_IN_COOKIE ] : null;
        wp_set_current_user( 0 );
        unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
        $nonce = wp_create_nonce( 'bookly' );
        wp_set_current_user( $current_user_id );
        if ( $logged_in_cookie !== null ) {
            $_COOKIE[ LOGGED_IN_COOKIE ] = $logged_in_cookie;
        }

        wp_remote_post( admin_url( 'admin-ajax.php' ), array(
            'blocking'  => false,
            'timeout'   => 5,
            'sslverify' => false,
            'body'      => array(
                'action'     => 'bookly_ai_admin_worker',
                'csrf_token' => $nonce,
                'job_id'     => $job->getId(),
            ),
        ) );
    }

    /* ---------------------------------------------------------------- internals */

    /**
     * A shown card or a question: nothing for the model to add until the user acts.
     *
     * @param string $tool_name
     * @param string $result
     * @return bool
     */
    private static function endsTurn( $tool_name, $result )
    {
        return $tool_name === Tools\OfferChoices::NAME
            || ( $tool_name === Tools\Propose::NAME && strpos( $result, '"status":"shown"' ) !== false );
    }

    /**
     * Queue a turn of the model and start the worker.
     *
     * @param Lib\Entities\AiConversation $conversation
     */
    private static function startTurn( $conversation )
    {
        $job = new Lib\Entities\AiJob();
        $job
            ->setConversationId( $conversation->getId() )
            ->setStatus( Lib\Entities\AiJob::STATUS_QUEUED )
            ->save();

        self::spawnWorker( $job );
    }

    /**
     * /complete request: the agent, the tools, the history. The rules and the knowledge are
     * Cloud's (see Lib\AiAdmin\Knowledge), so no prompt is sent.
     *
     * The history keeps every question, answer and proposal, but the data tools returned for
     * earlier questions (knowledge files, read tools) is replaced by a short stub: the model
     * re-reads what it needs, so the request stays small in a long conversation, and data read
     * before a change was applied is not mistaken for the current state.
     *
     * @param Lib\Entities\AiConversation $conversation
     * @return array
     */
    private static function buildCompletionPayload( $conversation )
    {
        /** @var Lib\Entities\AiMessage[] $history */
        $history = Lib\Entities\AiMessage::query()
            ->where( 'conversation_id', $conversation->getId() )
            ->sortBy( 'id' )
            ->find();

        // Where the current turn starts: the last user message, or a note after a finished turn
        // (Apply of a card that continues the request).
        $current = 0;
        $ended   = false;
        foreach ( $history as $message ) {
            switch ( $message->getRole() ) {
                case Lib\Entities\AiMessage::ROLE_USER:
                    $current = $message->getId();
                    $ended   = false;
                    break;
                case Lib\Entities\AiMessage::ROLE_ASSISTANT:
                    $ended = false;
                    break;
                case Lib\Entities\AiMessage::ROLE_TOOL:
                    $ended = self::endsTurn( $message->getToolName(), $message->getContent() );
                    break;
                case Lib\Entities\AiMessage::ROLE_EVENT:
                    if ( $ended && $message->getToolName() === self::NOTE ) {
                        $current = $message->getId();
                    }
                    break;
            }
        }

        $messages = array();
        $notes    = array();
        $site     = null;
        // The last message is a card or a question that ended a turn (see aiAdminWorker()).
        $turn_over = false;
        // Notes after such a turn and before the next user message (Apply of a card that
        // continues the request starts a turn without one) go as a message of their own.
        $flush = function ( $time ) use ( &$messages, &$notes, &$site ) {
            $notes[] = SystemPrompt::dateLine( $time );
            if ( $site !== null ) {
                $notes[] = $site;
                $site    = null;
            }
            $messages[] = array( 'role' => 'user', 'content' => implode( "\n", $notes ) );
            $notes      = array();
        };
        $notes_time = null;
        foreach ( $history as $message ) {
            switch ( $message->getRole() ) {
                case Lib\Entities\AiMessage::ROLE_EVENT:
                    if ( $message->getToolName() === self::NOTE ) {
                        $notes[]    = '(' . $message->getContent() . ')';
                        $notes_time = $message->getCreatedAt();
                    } elseif ( $message->getToolName() === self::SITE ) {
                        $site = $message->getContent();
                    }
                    break;
                case Lib\Entities\AiMessage::ROLE_USER:
                    // The date rides with the message, not in the prompt: the prompt stays static
                    // and cached (see SystemPrompt::dateLine()). The site line — only with the
                    // message it changed before, as it was then: earlier messages stay as sent.
                    $notes[] = SystemPrompt::dateLine( $message->getCreatedAt() );
                    if ( $site !== null ) {
                        $notes[] = $site;
                        $site    = null;
                    }
                    $messages[] = array( 'role' => 'user', 'content' => $message->getContent() . "\n\n" . implode( "\n", $notes ) );
                    $notes      = array();
                    $turn_over  = false;
                    break;
                case Lib\Entities\AiMessage::ROLE_ASSISTANT:
                    if ( $turn_over && $notes ) {
                        $flush( $notes_time );
                    }
                    $turn_over = false;
                    $entry = array( 'role' => 'assistant', 'content' => $message->getContent() );
                    if ( $message->getToolCalls() ) {
                        $tool_calls = json_decode( $message->getToolCalls(), true );
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
                    $content = $message->getContent();
                    // A knowledge file is short here, a reference Cloud fills in with its text.
                    if ( $message->getId() < $current
                        && ! in_array( $message->getToolName(), array( Tools\Propose::NAME, Tools\OfferChoices::NAME ), true )
                        && ( strlen( $content ) > self::STUB_FROM || $message->getToolName() === Tools\GetKnowledge::NAME )
                    ) {
                        $content = 'Result for an earlier question, removed to keep the conversation short. Call the tool again if you need it now — the data may have changed since.';
                    }
                    $messages[] = array(
                        'role'         => 'tool',
                        'tool_call_id' => $message->getToolCallId(),
                        'name'         => $message->getToolName(),
                        'content'      => $content,
                    );
                    $turn_over = self::endsTurn( $message->getToolName(), $content );
                    break;
            }
        }
        if ( $turn_over && $notes ) {
            $flush( $notes_time );
        }

        return array(
            'agent'    => Lib\Entities\AiConversation::AGENT_ADMIN,
            'tools'    => array_values( array_map( function ( Tools\Tool $tool ) {
                return $tool->getSchema();
            }, Tools::all() ) ),
            'messages' => $messages,
        );
    }

    /**
     * Save the site line as an event before the user's message when it differs from the last one
     * of the conversation (or there is none yet), so the model gets it once and again only after
     * a change — not with every message.
     *
     * @param int $conversation_id
     */
    private static function pinSiteLine( $conversation_id )
    {
        $line = self::siteLine();
        $last = Lib\Entities\AiMessage::query()
            ->where( 'conversation_id', $conversation_id )
            ->where( 'role', Lib\Entities\AiMessage::ROLE_EVENT )
            ->where( 'tool_name', self::SITE )
            ->sortBy( 'id' )
            ->order( 'DESC' )
            ->limit( 1 )
            ->fetchVar( 'content' );
        if ( $last !== $line ) {
            $event = new Lib\Entities\AiMessage();
            $event
                ->setConversationId( $conversation_id )
                ->setRole( Lib\Entities\AiMessage::ROLE_EVENT )
                ->setToolName( self::SITE )
                ->setContent( $line )
                ->save();
        }
    }

    /**
     * Site conventions the owner reads in wp-admin, so the model writes times, dates, weekdays and
     * prices the same way and means the site's clock: time zone, first day of the week, WordPress
     * time and date formats (shown on a fixed sample moment, so the line changes only with the
     * settings), Bookly price format, and the installed add-ons.
     *
     * @return string
     */
    private static function siteLine()
    {
        global $wp_locale;
        $ts = mktime( 13, 30, 0, 1, 31, 2026 );

        // The worker runs as the conversation's owner: their wp-admin language. The installed
        // add-ons ride here too, so the model needs no call to learn them.
        $addons = Lib\AiAdmin\Access::installedAddons();

        return sprintf(
            '(Site: Bookly %s; wp-admin language %s; time zone %s; week starts on %s; times are written like "%s", dates like "%s", prices like "%s". Use these in what you write. Installed add-ons: %s.)',
            Lib\Plugin::getVersion(),
            get_user_locale(),
            Lib\Config::getWPTimeZone(),
            $wp_locale->get_weekday( (int) get_option( 'start_of_week', 0 ) ),
            date_i18n( get_option( 'time_format' ), $ts ),
            date_i18n( get_option( 'date_format' ), $ts ),
            html_entity_decode( strip_tags( Lib\Utils\Price::format( 1234.5, get_option( 'bookly_pmt_currency' ) ) ), ENT_QUOTES, 'UTF-8' ),
            $addons ? implode( ', ', $addons ) : 'none (no Pro)'
        );
    }

    /**
     * @param Tools\Tool[] $tools
     * @param string       $name
     * @param array        $arguments
     * @return string
     */
    private static function executeTool( array $tools, $name, array $arguments )
    {
        if ( ! isset( $tools[ $name ] ) ) {
            return 'Error: no such tool "' . $name . '".';
        }
        try {
            return (string) $tools[ $name ]->execute( $arguments );
        } catch ( \Error $e ) {
            Lib\Utils\Log::error( 'AI setup tool ' . $name . ' failed: ' . $e->getMessage(), $e->getFile(), $e->getLine() );
        } catch ( \Exception $e ) {
            Lib\Utils\Log::error( 'AI setup tool ' . $name . ' failed: ' . $e->getMessage(), $e->getFile(), $e->getLine() );
        }

        return 'Error: ' . $name . ' failed in the plugin, nothing was changed.';
    }

    /**
     * The admin's own conversation by token.
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

        return $conversation->loadBy( array(
            'token'      => $token,
            'agent'      => Lib\Entities\AiConversation::AGENT_ADMIN,
            'wp_user_id' => get_current_user_id(),
        ) ) ? $conversation : null;
    }

    /**
     * Card of the request's conversation by card_id, or the request ends with an error.
     *
     * @return Lib\Entities\AiMessage
     */
    private static function card()
    {
        $conversation = self::authorizeConversation();
        $card         = new Lib\Entities\AiMessage();
        if ( ! $conversation || ! $card->loadBy( array(
                'id'              => (int) self::parameter( 'card_id', 0 ),
                'conversation_id' => $conversation->getId(),
                'tool_name'       => Tools\Propose::CARD,
            ) ) ) {
            wp_send_json_error( array( 'error' => 'ERROR_UNKNOWN_CARD' ) );
        }

        return $card;
    }

    /**
     * @param int    $conversation_id
     * @param string $text
     */
    private static function note( $conversation_id, $text )
    {
        $note = new Lib\Entities\AiMessage();
        $note
            ->setConversationId( $conversation_id )
            ->setRole( Lib\Entities\AiMessage::ROLE_EVENT )
            ->setToolName( self::NOTE )
            ->setContent( $text )
            ->save();
    }

    /**
     * @param Lib\Entities\AiJob          $job
     * @param Lib\Entities\AiConversation $conversation
     */
    private static function finish( $job, $conversation )
    {
        $conversation->setStatus( Lib\Entities\AiConversation::STATUS_DONE )->save();
        $job->setStatus( Lib\Entities\AiJob::STATUS_DONE )->save();
    }

    /**
     * @param Lib\Entities\AiJob          $job
     * @param Lib\Entities\AiConversation $conversation
     * @param string                      $error_code
     * @param string|null                 $error_detail
     */
    private static function failJob( $job, $conversation, $error_code, $error_detail = null )
    {
        $job->setStatus( Lib\Entities\AiJob::STATUS_FAILED )->save();
        $conversation->setStatus( Lib\Entities\AiConversation::STATUS_ERROR )->setErrorCode( $error_code )->save();
        Lib\Utils\Log::error( 'AI setup job #' . $job->getId() . ' failed: ' . ( $error_detail ? $error_code . ': ' . $error_detail : $error_code ), __FILE__, __LINE__ );
    }
}
