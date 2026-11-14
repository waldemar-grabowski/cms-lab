<?php
namespace Bookly\Lib;

use Bookly\Frontend\Modules\Booking\Proxy as BookingProxy;
use Bookly\Lib\Proxy\Pro;
use Bookly\Lib\Utils\Collection;

class UserBookingData
{
    // Protected properties
    protected $first_rendered_step = 1;

    // Step 0
    /** @var string */
    protected $time_zone;
    /** @var int */
    protected $time_zone_offset;
    /** @var int */
    protected $wp_user_id;

    // Step service
    /** @var string Y-m-d */
    protected $date_from;
    /** @var array */
    protected $days;
    /** @var null|string H:i (null means min start time) */
    protected $time_from;
    /** @var null|string H:i (null means max end time) */
    protected $time_to;

    // Step time
    protected $slots = array();

    // Step details
    /** @var int */
    protected $facebook_id;
    /** @var string */
    protected $full_name;
    /** @var string */
    protected $first_name;
    /** @var string */
    protected $last_name;
    /** @var string */
    protected $email;
    /** @var string */
    protected $email_confirm;
    /** @var string */
    protected $country;
    /** @var string */
    protected $state;
    /** @var string */
    protected $postcode;
    /** @var string */
    protected $city;
    /** @var string */
    protected $street;
    /** @var string */
    protected $street_number;
    /** @var string */
    protected $additional_address;
    /** @var string */
    protected $full_address;
    /** @var string */
    protected $phone;
    /** @var array */
    protected $birthday;
    /** @var  string */
    protected $notes;
    /** @var array */
    protected $info_fields = array();
    /** @var array for WC checkout */
    protected $address_iso = array();

    // Step payment
    /** @var string */
    protected $coupon_code;
    /** @var string */
    protected $gift_code;
    /** @var float */
    protected $gift_card_amount;
    /** @var bool */
    protected $deposit_full = 0;
    /** @var float */
    protected $tips;

    // Cart item keys being edited
    /** @var array */
    protected $edit_cart_keys = array();
    /** @var bool */
    protected $repeated = 0;
    /** @var array */
    protected $repeat_data = array();

    /** @var string */
    protected $order_id;

    // Verification code
    /** @var string */
    protected $verification_code;
    /** @var string|null Recipient (phone/email) the current code was sent to */
    protected $verification_code_recipient;
    /** @var int Unix timestamp of the last verification code dispatch */
    protected $verification_code_sent_at = 0;
    /** @var int Number of verification code dispatches in this session */
    protected $verification_resend_count = 0;
    /** @var string|null Recipient (phone/email) already verified in this session */
    protected $verification_verified_recipient;
    /** @var int Number of consecutive wrong verification code entries */
    protected $verification_attempt_count = 0;

    // Private

    /** @var string */
    private $form_id;

    /** @var array */
    private $session = array();

    // Frontend expect variables
    private $properties = array(
        'first_rendered_step',
        // Step 0
        'time_zone',
        'time_zone_offset',
        // Step service
        'date_from',
        'days',
        'time_from',
        'time_to',
        // Step time
        'slots',
        // Step details
        'facebook_id',
        'full_name',
        'first_name',
        'last_name',
        'email',
        'email_confirm',
        'phone',
        'birthday',
        'full_address',
        'additional_address',
        'country',
        'state',
        'postcode',
        'city',
        'street',
        'street_number',
        'address_iso',
        'notes',
        'info_fields',
        // Step payment
        'coupon_code',
        'gift_code',
        'deposit_full',
        'tips',
        // Cart item keys being edited
        'edit_cart_keys',
        'repeated',
        'repeat_data',
    );

    /** @var Entities\Customer */
    private $customer;
    /** @var \BooklyCoupons\Lib\Entities\Coupon|null */
    private $coupon;
    /** @var \BooklyGiftCards\Lib\Entities\GiftCard|null */
    private $gift_card;
    /** @var integer|null */
    private $payment_id;
    /** @var string */
    private $payment_type = Entities\Payment::TYPE_LOCAL;

    // Public

    /** @var Cart */
    public $cart;
    /** @var Chain */
    public $chain;

