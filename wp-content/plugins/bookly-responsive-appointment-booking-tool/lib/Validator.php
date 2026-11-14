<?php
namespace Bookly\Lib;

use Bookly\Lib\Notifications\Verification\Sender;
use Bookly\Frontend\Modules\Booking\Proxy as BookingProxy;

class Validator
{
    /** Consecutive wrong verification code entries after which the stored code is discarded. */
    const MAX_VERIFICATION_ATTEMPTS = 5;

    private $errors = array();

    /**
     * Validate email.
     *
     * @param string $field
     * @param array $data
     */
    public function validateEmail( $field, $data )
    {
        if ( $data['email'] == '' && ( Config::emailRequired() || get_option( 'bookly_cst_create_account', 0 ) ) ) {
            $this->errors[ $field ] = Utils\Common::getTranslatedOption( 'bookly_l10n_required_email' );
        } else {
            if ( $data['email'] != '' && ! is_email( trim( $data['email'] ) ) ) {
                $this->errors[ $field ] = __( 'Invalid email', 'bookly-responsive-appointment-booking-tool' );
            }
            // Check email for uniqueness when a new WP account is going to be created.
            if ( get_option( 'bookly_cst_create_account', 0 ) && ! get_current_user_id() ) {
                $customer = new Entities\Customer();
                // Try to find customer by phone or email.
                $customer->loadBy(
                    Config::phoneRequired()
                        ? array( 'phone' => $data['phone'] )
                        : array( 'email' => $data['email'] )
                );
                if ( ( ! $customer->isLoaded() || ! $customer->getWpUserId() ) && email_exists( $data['email'] ) ) {
                    $this->errors[ $field ] = Utils\Common::getTranslatedOption( 'bookly_l10n_email_in_use' );
                }
            }
        }
    }

    /**
     * Validate email confirm.
     *
     * @param string $field
     * @param array $data
     */
    public function validateEmailConfirm( $field, $data )
    {
        if ( Config::showEmailConfirm() && $data['email'] != $data['email_confirm'] ) {
            $this->errors[ $field ] = Utils\Common::getTranslatedOption( 'bookly_l10n_email_confirm_not_match' );
        }
    }

    public function validateBirthday( $field_name, array $data )
    {
        $required = get_option( 'bookly_cst_required_birthday' );

        // Day
        $day = (int) $data['day'];
        $month = (int) $data['month'];
        $year = (int) $data['year'];

        $last_day = (int) date( 't', strtotime( $year . '-' . $month . '-01' ) );

        if ( $day < 1 ) {
            if ( $required ) {
                $this->errors[ $field_name . '_day' ] = Utils\Common::getTranslatedOption( 'bookly_l10n_required_day' );
            }
        } elseif ( $day > $last_day ) {
            $this->errors[ $field_name . '_day' ] = Utils\Common::getTranslatedOption( 'bookly_l10n_invalid_day' );
        }

        // Month
        if ( $required && ( $month < 1 || $month > 12 ) ) {
            $this->errors[ $field_name . '_month' ] = Utils\Common::getTranslatedOption( 'bookly_l10n_required_month' );
        }

        // Year
        $max_year = (int) Slots\DatePoint::now()->format( 'Y' );
        $min_year = $max_year - 100;

        if ( $required && ( $year < $min_year || $year > $max_year ) ) {
            $this->errors[ $field_name . '_year' ] = Utils\Common::getTranslatedOption( 'bookly_l10n_required_year' );
        }
    }

    /**
     * @param string $field_name
     * @param string $value
     * @param bool $required
     */
    public function validateAddress( $field_name, $value, $required = false )
    {
        $value = $value === null ? '' : trim( $value );
        if ( empty( $value ) && $required ) {
            $this->errors[ $field_name ] = Utils\Common::getTranslatedOption( 'bookly_l10n_required_' . $field_name );
        }
    }

    /**
     * Validate phone.
     *
     * @param string $field
     * @param string $phone
     * @param bool $required
     */
    public function validatePhone( $field, $phone, $required = false )
    {
        if ( $phone == '' && $required ) {
            $this->errors[ $field ] = Utils\Common::getTranslatedOption( 'bookly_l10n_required_phone' );
        }
    }

