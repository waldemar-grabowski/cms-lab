<?php
namespace Bookly\Frontend\Modules\Ai;

use Bookly\Lib;

/**
 * What of this booking system the business has and how it is set up.
 *
 * Two readers. Bookly Cloud puts its prompt together from parts and picks them by facts(),
 * sent with the catalog in SystemPrompt::site(): the texts are Cloud's, this class only
 * says what applies to the business. The tool schemas ask has(): an argument the business
 * has no use for (location_id without locations) is left out.
 *
 * Decided from the data the catalog shows, not from which add-ons are on: Service Extras
 * switched on with no extras on any public service is a business without extras. Settings
 * are interpreted here, next to the code that acts on them - options themselves never
 * leave the site.
 *
 * Safe for Cloud's prompt cache for the same reason as the catalog: it changes only when
 * the owner changes the catalog or the settings.
 */
class Concepts
{
    // What the business may have, see has().
    const EXTRA    = 'extra';
    const LOCATION = 'location';
    const GROUP    = 'group';
    const CATEGORY = 'category';

    // How it may be set up, see has().
    const TIME_PRICE  = 'time_price';
    const STAFF_PRICE = 'staff_price';
    const PAY_LOCALLY = 'pay_locally';
    const DEPOSIT     = 'deposit';

    // How bookings are paid here, see payment().
    const PAYMENT_NONE           = 'none';
    const PAYMENT_AT_APPOINTMENT = 'at_appointment';
    const PAYMENT_ONLINE         = 'online';

    /** @var static|null */
    private static $site;

    /** @var array [ concept or setting => true ] */
    private $on = array();

    /** @var string self::PAYMENT_* */
    private $payment;

    /**
     * Decided once per request.
     *
     * @return static
     */
    public static function forSite()
    {
        if ( self::$site === null ) {
            self::$site = new static();
        }

        return self::$site;
    }

    /**
     * @param string $name self::EXTRA etc.
     * @return bool
     */
    public function has( $name )
    {
        return isset( $this->on[ $name ] );
    }

    /**
     * @return string self::PAYMENT_*
     */
    public function payment()
    {
        return $this->payment;
    }

    /**
     * How the business is set up, as values: Bookly Cloud picks the parts of its prompt that
     * apply to this business by them - see SystemPrompt::site(). Grouped by what the customer
     * sees: payment, extra, location etc. Every fact is always sent, so a missing one means
     * only an older plugin. Each is listed in Cloud's Resources/prompts/facts.yml, and what
     * a name and a value mean never changes: a new meaning is a new fact.
     *
     * pay_locally and deposit are only ever true with mode "online".
     *
     * @return array
     */
    public function facts()
    {
        return array(
            'payment'  => array(
                'mode'        => $this->payment,
                'pay_locally' => $this->has( self::PAY_LOCALLY ),
                'deposit'     => $this->has( self::DEPOSIT ),
            ),
            'extra'    => array(
                'present'          => $this->has( self::EXTRA ),
                'affects_duration' => (bool) Lib\Proxy\ServiceExtras::considerDuration(),
            ),
            'location' => array( 'present' => $this->has( self::LOCATION ) ),
            'group'    => array( 'present' => $this->has( self::GROUP ) ),
            'category' => array( 'present' => $this->has( self::CATEGORY ) ),
            'service'  => array(
                'price_by_time'  => $this->has( self::TIME_PRICE ),
                'price_by_staff' => $this->has( self::STAFF_PRICE ),
            ),
            'catalog'  => array( 'complete' => ! Catalog::truncated() ),
            'company'  => array( 'contacts' => SystemPrompt::hasContacts() ),
        );
    }

    private function __construct()
    {
        $data = Catalog::data();

        $service_ids = array();
        $staff_ids = array();
        foreach ( $data['services'] as $entry ) {
            $service_ids[] = $entry['id'];
            $staff_ids = array_merge( $staff_ids, isset( $entry['price_by_staff'] ) ? array_map( 'intval', array_keys( (array) $entry['price_by_staff'] ) ) : $entry['staff_ids'] );
            if ( isset( $entry['price_by_staff'] ) ) {
                $this->on[ self::STAFF_PRICE ] = true;
            }
            if ( ! empty( $entry['extras'] ) ) {
                $this->on[ self::EXTRA ] = true;
            }
            if ( isset( $entry['persons'] ) ) {
                $this->on[ self::GROUP ] = true;
            }
            // Catalog lists categories only when there are two or more.
            if ( isset( $entry['category_id'] ) ) {
                $this->on[ self::CATEGORY ] = true;
            }
        }
        $staff_ids = array_unique( $staff_ids );

        // Catalog lists only locations some bookable staff member works at.
        if ( $data['locations'] ) {
            $this->on[ self::LOCATION ] = true;
        }

        // Null without Special Hours.
        if ( $staff_ids && Lib\Proxy\SpecialHours::getPeriodsCount( $staff_ids, $service_ids ) ) {
            $this->on[ self::TIME_PRICE ] = true;
        }

        // Only "pay at the appointment" is booked at once, without a payment card -
        // see CreateBooking::execute(). Any other gateway may need the card, even when
        // the gateways of a particular service or staff member leave only paying locally.
        $types = Checkout::enabledGatewayTypes();
        if ( Lib\Config::paymentStepDisabled() || ! $types ) {
            $this->payment = self::PAYMENT_NONE;
        } else {
            if ( $types === array( Lib\Entities\Payment::TYPE_LOCAL ) ) {
                $this->payment = self::PAYMENT_AT_APPOINTMENT;
            } else {
                $this->payment = self::PAYMENT_ONLINE;
                if ( in_array( Lib\Entities\Payment::TYPE_LOCAL, $types, true ) ) {
                    $this->on[ self::PAY_LOCALLY ] = true;
                }
                // Empty or "100%" is the full price (DepositPayments' calcDeposit()); "2" makes
                // every booking pay the full price whatever the deposit.
                if ( $staff_ids
                    && Lib\Config::depositPaymentsActive()
                    && get_option( 'bookly_deposit_allow_full_payment' ) !== '2'
                    && Lib\Entities\StaffService::query()
                        ->whereIn( 'staff_id', $staff_ids )
                        ->whereIn( 'service_id', $service_ids )
                        ->whereNotIn( 'deposit', array( '', '100%' ) )
                        ->count()
                ) {
                    $this->on[ self::DEPOSIT ] = true;
                }
            }
        }
    }
}