    /**
     * @param string $form_id
     * @param int|null $current_user_id
     */
    public function __construct( $form_id, $current_user_id = null )
    {
        $this->form_id = $form_id;
        $this->cart = new Cart( $this );
        $this->chain = new Chain();

        if ( Config::depositPaymentsActive() && get_option( 'bookly_deposit_allow_full_payment' ) === '2' ) {
            $this->deposit_full = 1;
        }

        // If logged in then set name, email and if existing customer then also phone.
        $current_user = $current_user_id === null
            ? wp_get_current_user()
            : get_userdata( $current_user_id );
        $this->wp_user_id = 0;
        if ( $current_user && $current_user->ID ) {
            $this->wp_user_id = $current_user->ID;
            $customer = new Entities\Customer();
            if ( $customer->loadBy( array( 'wp_user_id' => $this->wp_user_id ) ) ) {
                if ( $customer->getBirthday() ) {
                    $date = explode( '-', $customer->getBirthday() );
                    $this->setBirthday( array(
                        'year' => $date[0],
                        'month' => isset( $date[1] ) ? (int) $date[1] : 0,
                        'day' => isset( $date[2] ) ? (int) $date[2] : 0,
                    ) );
                }
                $this
                    ->setFullName( $customer->getFullName() )
                    ->setFirstName( $customer->getFirstName() )
                    ->setLastName( $customer->getLastName() )
                    ->setEmail( $customer->getEmail() )
                    ->setEmailConfirm( $customer->getEmail() )
                    ->setPhone( $customer->getPhone() )
                    ->setCountry( $customer->getCountry() )
                    ->setState( $customer->getState() )
                    ->setPostcode( $customer->getPostcode() )
                    ->setCity( $customer->getCity() )
                    ->setStreet( $customer->getStreet() )
                    ->setStreetNumber( $customer->getStreetNumber() )
                    ->setAdditionalAddress( $customer->getAdditionalAddress() )
                    ->setInfoFields( json_decode( $customer->getInfoFields(), true ) );
            } else {
                $this
                    ->setFullName( $current_user->display_name )
                    ->setFirstName( $current_user->user_firstname )
                    ->setLastName( $current_user->user_lastname )
                    ->setEmail( $current_user->user_email )
                    ->setEmailConfirm( $current_user->user_email );
            }
        } elseif ( get_option( 'bookly_cst_remember_in_cookie' ) ) {
            if ( isset( $_COOKIE['bookly-customer-full-name'] ) ) {
                $this->setFullName( Utils\Common::stripWpKses( $_COOKIE['bookly-customer-full-name'] ) );
            }
            Proxy\CustomerInformation::setFromCookies( $this );
            if ( isset( $_COOKIE['bookly-customer-birthday'] ) ) {
                $date = explode( '-', Utils\Common::stripWpKses( $_COOKIE['bookly-customer-birthday'] ) );
                $birthday = array(
                    'year' => $date[0],
                    'month' => isset( $date[1] ) ? (int) $date[1] : 0,
                    'day' => isset( $date[2] ) ? (int) $date[2] : 0,
                );
                $this->setBirthday( $birthday );
            }
            if ( isset( $_COOKIE['bookly-customer-email'] ) ) {
                $this
                    ->setEmail( Utils\Common::stripWpKses( $_COOKIE['bookly-customer-email'] ) )
                    ->setEmailConfirm( Utils\Common::stripWpKses( $_COOKIE['bookly-customer-email'] ) );
            }
            if ( isset( $_COOKIE['bookly-customer-phone'] ) ) {
                $this->setPhone( Utils\Common::stripWpKses( $_COOKIE['bookly-customer-phone'] ) );
            }
            if ( isset( $_COOKIE['bookly-customer-first-name'] ) ) {
                $this->setFirstName( Utils\Common::stripWpKses( $_COOKIE['bookly-customer-first-name'] ) );
            }
            if ( isset( $_COOKIE['bookly-customer-last-name'] ) ) {
                $this->setLastName( Utils\Common::stripWpKses( $_COOKIE['bookly-customer-last-name'] ) );
            }
            if ( isset( $_COOKIE['bookly-customer-country'] ) ) {
                $this->setCountry( Utils\Common::stripWpKses( $_COOKIE['bookly-customer-country'] ) );
            }
            if ( isset( $_COOKIE['bookly-customer-state'] ) ) {
                $this->setState( Utils\Common::stripWpKses( $_COOKIE['bookly-customer-state'] ) );
            }
            if ( isset( $_COOKIE['bookly-customer-postcode'] ) ) {
                $this->setPostcode( Utils\Common::stripWpKses( $_COOKIE['bookly-customer-postcode'] ) );
            }
            if ( isset( $_COOKIE['bookly-customer-city'] ) ) {
                $this->setCity( Utils\Common::stripWpKses( $_COOKIE['bookly-customer-city'] ) );
            }
            if ( isset( $_COOKIE['bookly-customer-street'] ) ) {
                $this->setStreet( Utils\Common::stripWpKses( $_COOKIE['bookly-customer-street'] ) );
            }
            if ( isset( $_COOKIE['bookly-customer-street-number'] ) ) {
                $this->setStreetNumber( Utils\Common::stripWpKses( $_COOKIE['bookly-customer-street-number'] ) );
            }
            if ( isset( $_COOKIE['bookly-customer-additional-address'] ) ) {
                $this->setAdditionalAddress( Utils\Common::stripWpKses( $_COOKIE['bookly-customer-additional-address'] ) );
            }
        }
    }

    public function resetChain()
    {
        $this->chain->clear();
        $this->chain->add( new ChainItem() );

        // Set up default parameters.
        $this
            ->setDateFrom( Slots\DatePoint::now()
                ->modify( Proxy\Pro::getMinimumTimePriorBooking( 0 ) )
                ->toClientTz()
                ->format( 'Y-m-d' )
            )
            ->setTimeFrom( null )
            ->setTimeTo( null )
            ->setSlots( array() )
            ->setEditCartKeys( array() )
            ->setRepeated( 0 )
            ->setRepeatData( array() );
    }

    /**
     * Save data to a session.
     */
    public function sessionSave()
    {
        $this->session['data'] = $this->getData();
        $this->session['cart'] = $this->cart->getItemsData();
        $this->session['chain'] = $this->chain->getItemsData();
        $this->session['payment_id'] = $this->payment_id;
        $this->session['payment_type'] = $this->payment_type;
        $this->session['verification_code'] = $this->verification_code ?: mt_rand( 100000, 999999 );
        $this->session['verification_code_recipient'] = $this->verification_code_recipient;
        $this->session['verification_code_sent_at'] = $this->verification_code_sent_at;
        $this->session['verification_resend_count'] = $this->verification_resend_count;
        $this->session['verification_verified_recipient'] = $this->verification_verified_recipient;
        $this->session['verification_attempt_count'] = $this->verification_attempt_count;
        $this->session['order_id'] = $this->order_id;

        FormSession::saveSession( $this->form_id, $this->session );
    }

    /**
     * @return array
     */
    public function getData()
    {
        $data = array();
        foreach ( $this->properties as $variable_name ) {
            $data[ $variable_name ] = $this->{$variable_name};
        }

        return $data;
    }

    /**
     * Load data from session.
     *
     * @return bool
     */
    public function load()
    {
        $this->session = FormSession::loadSession( $this->form_id );
        if ( $this->session ) {
            $data = isset( $this->session['data'] ) ? $this->session['data'] : null;
            if ( $data !== null ) {
                // Restore data.
                $this->fillData( $data );
                $this->chain->setItemsData( isset( $this->session['chain'] ) ? $this->session['chain'] : null );
                $this->cart->setItemsData( isset( $this->session['cart'] ) ? $this->session['cart'] : null );
                $this->payment_id = isset( $this->session['payment_id'] ) ? $this->session['payment_id'] : null;
                $this->payment_type = isset( $this->session['payment_type'] ) ? $this->session['payment_type'] : null;
                $this->verification_code = isset( $this->session['verification_code'] ) ? $this->session['verification_code'] : null;
                $this->verification_code_recipient = isset( $this->session['verification_code_recipient'] ) ? $this->session['verification_code_recipient'] : null;
                $this->verification_code_sent_at = isset( $this->session['verification_code_sent_at'] ) ? $this->session['verification_code_sent_at'] : 0;
                $this->verification_resend_count = isset( $this->session['verification_resend_count'] ) ? $this->session['verification_resend_count'] : 0;
                $this->verification_verified_recipient = isset( $this->session['verification_verified_recipient'] ) ? $this->session['verification_verified_recipient'] : null;
                $this->verification_attempt_count = isset( $this->session['verification_attempt_count'] ) ? $this->session['verification_attempt_count'] : 0;
                $this->order_id = isset( $this->session['order_id'] ) ? $this->session['order_id'] : null;
                $this->applyTimeZone();

                return true;
            }
        }

        return false;
    }

