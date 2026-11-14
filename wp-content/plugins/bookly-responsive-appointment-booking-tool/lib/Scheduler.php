<?php
namespace Bookly\Lib;

use Bookly\Lib;
use Bookly\Lib\Slots\DatePoint;

class Scheduler
{
    const REPEAT_DAILY    = 'daily';
    const REPEAT_WEEKLY   = 'weekly';
    const REPEAT_BIWEEKLY = 'biweekly';
    const REPEAT_MONTHLY  = 'monthly';
    const REPEAT_YEARLY   = 'yearly';//not implemented yet
    const REPEAT_NONE     = 'none';

    private $client_from;

    private $time;

    private $client_until;

    private $repeat;

    private $params;

    private $slots;

    private $index;

    /** @var Lib\UserBookingData */
    private $userData;

    /** @var Lib\Slots\Finder */
    private $finder;

    /** @var bool */
    private $for_backend;

    /** @var bool */
    private $with_options;

    /** @var int */
    private $min_time_prior_booking = 0;

    /**
     * Constructor.
     *
     * @param Lib\Chain $chain chain to repeat
     * @param string $datetime first appointment date and time (in WP time zone)
     * @param string $until the last appointment date (in client time zone)
     * @param string $repeat repeat period, could be one of self::REPEAT_*
     * @param array $params additional params we should know to build schedule
     * @param array $exclude slots we can't use for schedule
     * @param bool $waiting_list_enabled
     * @param array $ignore_appointments
     */
    public function __construct( Lib\Chain $chain, $datetime, $until, $repeat, array $params, array $exclude, $waiting_list_enabled = null, $ignore_appointments = array() )
    {
        // Update $until for chain with duration greater than 24 hour
        $duration = 0;
        foreach ( $chain->getItems() as $chain_item ) {
            $service = Lib\Entities\Service::find( $chain_item->getServiceId() );
            $this->min_time_prior_booking = max( $this->min_time_prior_booking, Lib\Proxy\Pro::getMinimumTimePriorBooking( $chain_item->getServiceId() ) );
            if ( $service->withSubServices() ) {
                foreach ( $service->getSubServices() as $sub_service ) {
                    if ( $service->isCompound() ) {
                        $duration += $sub_service->getDuration();
                    } elseif ( $service->isCollaborative() ) {
                        $duration = max( $duration, $sub_service->getDuration() );
                    }
                }
            } else {
                $duration = $chain_item->getUnits() * $service->getDuration();
            }
        }

        $last_date = date_create( $datetime )->modify( max( 0, (int) ( $duration / DAY_IN_SECONDS ) - 1 ) . ' days' );
        if ( $last_date > date_create( $until ) ) {
            $until = $last_date->format( 'Y-m-d' );
        }

        // Set up UserBookingData.
        $this->userData = new Lib\UserBookingData( null );
        $this->userData->resetChain();
        $this->userData->chain = $chain;
        $this->userData->setDays( array( 1, 2, 3, 4, 5, 6, 7 ) );

        if ( isset ( $params['time_zone_offset'] ) || ( isset( $params['time_zone'] ) && $params['time_zone'] !== '' ) ) {
            $this->userData
                ->setTimeZone( $params['time_zone'] )
                ->setTimeZoneOffset( $params['time_zone_offset'] )
                ->applyTimeZone();
        }
        if ( isset( $params['userData'] ) ) {
            /** @var Lib\UserBookingData $userData */
            $userData = $params['userData'];
            foreach ( $userData->cart->getItems() as $item ) {
                if ( $item->getSlots() ) {
                    $this->userData->cart->add( $item );
                }
            }
        }

        foreach ( $exclude as $slots ) {
            $this->userData
                ->setSlots( json_decode( $slots, true ) )
                ->addChainToCart()
                ->setEditCartKeys( array() );
        }

        // resetChain() sets date_from based on the global minimum time prior booking,
        // services in the chain may allow earlier dates; existing bookings must be loaded from the actual schedule start
        $this->userData->setDateFrom( DatePoint::fromStr( $datetime )->toClientTz()->format( 'Y-m-d' ) );

        // Set up Finder.
        $this->finder = new Lib\Slots\Finder(
            $this->userData,
            function( DatePoint $client_dp ) {
                return $client_dp->format( 'Y-m-d' );
            },
            function( DatePoint $client_dp, $groups_count, $slots_count ) {
                return $groups_count >= 1 ? 2 : 0;
            },
            $waiting_list_enabled,
            $ignore_appointments,
            isset( $params['show_blocked_slots'] ) && $params['show_blocked_slots']
        );

        $this->finder->prepare( isset( $params['for_backend'] ) && $params['for_backend'] ? DatePoint::fromStr( $until )->modify( '+1 week' ) : null );

        $this->client_from = DatePoint::fromStr( $datetime )->toClientTz();
        $this->client_until = DatePoint::fromStrInClientTz( $until );

        $this->time = ( $repeat === self::REPEAT_NONE || ( isset( $params['full_day'] ) && $params['full_day'] ) ) ? '00:00:00' : $this->client_from->format( 'H:i:s' );
        $this->repeat = $repeat;
        $this->params = $params;
    }

