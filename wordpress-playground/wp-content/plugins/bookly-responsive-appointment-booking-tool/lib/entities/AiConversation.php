<?php
namespace Bookly\Lib\Entities;

use Bookly\Lib;

class AiConversation extends Lib\Base\Entity
{
    const STATUS_PROCESSING = 'processing';
    const STATUS_DONE       = 'done';
    const STATUS_ERROR      = 'error';

    /**
     * Booking statuses describe the draft the chat is currently working on - they are
     * unrelated to STATUS_* above, which describe the model's turn.
     *
     * PENDING    - create_booking collected everything and priced it; nothing is in the
     *              database yet except this draft, and the customer is picking a gateway.
     * PROCESSING - Gateway::createIntent() already created the Order/Appointment/Payment;
     *              the customer is at the payment system.
     * AWAITING   - the customer went through the payment system and the booking stands, but
     *              the gateway has not confirmed the money yet and will do it through its
     *              webhook (PayPal Payments Standard always ends up here on the way back:
     *              its return redirect carries no payment data, only the IPN does). Bookly's
     *              own booking form counts this as booked too - see
     *              BooklyPro ... ModernBookingForm\Lib\PaymentFlow::isSuccessStatus().
     * PAID/FAILED- terminal.
     */
    const BOOKING_PENDING    = 'pending';
    const BOOKING_PROCESSING = 'processing';
    const BOOKING_AWAITING   = 'awaiting';
    const BOOKING_PAID       = 'paid';
    const BOOKING_FAILED     = 'failed';

    /** Cloud agent the conversation belongs to: the booking chat or the admin assistant in wp-admin. */
    const AGENT_BOOKING = 'booking';
    const AGENT_ADMIN   = 'admin';

    /** @var string */
    protected $token;
    /** @var string */
    protected $agent = self::AGENT_BOOKING;
    /** @var int */
    protected $wp_user_id;
    /** @var string */
    protected $status;
    /** @var string */
    protected $error_code;
    /** @var string */
    protected $booking_data;
    /** @var string */
    protected $booking_status;
    /** @var int */
    protected $order_id;
    /** @var string */
    protected $snapshot;
    /** @var string */
    protected $created_at;
    /** @var string */
    protected $updated_at;

    protected static $table = 'bookly_ai_conversations';

    protected static $schema = array(
        'id' => array( 'format' => '%d' ),
        'token' => array( 'format' => '%s' ),
        'agent' => array( 'format' => '%s' ),
        'wp_user_id' => array( 'format' => '%d' ),
        'status' => array( 'format' => '%s' ),
        'error_code' => array( 'format' => '%s' ),
        'booking_data' => array( 'format' => '%s' ),
        'booking_status' => array( 'format' => '%s' ),
        'order_id' => array( 'format' => '%d' ),
        'snapshot' => array( 'format' => '%s' ),
        'created_at' => array( 'format' => '%s' ),
        'updated_at' => array( 'format' => '%s' ),
    );

    /**
     * The draft as an array, or an empty array when there is none.
     *
     * @return array
     */
    public function getBookingDataArray()
    {
        $data = $this->booking_data ? json_decode( $this->booking_data, true ) : null;

        return is_array( $data ) ? $data : array();
    }

    /**
     * @param array $data
     * @return $this
     */
    public function setBookingDataArray( array $data )
    {
        return $this->setBookingData( wp_json_encode( $data ) );
    }

    /**
     * What the conversation's agent took once and keeps for the whole conversation, by key
     * (e.g. Catalog::forConversation()). Empty when another plugin version took it: the
     * shape of what it keeps may have changed since.
     *
     * @return array
     */
    public function getSnapshotArray()
    {
        $data = $this->snapshot ? json_decode( $this->snapshot, true ) : null;

        return isset( $data['version'] ) && $data['version'] === Lib\Plugin::getVersion() ? $data : array();
    }

