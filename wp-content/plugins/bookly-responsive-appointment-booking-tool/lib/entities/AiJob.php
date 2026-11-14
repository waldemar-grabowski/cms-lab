<?php
namespace Bookly\Lib\Entities;

use Bookly\Lib;

/**
 * A background worker job driving one tool-loop turn of an AiConversation.
 * See Bookly\Frontend\Modules\Ai\Ajax (aiSendMessage/aiWorker/aiPoll) and
 * wiki synthesis-ai-booking-assistant-architecture-emma.md §6 for the
 * async+polling flow this table exists to support.
 */
class AiJob extends Lib\Base\Entity
{
    const STATUS_QUEUED  = 'queued';
    const STATUS_RUNNING = 'running';
    const STATUS_DONE    = 'done';
    const STATUS_FAILED  = 'failed';

    /** @var int */
    protected $conversation_id;
    /** @var string */
    protected $status;
    /** @var int */
    protected $step;
    /** @var string */
    protected $heartbeat_at;
    /** @var int */
    protected $attempts;
    /** @var string */
    protected $created_at;
    /** @var string */
    protected $updated_at;

    protected static $table = 'bookly_ai_jobs';

    protected static $schema = array(
        'id' => array( 'format' => '%d' ),
        'conversation_id' => array( 'format' => '%d', 'reference' => array( 'entity' => 'AiConversation' ) ),
        'status' => array( 'format' => '%s' ),
        'step' => array( 'format' => '%d' ),
        'heartbeat_at' => array( 'format' => '%s' ),
        'attempts' => array( 'format' => '%d' ),
        'created_at' => array( 'format' => '%s' ),
        'updated_at' => array( 'format' => '%s' ),
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
     * Get status
     *
     * @return string
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * Set status
     *
     * @param string $status
     * @return $this
     */
    public function setStatus( $status )
    {
        $this->status = $status;

        return $this;
    }

    /**
     * Get step
     *
     * @return int
     */
    public function getStep()
    {
        return $this->step;
    }

    /**
     * Set step
     *
     * @param int $step
     * @return $this
     */
    public function setStep( $step )
    {
        $this->step = $step;

        return $this;
    }

    /**
     * Get heartbeat_at
     *
     * @return string
     */
    public function getHeartbeatAt()
    {
        return $this->heartbeat_at;
    }

    /**
     * Set heartbeat_at
     *
     * @param string $heartbeat_at
     * @return $this
     */
    public function setHeartbeatAt( $heartbeat_at )
    {
        $this->heartbeat_at = $heartbeat_at;

        return $this;
    }

    /**
     * Get attempts
     *
     * @return int
     */
    public function getAttempts()
    {
        return $this->attempts;
    }

    /**
     * Set attempts
     *
     * @param int $attempts
     * @return $this
     */
    public function setAttempts( $attempts )
    {
        $this->attempts = $attempts;

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

    /**
     * Get updated_at
     *
     * @return string
     */
    public function getUpdatedAt()
    {
        return $this->updated_at;
    }

    /**
     * Set updated_at
     *
     * @param string $updated_at
     * @return $this
     */
    public function setUpdatedAt( $updated_at )
    {
        $this->updated_at = $updated_at;

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
            if ( $this->getStep() === null ) {
                $this->setStep( 0 );
            }
            if ( $this->getAttempts() === null ) {
                $this->setAttempts( 0 );
            }
        }
        $this->setUpdatedAt( current_time( 'mysql' ) );

        return parent::save();
    }
}