    /**
     * Create schedule on backend.
     *
     * @param bool $with_options
     * @return array
     */
    public function scheduleForBackend( $with_options = false )
    {
        $this->for_backend = true;
        $this->with_options = (bool) $with_options;

        return $this->_schedule();
    }

    /**
     * Create schedule on frontend.
     *
     * @param bool $with_options
     * @return array
     */
    public function scheduleForFrontend( $with_options = false )
    {
        $this->for_backend = false;
        $this->with_options = (bool) $with_options;

        return $this->_schedule();
    }

    /**
     * Build schedule based on timeslots
     *
     * @param int[] $slots array of unix timestamps with appointment time
     * @return array
     */
    public function build( array $slots )
    {
        $this->slots = array();
        $this->for_backend = false;
        $this->with_options = false;

        $appointments_in_chain = 0;
        foreach ( $this->userData->chain->getItems() as $item ) {
            $appointments_in_chain += $item->getQuantity();
        }

        for ( $i = 0, $count = count( $slots ); $i < $count; $i += $appointments_in_chain ) {
            $dp = DatePoint::fromStr( $slots[ $i ] );
            $client_dp = $dp->toClientTz();
            $this->time = $client_dp->format( 'H:i:s' );
            $this->_addSlot( $client_dp->modify( 'today' ), true );
        }

        return $this->slots;
    }

    /**
     * Create schedule.
     *
     * @return array
     */
    private function _schedule()
    {
        $this->slots = array();
        $this->index = 0;

        $start_dp = $this->client_from->modify( 'today' );
        $client_dp = $this->client_from->modify( 'today' );

        $weekdays = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );
        $ordinals = array( 'first', 'second', 'third', 'fourth', 'last' );