    /**
     * Partially update data in session.
     *
     * @param array $data
     */
    public function fillData( array $data )
    {
        foreach ( $data as $name => $value ) {
            if ( $name === 'tips' ) {
                $this->setTips( $value );
            } elseif ( in_array( $name, $this->properties ) ) {
                $this->{$name} = $value;
            } elseif ( $name == 'chain' ) {
                $chain_items = $this->chain->getItems();
                $this->chain->clear();
                foreach ( $value as $key => $_data ) {
                    $item = isset ( $chain_items[ $key ] ) ? $chain_items[ $key ] : new ChainItem();
                    $item->setData( $_data );
                    $this->chain->add( $item );
                }
            } elseif ( $name == 'cart' ) {
                $consider_extras_duration = Proxy\ServiceExtras::considerDuration( true );
                foreach ( $value as $key => $_data ) {
                    $this->cart->get( $key )
                        ->setData( $_data )
                        ->setConsiderExtrasDuration( $consider_extras_duration );
                }
            } elseif ( $name === 'repeat' ) {
                $this->setRepeated( $value );
            } elseif ( $name === 'unrepeat' ) {
                $this
                    ->setRepeated( 0 )
                    ->setRepeatData( array() );
            }
        }
    }

    /**
     * Set chain from given cart item.
     *
     * @param integer $cart_key
     */
    public function setChainFromCartItem( $cart_key )
    {
        $cart_item = $this->cart->get( $cart_key );
        $this
            ->setDateFrom( $cart_item->getDateFrom() )
            ->setDays( $cart_item->getDays() )
            ->setTimeFrom( $cart_item->getTimeFrom() )
            ->setTimeTo( $cart_item->getTimeTo() )
            ->setSlots( $cart_item->getSlots() )
            ->setRepeated( 0 )
            ->setRepeatData( array() );

        $chain_item = new ChainItem();
        $chain_item
            ->setServiceId( $cart_item->getServiceId() )
            ->setStaffIds( $cart_item->getStaffIds() )
            ->setNumberOfPersons( $cart_item->getNumberOfPersons() )
            ->setExtras( $cart_item->getExtras() )
            ->setSeriesUniqueId( $cart_item->getSeriesUniqueId() )
            ->setQuantity( 1 );

        $this->chain->clear();
        $this->chain->add( $chain_item );
    }

    /**
     * Add chain items to cart.
     *
     * @return $this
     */
    public function addChainToCart()
    {
        $cart_items = array();
        $edit_cart_keys = $this->getEditCartKeys();
        $eck_idx = 0;
        $slots = $this->getSlots();
        $slots_idx = 0;
        $repeated = $this->getRepeated() ?: 1;
        if ( $this->getRepeated() ) {
            $series_unique_id = mt_rand( 1, PHP_INT_MAX );
        } else {
            $series_unique_id = 0;
        }
        $consider_extras_duration = Proxy\ServiceExtras::considerDuration( true );
        $cart_items_repeats = array();
        for ( $i = 0; $i < $repeated; $i++ ) {
            $items_in_repeat = array();
            foreach ( $this->chain->getItems() as $chain_item ) {
                for ( $q = 0; $q < $chain_item->getQuantity(); ++$q ) {
                    $cart_item_slots = array();

                    if ( $chain_item->getService()->withSubServices() ) {
                        foreach ( $chain_item->getSubServices() as $sub_service ) {
                            $cart_item_slots[] = $slots[ $slots_idx++ ];
                        }
                    } else {
                        $cart_item_slots[] = $slots[ $slots_idx++ ];
                    }
                    $cart_item = new CartItem();

                    $cart_item
                        ->setDateFrom( $this->getDateFrom() )
                        ->setDays( $this->getDays() )
                        ->setTimeFrom( $this->getTimeFrom() )
                        ->setTimeTo( $this->getTimeTo() );

                    $cart_item
                        ->setSeriesUniqueId( $chain_item->getSeriesUniqueId() ?: $series_unique_id )
                        ->setExtras( $chain_item->getExtras() )
                        ->setConsiderExtrasDuration( $consider_extras_duration )
                        ->setLocationId( $chain_item->getLocationId() )
                        ->setNumberOfPersons( $chain_item->getNumberOfPersons() )
                        ->setUnits( $chain_item->getUnits() )
                        ->setServiceId( $chain_item->getServiceId() )
                        ->setSlots( $cart_item_slots )
                        ->setStaffIds( $chain_item->getStaffIds() )
                        ->setFirstInSeries( false );
                    if ( isset ( $edit_cart_keys[ $eck_idx ] ) ) {
                        $cart_item->setCustomFields( $this->cart->get( $edit_cart_keys[ $eck_idx ] )->getCustomFields() );
                        ++$eck_idx;
                    }

                    $items_in_repeat[] = $cart_item;
                }
            }
            $cart_items_repeats[] = $items_in_repeat;
        }

        /**
         * Searching for minimum time to find first client visiting
         */
        $first_visit_time = $slots[0][2];
        $first_visit_repeat = 0;
        foreach ( $cart_items_repeats as $repeat_id => $items_in_repeat ) {
            foreach ( $items_in_repeat as $cart_item ) {
                /** @var CartItem $cart_item */
                $slots = $cart_item->getSlots();
                foreach ( $slots as $slot ) {
                    if ( $slot[2] < $first_visit_time ) {
                        $first_visit_time = $slot[2];
                        $first_visit_repeat = $repeat_id;
                    }
                }
            }
        }

        foreach ( $cart_items_repeats[ $first_visit_repeat ] as $cart_item ) {
            /** @var CartItem $cart_item */
            $cart_item->setFirstInSeries( true );
        }

        foreach ( $cart_items_repeats as $items_in_repeat ) {
            $cart_items = array_merge( $cart_items, $items_in_repeat );
        }

        $count = count( $edit_cart_keys );
        $inserted_keys = array();

        if ( $count ) {
            $replace_key = array_shift( $edit_cart_keys );
            foreach ( $edit_cart_keys as $key ) {
                $this->cart->drop( $key );
            }
            $inserted_keys = $this->cart->replace( $replace_key, $cart_items );
        } else {
            foreach ( $cart_items as $cart_item ) {
                $inserted_keys[] = $this->cart->add( $cart_item );
            }
        }

        $this->setEditCartKeys( $inserted_keys );

        return $this;
    }

