<?php
namespace Bookly\Lib\Entities;

use Bookly\Lib;

/**
 * One turn of an AI conversation transcript.
 */
class AiMessage extends Lib\Base\Entity
{
    const ROLE_USER      = 'user';
    const ROLE_ASSISTANT = 'assistant';
    const ROLE_TOOL      = 'tool';
    // Something that happened in the chat outside the model's turn - a press on the payment
    // card, a card left unpressed. Never sent on its own: Ajax::buildCompletionPayload() appends it to
    // the next customer message, so the model and Cloud's log both see it.
    const ROLE_EVENT     = 'event';

    // tool_name of an assistant message the plugin wrote itself, not the model: a payment's
    // outcome, a time taken from under the payment card. The customer sees it like any other
    // reply; the model is not sent it and reads Checkout::report()'s result instead.
    const FROM_PLUGIN = 'plugin';

    /** @var int */
    protected $conversation_id;
    /** @var string */
    protected $role;
    /** @var string */
    protected $content;
    /** @var string */
    protected $tool_calls;
    /** @var string */
    protected $tool_call_id;
    /** @var string */
    protected $tool_name;
    /** @var string */
    protected $created_at;

    protected static $table = 'bookly_ai_messages';

    protected static $schema = array(
        'id' => array( 'format' => '%d' ),
        'conversation_id' => array( 'format' => '%d', 'reference' => array( 'entity' => 'AiConversation' ) ),
        'role' => array( 'format' => '%s' ),
        'content' => array( 'format' => '%s' ),
        'tool_calls' => array( 'format' => '%s' ),
        'tool_call_id' => array( 'format' => '%s' ),
        'tool_name' => array( 'format' => '%s' ),
        'created_at' => array( 'format' => '%s' ),
    );

    /**
     * Get conversation_id
     *
     * @return int
     */
    public function getConversationId()
    {
        return $this->conversation_id;
    }

    /**
     * Set conversation_id
     *
     * @param int $conversation_id
     * @return $this
     */
    public function setConversationId( $conversation_id )
    {
        $this->conversation_id = $conversation_id;

        return $this;
    }

    /**
     * Get role
     *
     * @return string
     */
    public function getRole()
    {
        return $this->role;
    }

    /**
     * Set role
     *
     * @param string $role
     * @return $this
     */
    public function setRole( $role )
    {
        $this->role = $role;

        return $this;
    }

    /**
     * Get content
     *
     * @return string
     */
    public function getContent()
    {
        return $this->content;
    }

    /**
     * Set content
     *
     * @param string $content
     * @return $this
     */
    public function setContent( $content )
    {
        $this->content = $content;

        return $this;
    }

    /**
     * Get tool_calls (JSON-encoded array, only set on assistant messages that requested tools)
     *
     * @return string
     */
    public function getToolCalls()
    {
        return $this->tool_calls;
    }

    /**
     * Set tool_calls
     *
     * @param string $tool_calls
     * @return $this
     */
    public function setToolCalls( $tool_calls )
    {
        $this->tool_calls = $tool_calls;

        return $this;
    }

    /**
     * Get tool_call_id (only set on role=tool messages)
     *
     * @return string
     */
    public function getToolCallId()
    {
        return $this->tool_call_id;
    }

    /**
     * Set tool_call_id
     *
     * @param string $tool_call_id
     * @return $this
     */
    public function setToolCallId( $tool_call_id )
    {
        $this->tool_call_id = $tool_call_id;

        return $this;
    }

    /**
     * Get tool_name (only set on role=tool messages)
     *
     * @return string
     */
    public function getToolName()
    {
        return $this->tool_name;
    }

    /**
     * Set tool_name
     *
     * @param string $tool_name
     * @return $this
     */
    public function setToolName( $tool_name )
    {
        $this->tool_name = $tool_name;

        return $this;
    }

    /**
     * Get created_at
     *
     * @return string
     */
    public function getCreatedAt()
    {
        return $this->created_at;
    }

    /**
     * Set created_at
     *
     * @param string $created_at
     * @return $this
     */
    public function setCreatedAt( $created_at )
    {
        $this->created_at = $created_at;

        return $this;
    }

    /**************************************************************************
     * Overridden Methods                                                     *
     **************************************************************************/

    /**
     * @inheritDoc
     */
    public function save()
    {
        if ( $this->getId() == null ) {
            $this->setCreatedAt( current_time( 'mysql' ) );
        }

        return parent::save();
    }
}
