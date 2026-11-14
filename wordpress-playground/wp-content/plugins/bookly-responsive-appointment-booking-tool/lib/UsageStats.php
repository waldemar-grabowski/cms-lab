<?php
namespace Bookly\Lib;

use Bookly\Lib\Entities\UsageStat;

/**
 * Usage statistics: counters of what admins do in Bookly (banner clicks, setup wizard steps and so on).
 * Counters accumulate always and are sent to Cloud only when the site allows it:
 * Pro is active or "Collect stats" is enabled.
 */
abstract class UsageStats
{
    /**
     * Known events: name => allowed properties and whether the browser may record the event.
     * Values of properties are short identifiers or numbers, never user input.
     *
     * @var array
     */
    protected static $events = array(
        'promo_banner.shown' => array( 'props' => array( 'banner', 'page', 'preset' ), 'browser' => false ),
        'promo_banner.click' => array( 'props' => array( 'banner', 'page', 'preset', 'button' ), 'browser' => true ),
        'promo_banner.dismiss' => array( 'props' => array( 'banner', 'page', 'times' ), 'browser' => false ),
        'setup.opened' => array( 'props' => array( 'variant' ), 'browser' => false ),
        'setup.started' => array( 'props' => array( 'variant' ), 'browser' => true ),
        'setup.step' => array( 'props' => array( 'variant', 'step' ), 'browser' => true ),
        'setup.back' => array( 'props' => array( 'variant', 'step' ), 'browser' => true ),
        'setup.skip' => array( 'props' => array( 'variant', 'step' ), 'browser' => true ),
        'setup.finished' => array( 'props' => array( 'variant', 'services', 'staff', 'sample' ), 'browser' => false ),
        'setup.cloud' => array( 'props' => array( 'variant', 'action' ), 'browser' => true ),
        'setup.done' => array( 'props' => array( 'variant', 'action' ), 'browser' => true ),
    );

    /**
     * Add to the counter of the event.
     *
     * @param string $name
     * @param array $props
     * @param bool $from_browser
     * @return bool
     */
    public static function track( $name, array $props = array(), $from_browser = false )
    {
        /** @global \wpdb $wpdb */
        global $wpdb;

        if ( ! isset( self::$events[ $name ] ) || ( $from_browser && ! self::$events[ $name ]['browser'] ) ) {
            return false;
        }

        $clean = array();
        foreach ( self::$events[ $name ]['props'] as $key ) {
            if ( isset( $props[ $key ] ) && is_scalar( $props[ $key ] ) ) {
                $value = is_bool( $props[ $key ] ) ? (string) (int) $props[ $key ] : (string) $props[ $key ];
                if ( preg_match( '/^[A-Za-z0-9_.\-]{1,64}$/', $value ) ) {
                    $clean[ $key ] = $value;
                }
            }
        }
        ksort( $clean );
        $json = $clean ? json_encode( $clean ) : '{}';

        return (bool) $wpdb->query( $wpdb->prepare(
            'INSERT INTO `' . UsageStat::getTableName() . '` (`name`, `props`, `props_hash`, `count`) VALUES (%s, %s, %s, 1)
            ON DUPLICATE KEY UPDATE `count` = `count` + 1',
            $name,
            $json,
            md5( $json )
        ) );
    }

    /**
     * Whether the site allows sending statistics.
     *
     * @return bool
     */
    public static function sendingAllowed()
    {
        return Config::proActive() || get_option( 'bookly_gen_collect_stats' );
    }

    /**
     * Send the accumulated counters to Cloud and subtract what was accepted.
     */
    public static function send()
    {
        /** @global \wpdb $wpdb */
        global $wpdb;

        if ( ! self::sendingAllowed() ) {
            return;
        }

        $rows = UsageStat::query()
            ->select( '`id`, `name`, `props`, `count`' )
            ->whereGt( 'count', 0 )
            ->sortBy( 'id' )
            ->fetchArray();
        if ( ! $rows ) {
            return;
        }
        $events = array();
        foreach ( $rows as $row ) {
            $events[] = array(
                'name' => $row['name'],
                'props' => json_decode( $row['props'], true ) ?: (object) array(),
                'count' => (int) $row['count'],
            );
        }
        if ( ! Cloud\API::getInstance()->general->sendUsageStats( self::context(), $events ) ) {
            return;
        }
        // Subtract rather than delete: events tracked while the request was running stay counted.
        $table = UsageStat::getTableName();
        foreach ( $rows as $row ) {
            $wpdb->query( $wpdb->prepare( 'UPDATE `' . $table . '` SET `count` = `count` - %d WHERE `id` = %d', $row['count'], $row['id'] ) );
        }
        UsageStat::query()->delete()->where( 'count', 0 )->execute();
    }

    /**
     * Facts about the site sent once with every request.
     *
     * @return array
     */
    protected static function context()
    {
        $wizard = get_option( 'bookly_setup_wizard' );

        return array(
            'pro' => (bool) Config::proActive(),
            'cloud' => (bool) Cloud\API::getInstance()->getToken(),
            'setup_variant' => is_array( $wizard ) && isset( $wizard['variant'] ) ? $wizard['variant'] : null,
            'setup_assigned_at' => is_array( $wizard ) && isset( $wizard['assigned_at'] ) ? $wizard['assigned_at'] : null,
        );
    }
}