    /**
     * Validate fields.
     *
     * @param $data
     * @return array
     */
    public function validate( $data )
    {
        $validator = new Validator();
        foreach ( $data as $field_name => $field_value ) {
            switch ( $field_name ) {
                case 'service_id':
                    $validator->validateNumber( $field_name, $field_value );
                    break;
                case 'date_from':
                    $validator->validateDate( $field_name, $field_value, true );
                    break;
                case 'time_from':
                case 'time_to':
                    $validator->validateTime( $field_name, $field_value, false );
                    break;
                case 'full_name':
                case 'first_name':
                case 'last_name':
                    $validator->validateName( $field_name, $field_value );
                    break;
                case 'email':
                    $validator->validateEmail( $field_name, $data );
                    break;
                case 'email_confirm':
                    $validator->validateEmailConfirm( $field_name, $data );
                    break;
                case 'birthday':
                    $validator->validateBirthday( $field_name, $field_value );
                    break;
                case 'country':
                case 'state':
                case 'postcode':
                case 'city':
                case 'street':
                case 'street_number':
                case 'additional_address':
                    if ( array_key_exists( $field_name, Proxy\Pro::getDisplayedAddressFields() ) ) {
                        $validator->validateAddress( $field_name, $field_value, Config::addressRequired() );
                    }
                    break;
                case 'phone':
                    $validator->validatePhone( $field_name, $field_value, Config::phoneRequired() );
                    break;
                case 'info_fields':
                    $validator->validateInfoFields( $field_value );
                    break;
                case 'cart':
                    $validator->validateCart( $field_value, $data['form_id'] );
                    break;
                case 'chain':
                    $validator->validateChain( $field_name, $field_value );
                    break;
                case 'extras':
                    $validator->validateExtras( $field_name, $field_value, $this );
                    break;
                default:
            }
        }
        // Post validators run only for a request that submits contact details. Every other
        // step (service, time, cart) carries no customer data, and a returning visitor whose
        // remembered details differ from the record would otherwise get the duplicate data
        // error there, where the form has no place to show it.
        if ( ! isset ( $data['phone'] ) && ! isset ( $data['email'] ) ) {
            return $validator->getErrors();
        }
        // The customer a booking ends up on is resolved from the identifiers held by the
        // session, which may come from an earlier step or from the cookies, not only from the
        // fields submitted now. Identifiers missing from the submission are taken from the
        // session so that the check runs against the same record the booking will be saved
        // to, and an absent field is not read as a differing one.
        $identifiers = array( 'phone' => $this->getPhone(), 'email' => $this->getEmail() );
        $names = Config::showFirstLastName()
            ? array( 'first_name' => $this->getFirstName(), 'last_name' => $this->getLastName() )
            : array( 'full_name' => $this->getFullName() );
        foreach ( $identifiers + $names as $field => $session_value ) {
            if ( ! isset ( $data[ $field ] ) ) {
                $data[ $field ] = (string) $session_value;
            }
        }
        if ( ! isset ( $data['verification_code'] ) ) {
            $data['verification_code'] = '';
        }
        if ( $data['phone'] != '' || $data['email'] != '' ) {
            $validator->postValidateCustomer( $data, $this );
        }

        return $validator->getErrors();
    }

    /**
     * Save all data and create appointment.
     *
     * @param Entities\Payment $payment
     * @return DataHolders\Booking\Order
     */
    public function save( $payment = null )
    {
        // Customer.
        $customer = $this->getCustomer();

        // An existing record is matched by phone or email alone, which the booking pipeline
        // accepts from anyone. Its filled in personal data therefore stays untouched unless
        // the session proves it owns the record; empty fields are still filled in.
        $rewrite = ! $customer->isLoaded() || $this->customerIdentityConfirmed( $customer );

        // Overwrite only if value is not empty.
        if ( $this->getFacebookId() && ( $rewrite || ! $customer->getFacebookId() ) ) {
            $customer->setFacebookId( $this->getFacebookId() );
        }
        if ( $this->getFullName() != '' && ( $rewrite || $customer->getFullName() == '' ) ) {
            $customer->setFullName( $this->getFullName() );
        }
        if ( $this->getFirstName() != '' && ( $rewrite || $customer->getFirstName() == '' ) ) {
            $customer->setFirstName( $this->getFirstName() );
        }
        if ( $this->getLastName() != '' && ( $rewrite || $customer->getLastName() == '' ) ) {
            $customer->setLastName( $this->getLastName() );
        }
        if ( $this->getPhone() != '' && ( $rewrite || $customer->getPhone() == '' ) ) {
            $customer->setPhone( $this->getPhone() );
        }
        if ( $this->getEmail() != '' && ( $rewrite || $customer->getEmail() == '' ) ) {
            $customer->setEmail( trim( $this->getEmail() ) );
        }
        if ( $this->getBirthdayYmd() != '' && ( $rewrite || ! $customer->getBirthday() ) ) {
            $customer->setBirthday( $this->getBirthdayYmd() );
        }
        // Address is stored as one whole: a single changed part clears the rest, so a record
        // that already holds an address is left alone unless the session owns it.
        $address_empty = $customer->getCountry() == ''
            && $customer->getState() == ''
            && $customer->getPostcode() == ''
            && $customer->getCity() == ''
            && $customer->getStreet() == ''
            && $customer->getStreetNumber() == ''
            && $customer->getAdditionalAddress() == '';

        if ( $rewrite || $address_empty ) {
            // Set address fields.
            if ( $this->getCountry() !== $customer->getCountry() ||
                $this->getState() !== $customer->getState() ||
                $this->getPostcode() !== $customer->getPostcode() ||
                $this->getCity() !== $customer->getCity() ||
                $this->getStreet() !== $customer->getStreet() ||
                $this->getStreetNumber() !== $customer->getStreetNumber() ||
                $this->getAdditionalAddress() !== $customer->getAdditionalAddress()
            ) {
                // Clear all address fields if some field changed.
                $customer
                    ->setCountry( '' )
                    ->setState( '' )
                    ->setPostcode( '' )
                    ->setCity( '' )
                    ->setStreet( '' )
                    ->setStreetNumber( '' )
                    ->setAdditionalAddress( '' );
            }
            if ( $this->getCountry() != '' ) {
                $customer->setCountry( $this->getCountry() );
            }
            if ( $this->getState() != '' ) {
                $customer->setState( $this->getState() );
            }
            if ( $this->getPostcode() != '' ) {
                $customer->setPostcode( $this->getPostcode() );
            }
            if ( $this->getCity() != '' ) {
                $customer->setCity( $this->getCity() );
            }
            if ( $this->getStreet() != '' ) {
                $customer->setStreet( $this->getStreet() );
            }
            if ( $this->getStreetNumber() != '' ) {
                $customer->setStreetNumber( $this->getStreetNumber() );
            }
            if ( $this->getAdditionalAddress() != '' ) {
                $customer->setAdditionalAddress( $this->getAdditionalAddress() );
            }
            if ( $this->getFullAddress() !== '' ) {
                $customer->setFullAddress( $this->getFullAddress() );
            }
        }

        // Customer information fields.
        $info_fields = $customer->getInfoFields();
        if ( $rewrite || $info_fields === null || $info_fields === '' || $info_fields === '[]' ) {
            $customer->setInfoFields( json_encode( Proxy\CustomerInformation::prepareVisibleInfoFields( $this->getInfoFields(), $customer ) ) );
        }

        // A WP account must not be created for, or tied to, a record the session does not own:
        // the credentials would grant access to someone else's booking history.
        if ( $rewrite ) {
            Proxy\Pro::createWPUser( $customer );
        }

        $customer->save();
        Proxy\Files::attachCIFiles( $this->getInfoFields() ?: array(), $customer );

        // Order.
        $order = DataHolders\Booking\Order::create( $customer );

        // Payment.
        if ( $payment ) {
            $order->setPayment( $payment );
            $this->payment_id = $payment->getId();
            $this->setPaymentType( $payment->getType() );
            Proxy\Shared::saveUserBookingData( $this );
        }

        if ( get_option( 'bookly_cst_remember_in_cookie' ) ) {

            $expire = time() + YEAR_IN_SECONDS;
            $keys = array( 'full_name', 'first_name', 'last_name', 'phone', 'email', 'birthday', 'country', 'state', 'postcode', 'city', 'street', 'street_number', 'additional_address', );
            if ( $rewrite ) {
                $fields = $customer->getFields();
                $info_fields = $customer->getInfoFields();
            } else {
                // The record was matched by a phone or email anyone can type and was not
                // overwritten, so it still holds another person's data. Remembering the record
                // would hand that data to this browser; what was typed in is remembered instead.
                $fields = array(
                    'full_name' => $this->getFullName(),
                    'first_name' => $this->getFirstName(),
                    'last_name' => $this->getLastName(),
                    'phone' => $this->getPhone(),
                    'email' => trim( $this->getEmail() ),
                    'birthday' => $this->getBirthdayYmd(),
                    'country' => $this->getCountry(),
                    'state' => $this->getState(),
                    'postcode' => $this->getPostcode(),
                    'city' => $this->getCity(),
                    'street' => $this->getStreet(),
                    'street_number' => $this->getStreetNumber(),
                    'additional_address' => $this->getAdditionalAddress(),
                );
                // Only the fields shown in the form: hidden ones would be taken from the record.
                $info_fields = json_encode( $this->getInfoFields() ?: array() );
            }
            foreach ( $keys as $key ) {
                $fields[ $key ] != '' && setcookie( 'bookly-customer-' . str_replace( '_', '-', $key ), $fields[ $key ], $expire, '/' );
            }
            if ( Config::customerInformationActive() ) {
                setcookie( 'bookly-customer-info-fields', $info_fields, $expire, '/' );
            }
        }

        return $this->cart->save( $order, $this->getTimeZone(), $this->getTimeZoneOffset() );
    }