    /**
     * Keep a value in the snapshot, next to the other keys.
     *
     * Writes only this column: the visitor's own request may be writing the rest of the row
     * at the same time (see Frontend\Modules\Ai\Ajax::setConversationStatus()).
     *
     * @param string $key
     * @param mixed  $value
     * @return $this
     */
    public function setSnapshotValue( $key, $value )
    {
        $data = $this->getSnapshotArray();
        $data['version'] = Lib\Plugin::getVersion();
        $data[ $key ] = $value;
        // Not \u-escaped: a Cyrillic letter would take six bytes instead of two.
        $this->snapshot = wp_json_encode( $data, 256 );

        self::query()
            ->update()
            ->set( 'snapshot', $this->snapshot )
            ->where( 'id', $this->getId() )
            ->execute();

        return $this;
    }

    /**
     * Whether a checkout is already underway for this conversation. While it is, a second
     * create_booking must not overwrite the draft - the first one already has an Order and
     * an Appointment in the database keyed to it.
     *
     * @return bool
     */
    public function checkoutInProgress()
    {
        return $this->booking_status === self::BOOKING_PROCESSING;
    }

    /**
     * Clear the draft and everything the checkout wrote onto the conversation.
     *
     * @return $this
     */
    public function resetBooking()
    {
        return $this
            ->setBookingData( null )
            ->setBookingStatus( null )
            ->setOrderId( null );
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
     * Get error_code
     *
     * @return string
     */
    public function getErrorCode()
    {
        return $this->error_code;
    }

    /**
     * Set error_code
     *
     * @param string $error_code
     * @return $this
     */
    public function setErrorCode( $error_code )
    {
        $this->error_code = $error_code;

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

    /**
     * Get token
     *
     * @return string
     */
    public function getToken()
    {
        return $this->token;
    }

    /**
     * Set token
     *
     * @param string $token
     * @return $this
     */
    public function setToken( $token )
    {
        $this->token = $token;

        return $this;
    }

    /**
     * Get agent
     *
     * @return string
     */
    public function getAgent()
    {
        return $this->agent;
    }

    /**
     * Set agent
     *
     * @param string $agent
     * @return $this
     */
    public function setAgent( $agent )
    {
        $this->agent = $agent;

        return $this;
    }

    /**
     * Get wp_user_id
     *
     * @return int
     */
    public function getWpUserId()
    {
        return $this->wp_user_id;
    }

    /**
     * Set wp_user_id
     *
     * @param int $wp_user_id
     * @return $this
     */
    public function setWpUserId( $wp_user_id )
    {
        $this->wp_user_id = $wp_user_id;

        return $this;
    }

    /**
     * Get booking_data
     *
     * @return string
     */
    public function getBookingData()
    {
        return $this->booking_data;
    }

    /**
     * Set booking_data
     *
     * @param string $booking_data
     * @return $this
     */
    public function setBookingData( $booking_data )
    {
        $this->booking_data = $booking_data;

        return $this;
    }

    /**
     * Get booking_status
     *
     * @return string
     */
    public function getBookingStatus()
    {
        return $this->booking_status;
    }

    /**
     * Set booking_status
     *
     * @param string $booking_status
     * @return $this
     */
    public function setBookingStatus( $booking_status )
    {
        $this->booking_status = $booking_status;

        return $this;
    }

    /**
     * Get order_id
     *
     * @return int
     */
    public function getOrderId()
    {
        return $this->order_id;
    }

    /**
     * Set order_id
     *
     * @param int $order_id
     * @return $this
     */
    public function setOrderId( $order_id )
    {
        $this->order_id = $order_id;

        return $this;
    }

    /**
     * Get snapshot
     *
     * @return string
     */
    public function getSnapshot()
    {
        return $this->snapshot;
    }

    /**
     * Set snapshot
     *
     * @param string $snapshot
     * @return $this
     */
    public function setSnapshot( $snapshot )
    {
        $this->snapshot = $snapshot;

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
            if ( $this->getToken() === null ) {
                $this->setToken( Lib\Utils\Common::generateToken( get_class( $this ), 'token' ) );
            }
        }
        $this->setUpdatedAt( current_time( 'mysql' ) );

        return parent::save();
    }
}
