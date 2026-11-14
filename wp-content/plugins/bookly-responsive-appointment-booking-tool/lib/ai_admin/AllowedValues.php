<?php
namespace Bookly\Lib\AiAdmin;

use Bookly\Lib;

/**
 * Values the interface offers for an option or field, computed the way the interface builds its
 * list. Rules::entries()[ref]['values'] says which form: pairs, a range, or a resolver from the
 * closed vocabulary of assets/ai-setup-kit/resolvers.yaml (one method here per resolver id).
 *
 * The result has the shape get_settings returns as "allowed": a list of values,
 * array( 'grid' => array( from, to, step, also ) ) or array( 'min', 'max', 'step' ).
 */
abstract class AllowedValues
{
    /**
     * Values of pairs the interface offers only while an add-on is active, when the pair itself
     * does not say so: entry ref => value => add-on slug.
     *
     * @var array
     */
    protected static $value_addons = array(
        // Without Advanced Google Calendar the Google Calendar tab offers One-way and Two-way front-end only.
        'option:bookly_gc_sync_mode' => array( '2-way' => 'advanced-google-calendar' ),
    );

    /**
     * Allowed values of an entry, or null when the entry has no fixed set (text, colour, URL).
     *
     * @param array $rule    Rules::entries() item
     * @param mixed $current current value — a saved value off the list stays allowed, as in the select
     * @param array $context resolver context, e.g. array( 'id' => <edited object id> ); 'ref' — the
     *                       entry ref, for values offered only with an add-on (valueAddon())
     * @return array|null
     */
    public static function of( array $rule, $current = null, array $context = array() )
    {
        if ( empty( $rule['values'] ) ) {
            return null;
        }
        $v = $rule['values'];
        if ( isset( $v['pairs'] ) ) {
            $ref  = isset( $context['ref'] ) ? $context['ref'] : null;
            $list = array();
            foreach ( $v['pairs'] as $pair ) {
                if ( self::pairAddon( $ref, $pair ) === null ) {
                    $list[] = $pair['value'];
                }
            }

            return $list;
        }
        if ( isset( $v['range'] ) ) {
            return $v['range'];
        }
        if ( isset( $v['resolver'] ) ) {
            $id     = $v['resolver']['id'];
            $params = $v['resolver'];
            unset( $params['id'] );
            if ( ! method_exists( __CLASS__, $id ) ) {
                return null;
            }
            $allowed = call_user_func( array( __CLASS__, $id ), $params, $context );
            if ( is_array( $allowed ) && ! isset( $allowed['grid'] ) ) {
                if ( ! empty( $v['scale'] ) ) {
                    foreach ( $allowed as &$value ) {
                        $value = $value * $v['scale'];
                    }
                    unset( $value );
                }
                foreach ( isset( $v['special'] ) ? $v['special'] : array() as $special ) {
                    // Compared as strings: loose in_array() takes 0 for null and drops "Disabled".
                    if ( ! in_array( (string) $special['value'], array_map( 'strval', $allowed ), true ) ) {
                        array_unshift( $allowed, $special['value'] );
                    }
                }
            } elseif ( isset( $allowed['grid'] ) && ! empty( $v['special'] ) ) {
                foreach ( $v['special'] as $special ) {
                    $allowed['grid']['also'][] = $special['value'];
                }
            }
            if ( isset( $allowed['grid'] ) && $current !== null && $current !== '' && ! self::inGrid( $current, $allowed['grid'] ) ) {
                $allowed['grid']['also'][] = $current;
            }

            return $allowed;
        }

        return null;
    }

    /**
     * Whether a value is allowed.
     *
     * @param mixed      $value
     * @param array|null $allowed result of of()
     * @return bool
     */
    public static function contains( $value, $allowed )
    {
        if ( $allowed === null ) {
            return true;
        }
        if ( isset( $allowed['grid'] ) ) {
            return self::inGrid( $value, $allowed['grid'] );
        }
        if ( isset( $allowed['min'] ) || isset( $allowed['max'] ) ) {
            if ( ! is_numeric( $value ) ) {
                return false;
            }
            if ( isset( $allowed['min'] ) && $value < $allowed['min'] ) {
                return false;
            }
            if ( isset( $allowed['max'] ) && $value > $allowed['max'] ) {
                return false;
            }
            if ( ! empty( $allowed['step'] ) ) {
                $base = isset( $allowed['min'] ) ? $allowed['min'] : 0;

                return abs( fmod( $value - $base, $allowed['step'] ) ) < 1e-9;
            }

            return true;
        }
        foreach ( $allowed as $item ) {
            if ( (string) $item === (string) $value ) {
                return true;
            }
        }

        return false;
    }