        switch ( $this->repeat ) {
            case self::REPEAT_NONE:
                while ( $client_dp->lte( $this->client_until ) ) {
                    $this->_addSlot( $client_dp, false, $client_dp->modify( '+1 day' ) );
                    $client_dp = $client_dp->modify( '+1 day' );
                }
                break;
            case self::REPEAT_DAILY:
                if ( isset ( $this->params['every'] ) ) {
                    while ( $client_dp->lte( $this->client_until ) ) {
                        $this->_addSlot( $client_dp, $this->params['every'] < 7 );
                        $client_dp = $client_dp->modify( sprintf( '+%d days', $this->params['every'] ) );
                    }
                }
                break;
            case self::REPEAT_WEEKLY:
                if ( isset ( $this->params['on'] ) && is_array( $this->params['on'] ) && ! empty ( $this->params['on'] ) ) {
                    self::sortWeekdays( $this->params['on'] );
                    while ( true ) {
                        foreach ( $this->params['on'] as $weekday ) {
                            if ( in_array( $weekday, $weekdays ) ) {
                                $client_dp = $client_dp
                                    ->modify( 'previous sun' )  // In PHP 5.6.23 & 7.0.8 this would be just '$weekday this week'
                                    ->modify( 'next mon' )      // (@see https://bugs.php.net/bug.php?id=63740).
                                    ->modify( $weekday );
                                if ( $client_dp->gt( $this->client_until ) ) {
                                    break ( 2 );
                                }
                                if ( $client_dp->gte( $start_dp ) ) {
                                    $this->_addSlot( $client_dp );
                                }
                            }
                        }
                        $client_dp = $client_dp->modify( '+1 week' );
                    }
                }
                break;
            case self::REPEAT_BIWEEKLY:
                if ( isset ( $this->params['on'] ) && is_array( $this->params['on'] ) && ! empty ( $this->params['on'] ) ) {
                    self::sortWeekdays( $this->params['on'] );
                    while ( true ) {
                        foreach ( $this->params['on'] as $weekday ) {
                            if ( in_array( $weekday, $weekdays ) ) {
                                $client_dp = $client_dp
                                    ->modify( 'previous sun' )  // In PHP 5.6.23 & 7.0.8 this would be just '$weekday this week'
                                    ->modify( 'next mon' )      // (@see https://bugs.php.net/bug.php?id=63740).
                                    ->modify( $weekday );
                                if ( $client_dp->gt( $this->client_until ) ) {
                                    break ( 2 );
                                }
                                if ( $client_dp->gte( $start_dp ) ) {
                                    $this->_addSlot( $client_dp );
                                }
                            }
                        }
                        $client_dp = $client_dp->modify( '+2 weeks' );
                    }
                }
                break;
            case self::REPEAT_MONTHLY:
                if ( isset ( $this->params['on'] ) ) {
                    if ( $this->params['on'] == 'day' && isset ( $this->params['day'] ) ) {
                        while ( $client_dp->lte( $this->client_until ) ) {
                            if ( $this->params['day'] <= $client_dp->format( 't' ) ) {
                                $client_dp = $client_dp
                                    ->modify( 'last day of previous month' )
                                    ->modify( sprintf( '+%d days', $this->params['day'] ) );
                                if ( $client_dp->gte( $start_dp ) ) {
                                    $this->_addSlot( $client_dp );
                                }
                            }
                            $client_dp = $client_dp->modify( 'first day of next month' );
                        }
                    } elseif ( in_array( $this->params['on'], $ordinals ) && isset ( $this->params['weekday'] ) && in_array( $this->params['weekday'], $weekdays ) ) {
                        while ( $client_dp->lte( $this->client_until ) ) {
                            $client_dp = $client_dp
                                ->modify( sprintf( '%s %s of', $this->params['on'], $this->params['weekday'] ) );
                            if ( $client_dp->gte( $start_dp ) ) {
                                $this->_addSlot( $client_dp );
                            }
                            $client_dp = $client_dp->modify( 'first day of next month' );
                        }
                    }
                }
                break;
            case self::REPEAT_YEARLY:
                break;
        }