    /**
     * Validate name.
     *
     * @param string $field
     * @param string $name
     */
    public function validateName( $field, $name )
    {
        if ( $name != '' ) {
            $max_length = 255;
            if ( preg_match_all( '/./su', $name, $matches ) > $max_length ) {
                $this->errors[ $field ] = sprintf(
                    __( '"%s" is too long (%d characters max).', 'bookly-responsive-appointment-booking-tool' ),
                    $name,
                    $max_length
                );
            }
        } else {
            switch ( $field ) {
                case 'full_name' :
                    $this->errors[ $field ] = Utils\Common::getTranslatedOption( 'bookly_l10n_required_name' );
                    break;
                case 'first_name' :
                    $this->errors[ $field ] = Utils\Common::getTranslatedOption( 'bookly_l10n_required_first_name' );
                    break;
                case 'last_name' :
                    $this->errors[ $field ] = Utils\Common::getTranslatedOption( 'bookly_l10n_required_last_name' );
                    break;
            }
        }
    }

    /**
     * Validate number.
     *
     * @param string $field
     * @param mixed $number
     * @param bool $required
     */
    public function validateNumber( $field, $number, $required = false )
    {
        if ( $number != '' ) {
            if ( ! is_numeric( $number ) ) {
                $this->errors[ $field ] = __( 'Invalid number', 'bookly-responsive-appointment-booking-tool' );
            }
        } elseif ( $required ) {
            $this->errors[ $field ] = __( 'Required', 'bookly-responsive-appointment-booking-tool' );
        }
    }

    /**
     * Validate date.
     *
     * @param string $field
     * @param string $date
     * @param bool $required
     */
    public function validateDate( $field, $date, $required = false )
    {
        if ( $date != '' ) {
            if ( date_create( $date ) === false ) {
                $this->errors[ $field ] = __( 'Invalid date', 'bookly-responsive-appointment-booking-tool' );
            }
        } elseif ( $required ) {
            $this->errors[ $field ] = __( 'Required', 'bookly-responsive-appointment-booking-tool' );
        }
    }

    /**
     * Validate time.
     *
     * @param string $field
     * @param string $time
     * @param bool $required
     */
    public function validateTime( $field, $time, $required = false )
    {
        if ( $time != '' ) {
            if ( ! preg_match( '/^-?\d{2}:\d{2}$/', $time ) ) {
                $this->errors[ $field ] = __( 'Invalid time', 'bookly-responsive-appointment-booking-tool' );
            }
        } elseif ( $required ) {
            $this->errors[ $field ] = __( 'Required', 'bookly-responsive-appointment-booking-tool' );
        }
    }