    /**
     * Get form ID.
     *
     * @return string
     */
    public function getFormId()
    {
        return $this->form_id;
    }

    /**
     * Get array with address iso codes.
     *
     * @return array
     */
    public function getAddressIso()
    {
        return $this->address_iso;
    }

    public function setCustomer( Entities\Customer $customer )
    {
        $this->customer = $customer;

        return $this;
    }

    /**
     * Get customer.
     *
     * @return Entities\Customer
     */
    public function getCustomer()
    {
        if ( $this->customer === null ) {
            // Find or create customer.
            $this->customer = new Entities\Customer();
            if ( $this->wp_user_id > 0 ) {
                // Try to find customer by WP user ID.
                $this->customer->loadBy( array( 'wp_user_id' => $this->wp_user_id ) );
            }
            if ( ! $this->customer->isLoaded() ) {
                $customer = BookingProxy\Pro::getCustomerByFacebookId( $this->getFacebookId() );
                if ( $customer ) {
                    $this->customer = $customer;
                }
                if ( ! $this->customer->isLoaded() ) {
                    // Check allow duplicates option
                    if ( Config::allowDuplicates() ) {
                        $customer_data = array(
                            'email' => $this->getEmail(),
                            'phone' => $this->getPhone(),
                        );
                        if ( Config::showFirstLastName() ) {
                            $customer_data['first_name'] = $this->getFirstName();
                            $customer_data['last_name'] = $this->getLastName();
                        } else {
                            $customer_data['full_name'] = $this->getFullName();
                        }
                        if ( $this->getEmail() != '' ) {
                            $customer_data['email'] = $this->getEmail();
                        }
                        if ( $this->getPhone() != '' ) {
                            $customer_data['phone'] = $this->getPhone();
                        }
                        $this->customer->loadBy( $customer_data );
                    } else {
                        // Try to find customer by phone or email.
                        $params = Config::phoneRequired()
                            ? ( $this->getPhone() !== null && $this->getPhone() !== '' ? array( 'phone' => $this->getPhone() ) : array() )
                            : ( $this->getEmail() !== null && $this->getEmail() !== '' ? array( 'email' => $this->getEmail() ) : array() );
                        if ( ! empty ( $params ) && ! $this->customer->loadBy( $params ) ) {
                            $params = Config::phoneRequired()
                                ? ( $this->getEmail() !== null && $this->getEmail() !== '' ? array( 'email' => $this->getEmail(), 'phone' => '' ) : array() )
                                : ( $this->getPhone() !== null && $this->getPhone() !== '' ? array( 'phone' => $this->getPhone(), 'email' => '' ) : array() );
                            if ( ! empty( $params ) ) {
                                // Try to find customer by 'secondary' identifier, otherwise return new customer.
                                $this->customer->loadBy( $params );
                            }
                        }
                    }
                }
            }
        }

        return $this->customer;
    }