    /**
     * The inactive add-on a value of a pairs entry needs, or null when the value is offered on this
     * installation (or is not one of the pairs).
     *
     * @param string $ref   entry ref, e.g. option:bookly_gc_sync_mode
     * @param mixed  $value
     * @return string|null add-on slug
     */
    public static function valueAddon( $ref, $value )
    {
        $rule = Access::rule( $ref );
        if ( ! $rule || ! isset( $rule['values']['pairs'] ) ) {
            return null;
        }
        foreach ( $rule['values']['pairs'] as $pair ) {
            if ( (string) $pair['value'] === (string) $value ) {
                return self::pairAddon( $ref, $pair );
            }
        }

        return null;
    }

    /**
     * The inactive add-on a pair needs: the pair's own "addon" or the one in $value_addons.
     *
     * @param string|null $ref
     * @param array       $pair
     * @return string|null
     */
    protected static function pairAddon( $ref, array $pair )
    {
        $addon = null;
        if ( ! empty( $pair['addon'] ) ) {
            $addon = $pair['addon'];
        } elseif ( $ref !== null && isset( self::$value_addons[ $ref ][ (string) $pair['value'] ] ) ) {
            $addon = self::$value_addons[ $ref ][ (string) $pair['value'] ];
        }

        return $addon !== null && ! Access::addonActive( $addon ) ? $addon : null;
    }

    /**
     * Time "HH:MM[:SS]" → seconds from midnight, or null.
     *
     * @param mixed $time
     * @return int|null
     */
    public static function seconds( $time )
    {
        if ( ! preg_match( '/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', (string) $time, $m ) ) {
            return null;
        }

        return $m[1] * HOUR_IN_SECONDS + $m[2] * MINUTE_IN_SECONDS + ( isset( $m[3] ) ? (int) $m[3] : 0 );
    }

    /**
     * @param mixed $value
     * @param array $grid
     * @return bool
     */
    protected static function inGrid( $value, array $grid )
    {
        foreach ( isset( $grid['also'] ) ? $grid['also'] : array() as $also ) {
            if ( (string) $also === (string) $value ) {
                return true;
            }
        }
        $s = self::seconds( $value );
        if ( $s === null ) {
            return false;
        }
        $from = self::seconds( $grid['from'] );
        $to   = self::seconds( $grid['to'] );

        return $s >= $from && $s <= $to && ( $s - $from ) % ( $grid['step'] * MINUTE_IN_SECONDS ) === 0;
    }

    /* ---------------------------------------------------------------- resolvers */

    protected static function time_slot_length()
    {
        return Lib\Config::getTimeSlotLengthOptions();
    }

    protected static function time_grid( array $params )
    {
        $step = (int) ( Lib\Config::getTimeSlotLength() / MINUTE_IN_SECONDS );
        $type = isset( $params['type'] ) ? $params['type'] : 'from';
        $last = $type === 'from' ? DAY_IN_SECONDS - $step * MINUTE_IN_SECONDS
            : ( $type === 'break_from' ? 2 * DAY_IN_SECONDS - $step * MINUTE_IN_SECONDS : 2 * DAY_IN_SECONDS );

        return array( 'grid' => array(
            'from' => '00:00:00',
            'to'   => Lib\Utils\DateTime::buildTimeString( $last ),
            'step' => $step,
            'also' => array(),
        ) );
    }

    protected static function min_time_prior()
    {
        return array_merge( array( 0.5 ), range( 1, 12 ), range( 24, 144, 24 ), range( 168, 672, 168 ) );
    }

    protected static function payment_timeout()
    {
        return array_merge(
            range( 5 * MINUTE_IN_SECONDS, 55 * MINUTE_IN_SECONDS, 5 * MINUTE_IN_SECONDS ),
            range( HOUR_IN_SECONDS, 23 * HOUR_IN_SECONDS, HOUR_IN_SECONDS ),
            range( DAY_IN_SECONDS, 7 * DAY_IN_SECONDS, DAY_IN_SECONDS ),
            array( 14 * DAY_IN_SECONDS, 21 * DAY_IN_SECONDS, 28 * DAY_IN_SECONDS )
        );
    }