    /**
     * Post-validate customer.
     *
     * @param array $data
     * @param UserBookingData $userData
     */
    public function postValidateCustomer( $data, UserBookingData $userData )
    {
        if ( empty ( $this->errors ) ) {
            $user_id = get_current_user_id();
            $customer = new Entities\Customer();
            if ( $user_id > 0 ) {
                // Try to find customer by WP user ID.
                $customer->loadBy( array( 'wp_user_id' => $user_id ) );
            }
            $verify_customer_details = get_option( 'bookly_cst_verify_customer_details', false );
            if ( ! $customer->isLoaded() ) {
                $entity = BookingProxy\Pro::getCustomerByFacebookId( $userData->getFacebookId() );
                if ( $entity ) {
                    $customer = $entity;
                }
                if ( ! $customer->isLoaded() ) {
                    // Try to find customer by 'primary' identifier.
                    $identifier = Config::phoneRequired() ? 'phone' : 'email';
                    if ( $data[ $identifier ] !== '' ) {
                        $customer->loadBy( array( $identifier => $data[ $identifier ] ) );
                    }
                    if ( ! $customer->isLoaded() ) {
                        // Try to find customer by 'secondary' identifier.
                        $identifier = Config::phoneRequired() ? 'email' : 'phone';
                        if ( $data[ $identifier ] !== '' ) {
                            $customer->loadBy( array( 'phone' => '', 'email' => '', $identifier => $data[ $identifier ] ) );
                        }
                    }
                    if ( Config::allowDuplicates() ) {
                        if ( Config::showFirstLastName() ) {
                            $customer_data = array(
                                'first_name' => $data['first_name'],
                                'last_name' => $data['last_name'],
                            );
                        } else {
                            $customer_data = array( 'full_name' => $data['full_name'] );
                        }
                        if ( $data['email'] != '' ) {
                            $customer_data['email'] = $data['email'];
                        }
                        if ( $data['phone'] != '' ) {
                            $customer_data['phone'] = $data['phone'];
                        }
                        $customer->loadBy( $customer_data );
                    } elseif ( $customer->isLoaded() ) {
                        // Find difference between new and existing data.
                        $diff = array();
                        $fields = array(
                            'phone' => Utils\Common::getTranslatedOption( 'bookly_l10n_label_phone' ),
                            'email' => Utils\Common::getTranslatedOption( 'bookly_l10n_label_email' ),
                        );
                        $current = $customer->getFields();
                        if ( Config::showFirstLastName() ) {
                            $fields['first_name'] = Utils\Common::getTranslatedOption( 'bookly_l10n_label_first_name' );
                            $fields['last_name'] = Utils\Common::getTranslatedOption( 'bookly_l10n_label_last_name' );
                        } else {
                            $fields['full_name'] = Utils\Common::getTranslatedOption( 'bookly_l10n_label_name' );
                        }
                        foreach ( $fields as $field => $name ) {
                            if (
                                $data[ $field ] !== '' &&
                                $current[ $field ] !== '' &&
                                strcasecmp( $data[ $field ], $current[ $field ] ) !== 0
                            ) {
                                $diff[] = $name;
                            }
                        }
                        if ( ! empty ( $diff ) ) {
                            if ( $verify_customer_details === 'on_update' ) {
                                // force_update_customer is client-supplied and must never bypass code verification.
                                // A code only counts for the recipient it was issued to, so a code obtained
                                // for one contact cannot authorise a change to another person's record.
                                $recipient = $identifier === 'phone'
                                    ? Cloud\SMS::normalizePhoneNumber( (string) $customer->getPhone() )
                                    : strtolower( trim( (string) $customer->getEmail() ) );
                                $sent_recipient = $identifier === 'phone'
                                    ? Cloud\SMS::normalizePhoneNumber( (string) $userData->getVerificationCodeRecipient() )
                                    : strtolower( trim( (string) $userData->getVerificationCodeRecipient() ) );
                                if ( self::verificationCodeMatches( $data['verification_code'], $userData->getVerificationCode() )
                                    && $recipient !== ''
                                    && $sent_recipient === $recipient
                                ) {
                                    $userData->setVerifiedRecipient( $recipient );
                                    $userData->setVerificationAttemptCount( 0 );
                                } else {
                                    $this->registerVerificationAttempt( $data, $userData );
                                    $this->errors['verify'] = $identifier;
                                }
                            } elseif ( ! isset ( $data['force_update_customer'] ) ) {
                                // Update rewrites the record only for a session that owns it; for anyone
                                // else it keeps the stored details and fills in the empty ones, and the
                                // message must not promise more than that.
                                $this->errors['customer'] = sprintf(
                                    $userData->customerIdentityConfirmed( $customer )
                                        ? __( 'Your %s: %s is already associated with another %s.<br/>Press Update if we should update your user data, or press Cancel to edit entered data.', 'bookly-responsive-appointment-booking-tool' )
                                        : __( 'Your %s: %s is already associated with another %s.<br/>Press Update to continue: the details we already have remain unchanged, and only missing details are added. Press Cancel to edit the entered data.', 'bookly-responsive-appointment-booking-tool' ),
                                    $fields[ $identifier ],
                                    $data[ $identifier ],
                                    implode( ', ', $diff )
                                );
                            }
                        }
                    }
                }
            }
            // Add customer name, email and phone to send notification
            if ( ! $customer->isLoaded() ) {
                if ( Config::showFirstLastName() ) {
                    $customer->setFirstName( $data['first_name'] );
                    $customer->setLastName( $data['last_name'] );
                } else {
                    $customer->setFullName( $data['full_name'] );
                }
                $customer->setEmail( $data['email'] );
                $customer->setPhone( $data['phone'] );
            }

            // Verify customer details
            if ( in_array( $verify_customer_details, array( 'always_phone', 'always_email' ) ) ) {
                // Normalized recipient key so that mere reformatting (or a loaded customer's
                // stored format) is not mistaken for a different recipient.
                if ( $verify_customer_details === 'always_phone' ) {
                    $verify_recipient = Cloud\SMS::normalizePhoneNumber( (string) $customer->getPhone() );
                    $sent_recipient = Cloud\SMS::normalizePhoneNumber( (string) $userData->getVerificationCodeRecipient() );
                } else {
                    $verify_recipient = strtolower( trim( (string) $customer->getEmail() ) );
                    $sent_recipient = strtolower( trim( (string) $userData->getVerificationCodeRecipient() ) );
                }
                if ( $verify_recipient !== '' && $userData->getVerifiedRecipient() === $verify_recipient ) {
                    // This recipient was already verified earlier in the session — do not ask again.
                } elseif ( self::verificationCodeMatches( $data['verification_code'], $userData->getVerificationCode() )
                    && $sent_recipient === $verify_recipient ) {
                    // Correct code entered for the recipient it was actually sent to —
                    // remember it so this recipient is not asked to verify again this session.
                    // The recipient binding prevents passing with a code issued for another number.
                    $userData->setVerifiedRecipient( $verify_recipient );
                    // Proven control of the recipient — reset the resend throttle so a later
                    // verification round (e.g. a new number) starts fresh at the first interval.
                    $userData->setVerificationResendCount( 0 );
                    $userData->setVerificationCodeSentAt( 0 );
                    $userData->setVerificationAttemptCount( 0 );
                } else {
                    $this->registerVerificationAttempt( $data, $userData );
                    $this->errors['verify'] = $verify_customer_details === 'always_phone' ? 'phone' : 'email';
                }
            }

            // Send message with verification code
            if ( isset( $this->errors['verify'] ) ) {
                $recipient = $this->errors['verify'] == 'phone' ? $customer->getPhone() : $customer->getEmail();
                $this->errors['verify_text'] = $this->errors['verify'] == 'phone' ? __( 'Enter verification code from SMS', 'bookly-responsive-appointment-booking-tool' ) : __( 'Enter verification code from email', 'bookly-responsive-appointment-booking-tool' );
                $this->errors['incorrect_code_text'] = $this->errors['verify'] == 'phone' ? Utils\Common::getTranslatedOption( 'bookly_l10n_incorrect_phone_verification_code' ) : Utils\Common::getTranslatedOption( 'bookly_l10n_incorrect_email_verification_code' );

                $resend_schedule = array( 60, 120, 600 );
                $now = time();
                $count = $userData->getVerificationResendCount();
                $required = $count > 0
                    ? $resend_schedule[ min( $count, count( $resend_schedule ) ) - 1 ]
                    : 0;
                $elapsed = $now - $userData->getVerificationCodeSentAt();
                $need_new_code = $userData->getVerificationCodeRecipient() !== $recipient || isset( $data['resend_verification_code'] );
                if ( $need_new_code && $elapsed >= $required ) {
                    $userData->setVerificationCode( mt_rand( 100000, 999999 ) );
                    Sender::send( $customer, $userData->getVerificationCode(), $this->errors['verify'] );
                    $userData->setVerificationCodeRecipient( $recipient );
                    $userData->setVerificationCodeSentAt( $now );
                    $userData->setVerificationResendCount( ++$count );
                }
                // Seconds the client must wait before the next resend is allowed.
                if ( $count > 0 ) {
                    $next_required = $resend_schedule[ min( $count, count( $resend_schedule ) ) - 1 ];
                    $this->errors['resend_after'] = max( 0, $next_required - ( $now - $userData->getVerificationCodeSentAt() ) );
                } else {
                    $this->errors['resend_after'] = 0;
                }
            }

            // Check "skip payment" custom groups settings
            if ( BookingProxy\CustomerGroups::getSkipPayment( $customer ) ) {
                $this->errors['group_skip_payment'] = true;
            }
            // Check appointments limit
            $data = array();
            foreach ( $userData->cart->getItems() as $cart_item ) {
                if ( $cart_item->toBePutOnWaitingList() ) {
                    // Skip waiting list items.
                    continue;
                }

                $service = $cart_item->getService();
                $slots = $cart_item->getSlots();

                $data[ $service->getId() ]['service'] = $service;
                $data[ $service->getId() ]['dates'][] = $slots[0][2];
            }
            foreach ( $data as $service_data ) {
                if ( $service_data['service']->appointmentsLimitReached( $customer->getId(), $service_data['dates'] ) ) {
                    $this->errors['appointments_limit_reached'] = true;
                    break;
                }
            }
        }
    }