    /**
     * @param array $customer_data
     * @param Collection $appearance
     * @return $this
     */
    public function setModernFormCustomer( $customer_data, Collection $appearance )
    {
        if ( $this->customer === null ) {
            // Find or create customer.
            $this->customer = new Entities\Customer();
            $customer_data['phone'] = isset( $customer_data['phone_formatted'] ) ? $customer_data['phone_formatted'] : $customer_data['phone'];
            $customer_fields = array( 'email', 'phone' );
            $show = $appearance->get( 'details_fields_show' );
            foreach ( array( 'first_name', 'last_name', 'full_name' ) as $field ) {
                if ( in_array( $field, $show ) ) {
                    $customer_fields[] = $field;
                }
            }
            $search_criteria = array();
            if ( Config::allowDuplicates() ) {
                $criteria = array();
                foreach ( $customer_fields as $field ) {
                    if ( isset( $customer_data[ $field ] ) && $customer_data[ $field ] !== '' ) {
                        $criteria[ $field ] = $customer_data[ $field ];
                    }
                }
                $search_criteria[] = $criteria;
            } else {
                if ( get_current_user_id() > 0 ) {
                    $search_criteria = array(
                        array(
                            'wp_user_id' => get_current_user_id(),
                        ),
                    );
                }

                $search_criteria[] = array(
                    'phone' => $customer_data['phone'],
                    'email' => $customer_data['email'],
                    'wp_user_id' => null,
                );

                $verify_credentials = $appearance->get( 'verify_credentials' );
                if ( $verify_credentials === 'phone' || $verify_credentials === 'email' ) {
                    $search_criteria[] = array_filter( array(
                        $verify_credentials => $customer_data[ $verify_credentials ],
                        'wp_user_id' => null,
                    ) );
                } else {
                    $search_criteria[] = array_filter( array(
                        'phone' => $customer_data['phone'],
                        'wp_user_id' => null,
                    ) );
                    $search_criteria[] = array_filter( array(
                        'email' => $customer_data['email'],
                        'wp_user_id' => null,
                    ) );
                }
            }
            $search_criteria = array_filter( $search_criteria );

            foreach ( $search_criteria as $criteria ) {
                foreach ( $criteria as $field ) {
                    if ( $field === '' ) {
                        continue 2;
                    }
                }
                if ( $this->customer->loadBy( $criteria ) ) {
                    break;
                }
            }
            if ( $this->customer->isLoaded() && Config::allowDuplicates() ) {
                $fields = $this->customer->getFields();
                foreach ( $customer_fields as $field ) {
                    if ( $fields[ $field ] != $customer_data[ $field ] ) {
                        $this->customer = new Entities\Customer();
                        break;
                    }
                }
            }
            foreach ( array( 'email', 'phone', 'first_name', 'last_name', 'full_name' ) as $field ) {
                if ( ! in_array( $field, $customer_fields ) ) {
                    $this->fillData( array( $field => '' ) );
                }
            }
            // A found record keeps its details: save() decides which of the entered ones may replace them.
            foreach ( $customer_fields as $field ) {
                $this->fillData( array( $field => $customer_data[ $field ] ?: '' ) );
                if ( ! $this->customer->isLoaded() ) {
                    $this->customer->setFields( array( $field => $customer_data[ $field ] ?: '' ) );
                }
            }
            if ( isset( $customer_data['customer_information'] ) && Config::customerInformationActive() ) {
                $customer_information = array();
                foreach ( $customer_data['customer_information'] as $id => $value ) {
                    $customer_information[] = array( 'id' => $id, 'value' => $value );
                }
                $this->setInfoFields( $customer_information );
            }
            if ( isset( $customer_data['time_zone'] ) && $customer_data['time_zone'] !== '' ) {
                $time_zone = $customer_data['time_zone'];

                $this->setTimeZone( $time_zone );

                if ( preg_match( '/^UTC[+-]/', $time_zone ) ) {
                    $offset = preg_replace( '/UTC\+?/', '', $time_zone );
                    $time_zone = null;
                    $time_zone_offset = -$offset * 60;
                } else {
                    $time_zone_offset = -timezone_offset_get( timezone_open( $time_zone ), new \DateTime() ) / 60;
                }
                $this->setTimeZoneOffset( $time_zone_offset );
            } else if ( isset( $customer_data['time_zone_offset'] ) && $customer_data['time_zone_offset'] !== '' ) {
                $this->setTimeZoneOffset( $customer_data['time_zone_offset'] );
            }
            if ( isset( $customer_data['full_address'] ) && $customer_data['full_address'] !== '' ) {
                $this->setFullAddress( $customer_data['full_address'] );
            }
        }

        return $this;
    }

    /**
     * Get coupon.
     *
     * @return \BooklyCoupons\Lib\Entities\Coupon|false
     */
    public function getCoupon()
    {
        if ( $this->coupon === null ) {
            $coupon = BookingProxy\Coupons::findOneByCode( $this->getCouponCode() );
            if ( $coupon ) {
                $this->coupon = $coupon;
            } else {
                $this->coupon = false;
            }
        }

        return $this->coupon;
    }

    /**
     * Get gift card.
     *
     * @return \BooklyGiftCards\Lib\Entities\GiftCard|false
     */
    public function getGiftCard()
    {
        if ( $this->gift_card === null ) {
            $this->gift_card = BookingProxy\GiftCards::findOneGiftCardByCode( $this->getGiftCode() ) ?: false;
        }

        return $this->gift_card;
    }

    /**
     * Delete coupon.
     *
     * @return $this
     */
    public function deleteCoupon()
    {
        $this->coupon = null;
        $this->coupon_code = null;

        return $this;
    }

    /**
     * Set payment ( PayPal, 2Checkout, PayU Latam, Mollie ) transaction status.
     *
     * @param string $status
     * @return $this
     */
    public function setPaymentStatus( $status )
    {
        $this->session['payment'] = compact( 'status' );

        return $this;
    }

    /**
     * Get and clear ( PayPal, 2Checkout, PayU Latam, Payson, ... ) transaction status.
     *
     * @return array|false
     */
    public function extractPaymentStatus()
    {
        $status = isset( $this->session['payment'] ) ? $this->session['payment'] : null;

        if ( isset( $status['status'] ) ) {
            unset( $this->session['payment'] );

            return $status;
        }

        return false;
    }

    /**
     * Get payment ID.
     *
     * @return int|null
     */
    public function getPaymentId()
    {
        return $this->payment_id;
    }

    /**
     * Apply client time zone.
     *
     * @return $this
     */
    public function applyTimeZone()
    {
        if ( $this->getTimeZone() !== null && date_create( $this->getTimeZone() ) !== false ) {
            Slots\DatePoint::$client_timezone = $this->getTimeZone();
            Slots\TimePoint::$client_timezone_offset = Utils\DateTime::timeZoneOffset( Slots\DatePoint::$client_timezone );
        } elseif ( $this->getTimeZoneOffset() !== null ) {
            Slots\TimePoint::$client_timezone_offset = -$this->getTimeZoneOffset() * MINUTE_IN_SECONDS;
            $timezone = Utils\DateTime::formatOffset( Slots\TimePoint::$client_timezone_offset );
            Slots\DatePoint::$client_timezone = date_create( $timezone ) === false ? null : $timezone;
        }

        return $this;
    }

    /**************************************************************************
     * UserData Getters & Setters                                             *
     **************************************************************************/

    /**
     * Gets first rendered step
     *
     * @return int
     */
    public function getFirstStep()
    {
        return $this->first_rendered_step;
    }

    /**
     * Sets first rendered step
     *
     * @param int $first_step
     * @return $this
     */
    public function setFirstStep( $first_step )
    {
        $this->first_rendered_step = $first_step;

        return $this;
    }

    /**
     * Gets time_zone
     *
     * @return string
     */
    public function getTimeZone()
    {
        return $this->time_zone;
    }

    /**
     * Sets time_zone
     *
     * @param string $time_zone
     * @return $this
     */
    public function setTimeZone( $time_zone )
    {
        $this->time_zone = $time_zone;

        return $this;
    }

    /**
     * Gets time_zone_offset
     *
     * @return int
     */
    public function getTimeZoneOffset()
    {
        return $this->time_zone_offset;
    }

    /**
     * Sets time_zone_offset
     *
     * @param int $time_zone_offset
     * @return $this
     */
    public function setTimeZoneOffset( $time_zone_offset )
    {
        $this->time_zone_offset = $time_zone_offset;

        return $this;
    }

    /**
     * Gets date_from
     *
     * @return string
     */
    public function getDateFrom()
    {
        return $this->date_from;
    }

