<?php
namespace Bookly\Lib\Entities;

use Bookly\Lib;

class Form extends Lib\Base\Entity
{
    const TYPE_BOOKLY_FORM = 'bookly-form';
    const TYPE_CANCELLATION_FORM = 'cancellation-confirmation';
    const TYPE_SEARCH_FORM = 'search-form';
    const TYPE_SERVICES_FORM = 'services-form';
    const TYPE_STAFF_FORM = 'staff-form';
    const TYPE_TAGS_FORM = 'tags-form';
    const TYPE_EVENTS_FORM = 'events-form';
    const TYPE_CHECKOUT_FORM = 'checkout-form';
    const TYPE_AI_ASSISTANT = 'ai-assistant-form';

    /** @var string */
    protected $type;
    /** @var string */
    protected $token;
    /** @var string */
    protected $name;
    /** @var string */
    protected $settings;
    /** @var string */
    protected $custom_css;
    /** @var string */
    protected $created_at;

    protected static $table = 'bookly_forms';

    protected static $schema = array(
        'id' => array( 'format' => '%d' ),
        'type' => array( 'format' => '%s' ),
        'name' => array( 'format' => '%s' ),
        'token' => array( 'format' => '%s' ),
        'settings' => array( 'format' => '%s' ),
        'custom_css' => array( 'format' => '%s' ),
        'created_at' => array( 'format' => '%s' ),
    );

    /**
     * @param string $type
     * @return string
     */
    public static function getTitle( $type )
    {
        switch ( $type ) {
            case self::TYPE_BOOKLY_FORM:
                return __( 'Booking form', 'bookly-responsive-appointment-booking-tool' );
            case self::TYPE_CANCELLATION_FORM:
                return __( 'Cancellation confirmation', 'bookly-responsive-appointment-booking-tool' );
            case self::TYPE_SEARCH_FORM:
                return __( 'Search form', 'bookly-responsive-appointment-booking-tool' );
            case self::TYPE_SERVICES_FORM:
                return __( 'Services form', 'bookly-responsive-appointment-booking-tool' );
            case self::TYPE_STAFF_FORM:
                return __( 'Staff form', 'bookly-responsive-appointment-booking-tool' );
            case self::TYPE_TAGS_FORM:
                return __( 'Tags form', 'bookly-responsive-appointment-booking-tool' );
            case self::TYPE_EVENTS_FORM:
                return __( 'Events form', 'bookly-responsive-appointment-booking-tool' );
            case self::TYPE_CHECKOUT_FORM:
                return __( 'Checkout form', 'bookly-responsive-appointment-booking-tool' );
            case self::TYPE_AI_ASSISTANT:
                return __( 'AI assistant', 'bookly-responsive-appointment-booking-tool' );
            default:
                return ucfirst( strtolower( str_replace( '_', ' ', $type ) ) );
        }
    }

    /**
     * @param string $type
     * @return string
     */
    public static function getDescription( $type )
    {
        switch ( $type ) {
            case self::TYPE_BOOKLY_FORM:
                return __( 'A custom block for displaying booking form', 'bookly-responsive-appointment-booking-tool' );
            case self::TYPE_CANCELLATION_FORM:
                return __( 'A custom block for displaying cancellation confirmation', 'bookly-responsive-appointment-booking-tool' );
            case self::TYPE_SEARCH_FORM:
                return __( 'A custom block for displaying search form', 'bookly-responsive-appointment-booking-tool' );
            case self::TYPE_CHECKOUT_FORM:
                return __( 'A custom block for displaying checkout form', 'bookly-responsive-appointment-booking-tool' );
            case self::TYPE_SERVICES_FORM:
                return __( 'A custom block for displaying services form', 'bookly-responsive-appointment-booking-tool' );
            case self::TYPE_STAFF_FORM:
                return __( 'A custom block for displaying staff form', 'bookly-responsive-appointment-booking-tool' );
            case self::TYPE_TAGS_FORM:
                return __( 'A custom block for displaying tags form', 'bookly-responsive-appointment-booking-tool' );
            case self::TYPE_EVENTS_FORM:
                return __( 'A custom block for displaying events form', 'bookly-responsive-appointment-booking-tool' );
            case self::TYPE_AI_ASSISTANT:
                return __( 'A custom block for displaying AI assistant form', 'bookly-responsive-appointment-booking-tool' );
            default:
                return '';
        }
    }

    /**
     * @return string[]
     */
    public static function getTypes()
    {
        return Lib\Proxy\Shared::prepareFormTypes( array(
            self::TYPE_BOOKLY_FORM,
            self::TYPE_AI_ASSISTANT,
        ) );
    }

    /**************************************************************************
     * Entity Fields Getters & Setters                                        *
     **************************************************************************/

    /**
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @param string $type
     */
    public function setType( $type )
    {
        $this->type = $type;

        return $this;
    }

    /**
     * @return string
     */
    public function getToken()
    {
        return $this->token;
    }

    /**
     * @param string $token
     */
    public function setToken( $token )
    {
        $this->token = $token;

        return $this;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param string $name
     */
    public function setName( $name )
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return string
     */
    public function getSettings()
    {
        return $this->settings;
    }

    /**
     * @param string $settings
     */
    public function setSettings( $settings )
    {
        $this->settings = $settings;

        return $this;
    }

    /**
     * @return string
     */
    public function getCustomCss()
    {
        return $this->custom_css;
    }

    /**
     * @param string $custom_css
     */
    public function setCustomCss( $custom_css )
    {
        $this->custom_css = $custom_css;

        return $this;
    }

    /**
     * Gets created_at
     *
     * @return string
     */
    public function getCreatedAt()
    {
        return $this->created_at;
    }

    /**
     * Sets created_at
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
     * @param string $value
     * @return string
     */
    private function generateToken( $value )
    {
        $token = sanitize_key( preg_replace( '/\s+/', '-', $value ?: $this->getToken() ) ) ?: 'token';
        $appendix = '';
        $entity = new self();
        while ( $entity->loadBy( array( 'token' => $token . $appendix ) ) === true ) {
            $appendix = '-' . mt_rand( 0, 1000 );
        }

        return $token . $appendix;
    }

    /**************************************************************************
     * Overridden Methods                                                     *
     **************************************************************************/

    public function save()
    {
        if ( ! $this->isLoaded() ) {
            $this->setCreatedAt( current_time( 'mysql' ) );
        }
        if ( ! $this->getToken() ) {
            $this->setToken( $this->generateToken( $this->getName() ) );
        }

        return parent::save();
    }
}