    /**
     * Check a submitted verification code against the one stored in the session.
     *
     * The submitted value comes from the client and may arrive as any JSON type. A loose
     * comparison would accept boolean true for any stored code, so only a string or an
     * integer made of digits is compared, and it is compared as a string.
     *
     * @param mixed $submitted
     * @param mixed $expected
     * @return bool
     */
    protected static function verificationCodeMatches( $submitted, $expected )
    {
        if ( is_int( $submitted ) ) {
            $submitted = (string) $submitted;
        }
        if ( is_int( $expected ) ) {
            $expected = (string) $expected;
        }
        if ( ! is_string( $submitted ) || ! is_string( $expected ) ) {
            return false;
        }
        $submitted = trim( $submitted );
        if ( $expected === '' || ! preg_match( '/^\d+$/', $submitted ) ) {
            return false;
        }

        return hash_equals( $expected, $submitted );
    }

    /**
     * Count a wrong verification code entry.
     *
     * A code stays in the session until its recipient changes, so without a cap the
     * six-digit value could be searched by repeated submissions. On reaching the cap the
     * stored code is replaced by a value that is never sent, so every guess is worthless
     * until the customer requests a new code.
     *
     * @param array $data
     * @param UserBookingData $userData
     */
    protected function registerVerificationAttempt( $data, UserBookingData $userData )
    {
        if ( ! isset ( $data['verification_code'] ) || $data['verification_code'] === '' ) {
            // The step is only being opened, no code entered yet.
            return;
        }

        $attempts = $userData->getVerificationAttemptCount() + 1;
        if ( $attempts >= self::MAX_VERIFICATION_ATTEMPTS ) {
            $userData->setVerificationCode( mt_rand( 100000, 999999 ) );
            $userData->setVerificationAttemptCount( 0 );
        } else {
            $userData->setVerificationAttemptCount( $attempts );
        }
    }