    /**
     * Sets date_from
     *
     * @param string $date_from
     * @return $this
     */
    public function setDateFrom( $date_from )
    {
        $this->date_from = $date_from;

        return $this;
    }

    /**
     * Gets days
     *
     * @return array
     */
    public function getDays()
    {
        return $this->days;
    }

    /**
     * Sets days
     *
     * @param array $days
     * @return $this
     */
    public function setDays( $days )
    {
        $this->days = $days;

        return $this;
    }

    /**
     * Gets time_from
     *
     * @return string|null
     */
    public function getTimeFrom()
    {
        return $this->time_from;
    }

    /**
     * Sets time_from
     *
     * @param string|null $time_from
     * @return $this
     */
    public function setTimeFrom( $time_from )
    {
        $this->time_from = $time_from;

        return $this;
    }

    /**
     * Gets time_to
     *
     * @return string|null
     */
    public function getTimeTo()
    {
        return $this->time_to;
    }

    /**
     * Sets time_to
     *
     * @param string|null $time_to
     * @return $this
     */
    public function setTimeTo( $time_to )
    {
        $this->time_to = $time_to;

        return $this;
    }

    /**
     * Gets slots
     *
     * @return array
     */
    public function getSlots()
    {
        return $this->slots;
    }

    /**
     * Sets slots
     *
     * @param array $slots
     * @return $this
     */
    public function setSlots( $slots )
    {
        $this->slots = $slots;

        return $this;
    }

    /**
     * Gets facebook_id
     *
     * @return int
     */
    public function getFacebookId()
    {
        return $this->facebook_id;
    }

    /**
     * Sets facebook_id
     *
     * @param int $facebook_id
     * @return $this
     */
    public function setFacebookId( $facebook_id )
    {
        $this->facebook_id = $facebook_id;

        return $this;
    }

    /**
     * Gets full_name
     *
     * @return string
     */
    public function getFullName()
    {
        return $this->full_name;
    }

    /**
     * Sets full_name
     *
     * @param string $full_name
     * @return $this
     */
    public function setFullName( $full_name )
    {
        $this->full_name = $full_name;

        return $this;
    }

    /**
     * Gets first_name
     *
     * @return string
     */
    public function getFirstName()
    {
        return $this->first_name;
    }

    /**
     * Sets first_name
     *
     * @param string $first_name
     * @return $this
     */
    public function setFirstName( $first_name )
    {
        $this->first_name = $first_name;

        return $this;
    }

    /**
     * Gets last_name
     *
     * @return string
     */
    public function getLastName()
    {
        return $this->last_name;
    }

    /**
     * Sets last_name
     *
     * @param string $last_name
     * @return $this
     */
    public function setLastName( $last_name )
    {
        $this->last_name = $last_name;

        return $this;
    }

    /**
     * Gets email
     *
     * @return string
     */
    public function getEmail()
    {
        return $this->email;
    }

    /**
     * Sets email
     *
     * @param string $email
     * @return $this
     */
    public function setEmail( $email )
    {
        $this->email = $email;

        return $this;
    }

    /**
     * Gets email_confirm
     *
     * @return string
     */
    public function getEmailConfirm()
    {
        return $this->email_confirm;
    }

    /**
     * Sets email_confirm
     *
     * @param string $email_confirm
     * @return $this
     */
    public function setEmailConfirm( $email_confirm )
    {
        $this->email_confirm = $email_confirm;

        return $this;
    }

    /**
     * @return string
     */
    public function getCountry()
    {
        return $this->country;
    }

    /**
     * @param string $country
     * @return $this
     */
    public function setCountry( $country )
    {
        $this->country = $country;

        return $this;
    }

    /**
     * @return string
     */
    public function getState()
    {
        return $this->state;
    }

    /**
     * @param string $state
     * @return $this
     */
    public function setState( $state )
    {
        $this->state = $state;

        return $this;
    }

    /**
     * @return string
     */
    public function getPostcode()
    {
        return $this->postcode;
    }

    /**
     * @param string $postcode
     * @return $this
     */
    public function setPostcode( $postcode )
    {
        $this->postcode = $postcode;

        return $this;
    }

    /**
     * @return string
     */
    public function getCity()
    {
        return $this->city;
    }

    /**
     * @param string $city
     * @return $this
     */
    public function setCity( $city )
    {
        $this->city = $city;

        return $this;
    }

    /**
     * @return string
     */
    public function getStreet()
    {
        return $this->street;
    }

    /**
     * @param string $street
     * @return $this
     */
    public function setStreet( $street )
    {
        $this->street = $street;

        return $this;
    }

    /**
     * @return string
     */
    public function getStreetNumber()
    {
        return $this->street_number;
    }

    /**
     * @param string $street_number
     * @return $this
     */
    public function setStreetNumber( $street_number )
    {
        $this->street_number = $street_number;

        return $this;
    }

    /**
     * @return string
     */
    public function getAdditionalAddress()
    {
        return $this->additional_address;
    }

    /**
     * @param string $additional_address
     * @return $this
     */
    public function setAdditionalAddress( $additional_address )
    {
        $this->additional_address = $additional_address;

        return $this;
    }

    /**
     * @return string
     */
    public function getFullAddress()
    {
        return $this->full_address;
    }

    /**
     * @param string $full_address
     */
    public function setFullAddress( $full_address )
    {
        $this->full_address = $full_address;

        return $this;
    }

    /**
     * Gets phone
     *
     * @return string
     */
    public function getPhone()
    {
        return $this->phone;
    }

    /**
     * Sets phone
     *
     * @param string $phone
     * @return $this
     */
    public function setPhone( $phone )
    {
        $this->phone = $phone;

        return $this;
    }

    /**
     * @param string $field_name
     * @return string
     */
    public function getAddressField( $field_name )
    {
        switch ( $field_name ) {
            case 'additional_address':
                return $this->additional_address;
            case 'country':
                return $this->country;
            case 'state':
                return $this->state;
            case 'postcode':
                return $this->postcode;
            case 'city':
                return $this->city;
            case 'street':
                return $this->street;
            case 'street_number':
                return $this->street_number;
        }

        return '';
    }

    /**
     * Gets info_fields
     *
     * @return array
     */
    public function getInfoFields()
    {
        return $this->info_fields;
    }

    /**
     * Sets info_fields
     *
     * @param array $info_fields
     * @return $this
     */
    public function setInfoFields( $info_fields )
    {
        $this->info_fields = $info_fields;

        return $this;
    }

    /**
     * Gets notes
     *
     * @return string
     */
    public function getNotes()
    {
        return $this->notes;
    }

    /**
     * Sets notes
     *
     * @param string $notes
     * @return $this
     */
    public function setNotes( $notes )
    {
        $this->notes = $notes;

        return $this;
    }

    /**
     * Gets birthday.
     *
     * @return array
     */
    public function getBirthday()
    {
        return $this->birthday;
    }