    protected static function industries()
    {
        $ids = array();
        foreach ( Lib\Utils\Common::getIndustries() as $group ) {
            $ids = array_merge( $ids, array_map( 'strval', array_keys( $group ) ) );
        }

        return $ids;
    }

    protected static function currencies()
    {
        return array_keys( Lib\Utils\Price::getCurrencies() );
    }

    protected static function price_formats()
    {
        return array_values( Lib\Utils\Price::getFormats() );
    }

    protected static function gateways()
    {
        return array_keys( Lib\Utils\Common::getGateways() );
    }

    protected static function payment_types()
    {
        return array_keys( Lib\Utils\Common::getGateways() );
    }

    protected static function wp_roles()
    {
        return array_keys( wp_roles()->get_names() );
    }

    protected static function durations()
    {
        $values = array();
        foreach ( Lib\Utils\Common::getDurationSelectOptions( 0 ) as $option ) {
            if ( $option['value'] ) {
                $values[] = (int) $option['value'];
            }
        }

        return $values;
    }

    protected static function padding()
    {
        return array_merge( array( 0 ), self::durations() );
    }

    protected static function time_zones()
    {
        // The list of the Timezone select: city names, UTC and UTC±N offsets.
        $values = array();
        foreach ( Lib\Utils\DateTime::getTimeZoneOptions() as $group ) {
            $values = array_merge( $values, array_map( 'strval', array_keys( $group ) ) );
        }

        return $values;
    }

    protected static function object_ids( array $params, array $context )
    {
        $entity = isset( $params['entity'] ) ? $params['entity'] : '';
        switch ( $entity ) {
            case 'category':
                return array_map( 'intval', Lib\Entities\Category::query()->fetchCol( 'id' ) );
            case 'staff':
                return array_map( 'intval', Lib\Entities\Staff::query()->fetchCol( 'id' ) );
            case 'service':
                return array_map( 'intval', Lib\Entities\Service::query()->fetchCol( 'id' ) );
            case 'simple_service':
                $query = Lib\Entities\Service::query()->where( 'type', Lib\Entities\Service::TYPE_SIMPLE );
                if ( ! empty( $context['id'] ) ) {
                    $query->whereNot( 'id', $context['id'] );
                }

                return array_map( 'intval', $query->fetchCol( 'id' ) );
            case 'staff_service':
                $types = array( Lib\Entities\Service::TYPE_SIMPLE );
                if ( Lib\Config::packagesActive() ) {
                    $types[] = Lib\Entities\Service::TYPE_PACKAGE;
                }

                return array_map( 'intval', Lib\Entities\Service::query()->whereIn( 'type', $types )->fetchCol( 'id' ) );
            case 'staff_location':
                $ids = array();
                if ( Lib\Config::locationsActive() && ! empty( $context['staff_id'] ) ) {
                    foreach ( Lib\Proxy\Locations::findByStaffId( $context['staff_id'] ) ?: array() as $location ) {
                        $ids[] = (int) $location->getId();
                    }
                }

                return $ids;
            case 'staff_category':
                $ids = array();
                if ( Lib\Config::proActive() ) {
                    foreach ( \Bookly\Backend\Modules\Staff\Proxy\Pro::getCategoriesList() ?: array() as $row ) {
                        $ids[] = (int) $row['id'];
                    }
                }

                return $ids;
            case 'tax':
                $ids = array();
                if ( Lib\Config::taxesActive() ) {
                    foreach ( Lib\Proxy\Taxes::getTaxesList() ?: array() as $tax ) {
                        $ids[] = (int) $tax['id'];
                    }
                }

                return $ids;
            case 'location':
                $ids = array();
                if ( Lib\Config::locationsActive() ) {
                    foreach ( Lib\Proxy\Locations::getAll() ?: array() as $location ) {
                        $ids[] = (int) $location->getId();
                    }
                }

                return $ids;
            case 'wp_user':
                $linked = Lib\Entities\Staff::query()->whereNot( 'wp_user_id', null )->fetchCol( 'wp_user_id' );
                $ids    = array();
                foreach ( get_users( array( 'fields' => 'ID' ) ) as $id ) {
                    if ( ! in_array( $id, $linked ) ) {
                        $ids[] = (int) $id;
                    }
                }

                return $ids;
            case 'wc_product':
                return function_exists( 'wc_get_products' )
                    ? array_map( 'intval', wc_get_products( array( 'limit' => -1, 'return' => 'ids' ) ) )
                    : array();
        }

        return null;
    }
}