    /**
     * Validate the multipliers of every chain item against the range its service allows.
     *
     * Number of persons, units and quantity all scale the order total, so a value outside
     * the range the booking form offers is refused rather than quietly adjusted: the saved
     * order then always matches what the customer was shown.
     *
     * @param string $field
     * @param array $chain
     */
    public function validateChain( $field, $chain )
    {
        if ( ! is_array( $chain ) ) {
            $this->errors[ $field ] = __( 'Invalid number', 'bookly-responsive-appointment-booking-tool' );

            return;
        }

        $max_quantity = max( 1, (int) get_option( 'bookly_multiply_appointments_quantity_max', 10 ) );

        foreach ( $chain as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $service = isset ( $item['service_id'] ) ? Entities\Service::find( $item['service_id'] ) : null;
            $staff_ids = isset ( $item['staff_ids'] ) ? (array) $item['staff_ids'] : array();
            $number_of_persons = isset ( $item['number_of_persons'] ) ? $item['number_of_persons'] : null;
            $units = isset ( $item['units'] ) ? $item['units'] : null;
            $location_id = isset ( $item['location_id'] ) ? (int) $item['location_id'] : 0;

            if ( ! $this->multipliersInRange( $service, $staff_ids, $number_of_persons, $units, $location_id ) ) {
                $this->errors[ $field ] = __( 'Invalid number', 'bookly-responsive-appointment-booking-tool' );
            }

            if ( isset ( $item['quantity'] ) ) {
                $quantity = (int) $item['quantity'];
                if ( $quantity < 1 || $quantity > $max_quantity ) {
                    $this->errors[ $field ] = __( 'Invalid number', 'bookly-responsive-appointment-booking-tool' );
                }
            }
        }
    }