        return $this->slots;
    }

    /**
     * Add slot.
     *
     * @param DatePoint $client_dp
     * @param boolean $skip_days_off
     * @param null|DatePoint $client_end_dp
     */
    private function _addSlot( DatePoint $client_dp, $skip_days_off = false, $client_end_dp = null )
    {
        $this->finder->client_start_dp = $client_dp;
        $until = $this->client_until->modify( '+1 day' );
        $last_date = new DatePoint( date_create() );
        $last_date = $last_date->modify( Config::getMaximumAvailableDaysForBooking() . ' days' );
        $this->finder->client_end_dp = $this->for_backend || $last_date->gte( $until ) ? $until : $last_date;
        $this->finder->start_dp = $this->finder->client_start_dp->toWpTz();
        $this->finder->end_dp = $this->finder->client_end_dp->toWpTz();

        $this->finder->load(
            $skip_days_off ?
                function( DatePoint $dp, $srv_duration_days, $slots_count ) use ( $client_dp ) {
                    return ( $dp->gte( $client_dp->modify( max( $srv_duration_days, 1 ) . ' days' ) ) && $slots_count == 0 ) || $dp->gte( $this->finder->client_end_dp );
                }
                : null
        );

        $slot = $this->findSlot( $client_dp, $client_end_dp );

        if ( $slot === null && $this->finder->hasMoreSlots() ) {
            // If there are more slots then start new search for the next day.
            $slots = $this->finder->getSlots();
            $this->finder->client_start_dp = DatePoint::fromStrInClientTz( key( $slots ) )->modify( '+1 day' );
            $this->finder->start_dp = $this->finder->client_start_dp->toWpTz();
            $this->finder->load();

            $slot = $this->findSlot( $client_dp, $client_end_dp );
        }

        if ( $slot !== null ) {
            $this->slots[] = $slot;

            $this->userData
                ->setSlots( json_decode( $slot['slots'], true ) )
                ->addChainToCart()
                ->setEditCartKeys( array() );
            $this->finder->handleCartBookings();
            $this->userData->cart = new Lib\Cart( $this->userData );
        }
    }

    /**
     * Find slot
     *
     * @param DatePoint $client_dp
     * @param null|DatePoint $client_end_dp
     * @return array|null
     */
    private function findSlot( DatePoint $client_dp, $client_end_dp = null )
    {
        $client_req_dp = $client_dp->modify( $this->time );
        $client_req_end_dp = $client_end_dp ? $client_end_dp->modify( $this->time ) : null;
        $client_res_dp = null;
        $options = array();
        $slots = array();

        foreach ( $this->finder->getSlots() as $group ) {
            /** @var Lib\Slots\Range $slot */
            foreach ( $group as $slot ) {
                $min_date = Lib\Slots\DatePoint::now()->modify( $this->min_time_prior_booking );
                if ( $this->for_backend || $min_date->lte( $slot->start() ) ) {
                    $data = $slot->buildSlotData();
                    // Check if we already have this slot in results
                    $has_slot = false;
                    $_data = json_encode( $data );
                    foreach ( $this->slots as $_slot ) {
                        if ( $_slot['slots'] === $_data ) {
                            $has_slot = true;
                            break;
                        }
                    }
                    if ( ! $has_slot ) {
                        /** @var DatePoint $client_start_dp */
                        $client_start_dp = $slot->start()->toClientTz();
                        $title = $client_start_dp->formatI18n( get_option( 'time_format' ) );
                        if ( $this->with_options ) {
                            $option = array(
                                'value' => json_encode( $data ),
                                'title' => $title,
                                'disabled' => $slot->fullyBooked(),
                                'waiting_list_count' => $slot->waitingListEverStarted() ? $slot->maxOnWaitingList() : null,
                            );
                            if ( isset( $this->params['with_nop'] ) && $this->params['with_nop'] ) {
                                $option['nop'] = $slot->data()->nop();
                                $option['capacity'] = $slot->data()->capacity();
                            }
                            $options[] = $option;
                        }
                        if ( $client_res_dp === null && $slot->notFullyBooked() && $client_start_dp->gte( $client_req_dp ) && ( ! $client_req_end_dp || $slot->end()->toClientTz()->lte( $client_req_end_dp ) ) ) {
                            $client_res_dp = $client_start_dp;
                            $slots = $data;

                            if ( ! $this->with_options ) {
                                $options[] = array(
                                    'value' => json_encode( $data ),
                                    'title' => $title,
                                );
                                break;
                            }
                        }
                    }
                }
            }
            if ( $client_res_dp === null ) {
                return null;
            }

            $result = array(
                'index' => ++$this->index,
                'slots' => json_encode( $slots ),
                'options' => $options,
                'another_time' => $client_res_dp->neq( $client_req_dp ),
            );
            if ( $this->finder->isServiceDurationInDays() ) {
                $result['all_day_service_time'] = Lib\Entities\Service::find( $slot->serviceId() )->getStartTimeInfo() ?: '';
            }
            $result['date'] = $client_res_dp->format( 'Y-m-d' );
            if ( ! $this->for_backend ) {
                $result['display_date'] = $client_res_dp->formatI18n( 'D, M d' );
                $result['display_time'] = $client_res_dp->formatI18n( get_option( 'time_format' ) );
            }

            return $result;
        }

        return null;
    }

    /**
     * Check whether the chain restricted to the given staff members has at least one
     * bookable slot on the first requested day. The finder prepared for the full staff
     * pool is reloaded with the chain staff narrowed to the given IDs, so a series of
     * probes shares one prepare instead of repeating its queries for every staff
     * member. The answer follows the findSlot rules of a frontend schedule run.
     *
     * @param int[] $staff_ids
     * @return bool
     */
    public function staffProbe( array $staff_ids )
    {
        $items = $this->userData->chain->getItems();
        $saved = array();
        foreach ( $items as $key => $item ) {
            $saved[ $key ] = $item->getStaffIds();
            $item->setStaffIds( array_values( array_intersect( $saved[ $key ], $staff_ids ) ) );
        }
        try {
            $client_dp = $this->client_from->modify( 'today' );
            $this->finder->client_start_dp = $client_dp;
            $until = $this->client_until->modify( '+1 day' );
            $last_date = new DatePoint( date_create() );
            $last_date = $last_date->modify( Config::getMaximumAvailableDaysForBooking() . ' days' );
            $this->finder->client_end_dp = $last_date->gte( $until ) ? $until : $last_date;
            $this->finder->start_dp = $this->finder->client_start_dp->toWpTz();
            $this->finder->end_dp = $this->finder->client_end_dp->toWpTz();
            $this->finder->load();

            $this->for_backend = false;
            $this->with_options = false;
            if ( $this->slots === null ) {
                $this->slots = array();
            }

            $found = $this->findSlot( $client_dp ) !== null;
        } catch ( \Exception $e ) {
            foreach ( $items as $key => $item ) {
                $item->setStaffIds( $saved[ $key ] );
            }
            throw $e;
        }
        foreach ( $items as $key => $item ) {
            $item->setStaffIds( $saved[ $key ] );
        }

        return $found;
    }

    /**
     * Get IDs of staff members that have at least one bookable slot on the first
     * requested day of the last schedule run. A winner slot carries the full chain
     * of alternative staff members for its time, so one schedule run yields the
     * same set of bookable staff as probing every staff member with a separate
     * search. Bookable follows the findSlot rules: the slot (or an alternative)
     * is not fully booked and passes the minimum-time-prior-booking limit.
     *
     * Call after scheduleForFrontend/scheduleForBackend.
     *
     * @return int[]
     */
    public function staffWithSlots()
    {
        $result = array();
        $groups = $this->finder->getSlots();
        $group_key = $this->client_from->format( 'Y-m-d' );
        if ( isset( $groups[ $group_key ] ) ) {
            $min_date = Lib\Slots\DatePoint::now()->modify( $this->min_time_prior_booking );
            foreach ( $groups[ $group_key ] as $slot ) {
                /** @var Lib\Slots\Range $slot */
                if ( $this->for_backend || $min_date->lte( $slot->start() ) ) {
                    $guard = 0;
                    do {
                        if ( $slot->notFullyBooked() ) {
                            $result[ $slot->staffId() ] = true;
                        }
                        $slot = $slot->hasAltSlot() ? $slot->altSlot() : null;
                    } while ( $slot && $guard++ < 1000 );
                }
            }
        }

        return array_map( 'intval', array_keys( $result ) );
    }

    /**
     * Sort days considering start_of_week.
     *
     * @param array $input
     */
    public static function sortWeekdays( array &$input )
    {
        $weekdays = array( 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7 );

        usort( $input, function( $a, $b ) use ( $weekdays ) {
            return $weekdays[ $a ] - $weekdays[ $b ];
        } );
    }

}