    /**
     * Gets birthday.
     *
     * @return string
     */
    public function getBirthdayYmd()
    {
        $date = '';
        if ( is_array( $this->birthday ) ) {
            $date = sprintf( '%04d-%02d-%02d', $this->birthday['year'], $this->birthday['month'], $this->birthday['day'] );
        } else if ( is_string( $this->birthday ) ) {
            $date = $this->birthday;
        }

        return Utils\DateTime::validateDate( $date ) ? $date : '';
    }

    /**
     * @param array $birthday
     * @return $this
     */
    public function setBirthday( $birthday )
    {
        $this->birthday = $birthday;

        return $this;
    }

    /**
     * Gets coupon_code
     *
     * @return string
     */
    public function getCouponCode()
    {
        return $this->coupon_code;
    }

    /**
     * Sets coupon_code
     *
     * @param string $coupon_code
     * @return $this
     */
    public function setCouponCode( $coupon_code )
    {
        $this->coupon_code = $coupon_code;

        return $this;
    }

    /**
     * Gets gift_code
     *
     * @return string
     */
    public function getGiftCode()
    {
        return $this->gift_code;
    }

    /**
     * Sets gift_code
     *
     * @param string $gift_code
     * @return $this
     */
    public function setGiftCode( $gift_code )
    {
        $this->gift_code = $gift_code;

        return $this;
    }

    /**
     * Gets gift_card_amount
     *
     * @return string
     */
    public function getGiftCardAmount()
    {
        return $this->gift_card_amount;
    }

    /**
     * Sets gift_card_amount
     *
     * @param string $gift_card_amount
     * @return $this
     */
    public function setGiftCardAmount( $gift_card_amount )
    {
        $this->gift_card_amount = $gift_card_amount;

        return $this;
    }

    /**
     * Gets deposit_full
     *
     * @return string
     */
    public function getDepositFull()
    {
        return $this->deposit_full;
    }

    /**
     * Sets deposit_full
     *
     * @param string $deposit_full
     * @return $this
     */
    public function setDepositFull( $deposit_full )
    {
        $this->deposit_full = $deposit_full;

        return $this;
    }

    /**
     * Gets edit_cart_keys
     *
     * @return array
     */
    public function getEditCartKeys()
    {
        return $this->edit_cart_keys;
    }

    /**
     * Sets edit_cart_keys
     *
     * @param array $edit_cart_keys
     * @return $this
     */
    public function setEditCartKeys( $edit_cart_keys )
    {
        $this->edit_cart_keys = $edit_cart_keys;

        return $this;
    }

    /**
     * Gets repeated
     *
     * @return bool
     */
    public function getRepeated()
    {
        return $this->repeated;
    }

    /**
     * Sets repeated
     *
     * @param bool $repeated
     * @return $this
     */
    public function setRepeated( $repeated )
    {
        $this->repeated = $repeated;

        return $this;
    }

    /**
     * Gets repeat_data
     *
     * @return array
     */
    public function getRepeatData()
    {
        return $this->repeat_data;
    }

    /**
     * Sets repeat_data
     *
     * @param array $repeat_data
     * @return $this
     */
    public function setRepeatData( $repeat_data )
    {
        $this->repeat_data = $repeat_data;

        return $this;
    }

    /**
     * Gets payment_type
     *
     * @return string
     */
    public function getPaymentType()
    {
        return $this->payment_type;
    }

    /**
     * Sets payment_type
     *
     * @param string $payment_type
     * @return $this
     */
    public function setPaymentType( $payment_type )
    {
        $this->payment_type = $payment_type;

        return $this;
    }

    /**
     * @return string
     */
    public function getVerificationCode()
    {
        return $this->verification_code;
    }

    /**
     * @param bool|string $value
     * @return $this
     */
    public function setVerificationCode( $value )
    {
        $this->verification_code = $value;

        return $this;
    }

    /**
     * @param string|null $value
     * @return $this
     */
    public function setVerificationCodeRecipient( $value )
    {
        $this->verification_code_recipient = $value;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getVerificationCodeRecipient()
    {
        return $this->verification_code_recipient;
    }

    /**
     * @return int
     */
    public function getVerificationCodeSentAt()
    {
        return (int) $this->verification_code_sent_at;
    }

    /**
     * @param int $value
     * @return $this
     */
    public function setVerificationCodeSentAt( $value )
    {
        $this->verification_code_sent_at = (int) $value;

        return $this;
    }

    /**
     * @return int
     */
    public function getVerificationResendCount()
    {
        return (int) $this->verification_resend_count;
    }

    /**
     * @param int $value
     * @return $this
     */
    public function setVerificationResendCount( $value )
    {
        $this->verification_resend_count = (int) $value;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getVerifiedRecipient()
    {
        return $this->verification_verified_recipient;
    }

    /**
     * @param string|null $value
     * @return $this
     */
    public function setVerifiedRecipient( $value )
    {
        $this->verification_verified_recipient = $value;

        return $this;
    }

    /**
     * @return int
     */
    public function getVerificationAttemptCount()
    {
        return (int) $this->verification_attempt_count;
    }

    /**
     * @param int $value
     * @return $this
     */
    public function setVerificationAttemptCount( $value )
    {
        $this->verification_attempt_count = (int) $value;

        return $this;
    }

    /**
     * Whether the booking session has proven control over the given existing customer.
     *
     * A booking may be attached to a customer matched by phone or email, but the personal
     * data of an already existing record must not be rewritten unless the session proves
     * it owns that record: either the record belongs to the logged in WP user, or the
     * customer's phone/email was confirmed with a verification code in this session.
     *
     * @param Entities\Customer $customer
     * @return bool
     */
    public function customerIdentityConfirmed( Entities\Customer $customer )
    {
        if ( $this->wp_user_id > 0 && (int) $customer->getWpUserId() === (int) $this->wp_user_id ) {
            return true;
        }

        $verified = $this->getVerifiedRecipient();
        if ( $verified !== null && $verified !== '' ) {
            $phone = Cloud\SMS::normalizePhoneNumber( (string) $customer->getPhone() );
            $email = strtolower( trim( (string) $customer->getEmail() ) );
            if ( ( $phone !== '' && $verified === $phone ) || ( $email !== '' && $verified === $email ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gets order_id
     *
     * @return string
     */
    public function getOrderId()
    {
        return $this->order_id;
    }

    /**
     * Sets order_id
     *
     * @param string $order_id
     * @return $this
     */
    public function setOrderId( $order_id )
    {
        $this->order_id = $order_id;

        return $this;
    }

    /**
     * Gets tips
     *
     * @return float|null
     */
    public function getTips()
    {
        return $this->tips;
    }

    /**
     * Sets tips
     *
     * @param float $tips
     * @return $this
     */
    public function setTips( $tips )
    {
        $this->tips = max( 0, (float) $tips );

        return $this;
    }
}