    /**
     * Whether the multipliers of a booked item stay within the range its service allows.
     *
     * Number of persons and units both scale the price and the duration of a booking, and
     * both reach the server as plain request values, so every booking form checks them
     * against the service before the item is priced. A null value is not submitted at all
     * and needs no check.
     *
     * @param Entities\Service|null $service
     * @param array $staff_ids
     * @param int|null $number_of_persons
     * @param int|null $units
     * @param int $location_id
     * @return bool
     */
    public function multipliersInRange( $service, array $staff_ids, $number_of_persons, $units, $location_id = 0 )
    {
        if ( $number_of_persons !== null ) {
            list ( $min, $max ) = $service ? $service->getPersonsRange( $staff_ids, $location_id ) : array( 1, 1 );
            $number_of_persons = (int) $number_of_persons;
            if ( $number_of_persons < $min || $number_of_persons > $max ) {
                return false;
            }
        }

        if ( $units !== null ) {
            list ( $min, $max ) = $service ? $service->getUnitsRange() : array( 1, 1 );
            $units = (int) $units;
            if ( $units < $min || $units > $max ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate the extras submitted for every chain item against the ones its service offers.
     *
     * Extras scale both the price and the duration of a booking, so an extra which belongs to
     * another service, or a quantity outside the range the extras step offers, is refused
     * rather than quietly adjusted: the saved order then always matches what the customer
     * was shown.
     *
     * @param string $field
     * @param array $extras [chain key => JSON encoded [extra id => quantity]]
     * @param UserBookingData $userData
     */
    public function validateExtras( $field, $extras, UserBookingData $userData )
    {
        if ( ! is_array( $extras ) ) {
            $this->errors[ $field ] = __( 'Invalid number', 'bookly-responsive-appointment-booking-tool' );

            return;
        }

        $chain_items = $userData->chain->getItems();
        foreach ( $extras as $key => $value ) {
            $items = is_array( $value ) ? $value : json_decode( (string) $value, true );
            $service = isset ( $chain_items[ $key ] ) ? $chain_items[ $key ]->getService() : null;
            if ( ! is_array( $items ) || ! $service ) {
                $this->errors[ $field ] = __( 'Invalid number', 'bookly-responsive-appointment-booking-tool' );
                continue;
            }

            $available = $service->getAvailableExtras();
            foreach ( $items as $extra_id => $quantity ) {
                if ( ! isset ( $available[ $extra_id ] ) ) {
                    $this->errors[ $field ] = __( 'Invalid number', 'bookly-responsive-appointment-booking-tool' );
                    continue;
                }
                $extra = $available[ $extra_id ];
                $quantity = (int) $quantity;
                // Zero stands for an extra which was not taken, any other quantity is the one
                // the extras step lets the customer pick.
                if ( $quantity !== 0 && ( $quantity < max( 1, (int) $extra->getMinQuantity() ) || $quantity > (int) $extra->getMaxQuantity() ) ) {
                    $this->errors[ $field ] = __( 'Invalid number', 'bookly-responsive-appointment-booking-tool' );
                }
            }
        }
    }

    /**
     * Validate info fields.
     *
     * @param array $info_fields
     */
    public function validateInfoFields( array $info_fields )
    {
        $this->errors = Proxy\CustomerInformation::validate( $this->errors, $info_fields );
    }

    /**
     * Validate cart.
     *
     * @param array $cart
     * @param int $form_id
     */
    public function validateCart( $cart, $form_id )
    {
        foreach ( $cart as $cart_key => $cart_parameters ) {
            foreach ( $cart_parameters as $parameter => $value ) {
                switch ( $parameter ) {
                    case 'custom_fields':
                        $this->errors = Proxy\CustomFields::validate( $this->errors, $value, $form_id, $cart_key );
                        break;
                }
            }
        }
    }

    /**
     * Get errors.
     *
     * @return array
     */
    public function getErrors()
    {
        return $this->errors;
    }
}