<?php
namespace Bookly\Lib\Mailing;

use Bookly\Lib;
use Bookly\Lib\Entities;

/**
 * Who an SMS campaign goes to.
 *
 * A preset audience is a rule with limits the business can change; it is resolved
 * again at the moment of sending. A custom audience is a filter whose result is saved
 * as a fixed list. Recipients are phone numbers: customers without a phone are left
 * out and a number shared by several customers is sent once.
 */
abstract class Audience
{
    const TYPE_PRESET = 'preset';
    const TYPE_CUSTOM = 'custom';

    const PRESET_LAPSED = 'lapsed';
    const PRESET_ONCE = 'once';
    const PRESET_CANCELLED = 'cancelled';
    const PRESET_REGULAR = 'regular';
    const PRESET_TOP = 'top';
    const PRESET_BIRTHDAY = 'birthday';
    const PRESET_BOOKED = 'booked';
    const PRESET_ALL = 'all';

    /**
     * Presets with their default limits, in the order they are offered.
     *
     * @return array
     */
    public static function presets()
    {
        $tomorrow = date_create( current_time( 'Y-m-d' ) )->modify( '+1 day' );
        $week = clone $tomorrow;
        $week->modify( '+6 days' );

        return array(
            self::PRESET_LAPSED => array( 'min_visits' => 2, 'from' => 90, 'to' => 365 ),
            self::PRESET_ONCE => array( 'from' => 30, 'to' => 180 ),
            self::PRESET_CANCELLED => array( 'from' => 2, 'to' => 60 ),
            self::PRESET_REGULAR => array( 'min_visits' => 3, 'intervals' => 2, 'from' => 30, 'to' => 365 ),
            self::PRESET_TOP => array( 'percent' => 10, 'months' => 12, 'recent' => 180 ),
            self::PRESET_BIRTHDAY => array( 'days' => 7 ),
            self::PRESET_BOOKED => array( 'from' => $tomorrow->format( 'Y-m-d' ), 'to' => $week->format( 'Y-m-d' ), 'staff_id' => 0 ),
            self::PRESET_ALL => array(),
        );
    }

    /**
     * Allowed range of each numeric limit.
     *
     * @return array
     */
    protected static function limits()
    {
        return array(
            'min_visits' => array( 1, 50 ),
            'from' => array( 0, 3650 ),
            'to' => array( 0, 3650 ),
            'intervals' => array( 1, 10 ),
            'percent' => array( 1, 100 ),
            'months' => array( 1, 36 ),
            'recent' => array( 1, 3650 ),
            'days' => array( 0, 60 ),
            'staff_id' => array( 0, PHP_INT_MAX ),
        );
    }

    /**
     * Bring an audience from a request or from the database to a known shape.
     *
     * @param array|string|null $audience
     * @return array|null
     */
    public static function normalize( $audience )
    {
        if ( is_string( $audience ) ) {
            $audience = json_decode( $audience, true );
        }
        if ( ! is_array( $audience ) || ! isset( $audience['type'] ) ) {
            return null;
        }
        if ( $audience['type'] === self::TYPE_PRESET ) {
            $presets = self::presets();
            if ( ! isset( $audience['key'], $presets[ $audience['key'] ] ) ) {
                return null;
            }
            $params = isset( $audience['params'] ) && is_array( $audience['params'] ) ? $audience['params'] : array();
            $result = array();
            $limits = self::limits();
            foreach ( $presets[ $audience['key'] ] as $name => $default ) {
                $value = isset( $params[ $name ] ) ? $params[ $name ] : $default;
                if ( $audience['key'] === self::PRESET_BOOKED && ( $name === 'from' || $name === 'to' ) ) {
                    $result[ $name ] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : $default;
                } else {
                    $result[ $name ] = min( $limits[ $name ][1], max( $limits[ $name ][0], (int) $value ) );
                }
            }

            return array(
                'type' => self::TYPE_PRESET,
                'key' => $audience['key'],
                'params' => $result,
                // Changes made by hand on top of the rule: people added to it and phones left out of it.
                'add' => isset( $audience['add'] ) ? self::people( $audience['add'] ) : array(),
                'exclude' => isset( $audience['exclude'] ) ? self::phones( $audience['exclude'] ) : array(),
            );
        }
        if ( $audience['type'] === self::TYPE_CUSTOM ) {
            $filter = isset( $audience['filter'] ) && is_array( $audience['filter'] ) ? $audience['filter'] : array();

            return array(
                'type' => self::TYPE_CUSTOM,
                'filter' => array(
                    'sum_of_payments' => isset( $filter['sum_of_payments'] ) ? max( 0, (float) $filter['sum_of_payments'] ) : 0,
                    'count_of_appointments' => isset( $filter['count_of_appointments'] ) ? max( 0, (int) $filter['count_of_appointments'] ) : 0,
                    'services' => isset( $filter['services'] ) ? self::ids( $filter['services'], true ) : array(),
                    'staff' => isset( $filter['staff'] ) ? self::ids( $filter['staff'] ) : array(),
                    'last_appointment' => isset( $filter['last_appointment'] ) ? (int) $filter['last_appointment'] : 0,
                ),
            );
        }

        return null;
    }

    /**
     * Recipients of a preset audience, or of the filter of a custom one.
     *
     * @param array $audience Normalized audience.
     * @return array List of array( 'name' => ..., 'phone' => ... ).
     */
    public static function recipients( array $audience )
    {
        if ( $audience['type'] === self::TYPE_PRESET ) {
            return self::adjust(
                self::phonesOf( self::presetCustomers( $audience['key'], $audience['params'] ) ),
                isset( $audience['add'] ) ? $audience['add'] : array(),
                isset( $audience['exclude'] ) ? $audience['exclude'] : array()
            );
        }

        return self::phonesOf( self::filterCustomers( $audience['filter'] ) );
    }

    /**
     * Recipients with people added and phones left out by hand. A phone is sent to once.
     *
     * @param array $recipients List of array( 'name' => ..., 'phone' => ... ).
     * @param array $add Same shape.
     * @param string[] $exclude
     * @return array
     */
    public static function adjust( array $recipients, array $add, array $exclude )
    {
        $skip = array_flip( $exclude );
        $result = array();
        foreach ( array_merge( $recipients, $add ) as $recipient ) {
            if ( ! isset( $skip[ $recipient['phone'] ] ) ) {
                $skip[ $recipient['phone'] ] = true;
                $result[] = $recipient;
            }
        }

        return $result;
    }

    /**
     * People from a request: a name and a phone each, without empty phones.
     *
     * @param mixed $people
     * @return array
     */
    public static function people( $people )
    {
        $result = array();
        foreach ( (array) $people as $person ) {
            $phone = isset( $person['phone'] ) ? self::phone( $person['phone'] ) : '';
            if ( $phone !== '' ) {
                $result[] = array( 'name' => isset( $person['name'] ) ? sanitize_text_field( $person['name'] ) : '', 'phone' => $phone );
            }
        }

        return $result;
    }

    /**
     * Phones as they came: they are compared with the phones of customers as stored.
     *
     * @param mixed $phones
     * @return string[]
     */
    public static function phones( $phones )
    {
        return array_values( array_filter( array_map( 'trim', array_map( 'strval', (array) $phones ) ) ) );
    }

    /**
     * A phone as it is stored and compared: digits and a leading plus.
     *
     * @param string $phone
     * @return string
     */
    public static function phone( $phone )
    {
        return preg_replace( '/[^\d+]/', '', (string) $phone );
    }

    /**
     * Number of recipients of every preset with its default limits.
     *
     * @return array preset key => number of recipients
     */
    public static function presetCounts()
    {
        $counts = array();
        foreach ( self::presets() as $key => $params ) {
            $counts[ $key ] = count( self::phonesOf( self::presetCustomers( $key, $params ) ) );
        }

        return $counts;
    }

    /**
     * Customers matching a preset. Only customers with a phone are considered.
     *
     * @param string $key
     * @param array $p Limits.
     * @return int[]
     */
    protected static function presetCustomers( $key, array $p )
    {
        global $wpdb;

        $c = Entities\Customer::getTableName();
        $ca = Entities\CustomerAppointment::getTableName();
        $a = Entities\Appointment::getTableName();
        $now = current_time( 'mysql' );
        $visits = self::visitsSql();
        $no_future = 'c.id NOT IN (' . self::futureSql() . ')';
        $phone = 'c.phone <> \'\'';

        switch ( $key ) {
            case self::PRESET_LAPSED:
                return $wpdb->get_col( $wpdb->prepare(
                    "SELECT c.id FROM `$c` c JOIN ($visits) v ON v.customer_id = c.id
                      WHERE $phone AND v.visits >= %d AND DATE(v.last_visit) BETWEEN %s AND %s AND $no_future",
                    $p['min_visits'], self::daysAgo( $p['to'] ), self::daysAgo( $p['from'] )
                ) );

            case self::PRESET_ONCE:
                return $wpdb->get_col( $wpdb->prepare(
                    "SELECT c.id FROM `$c` c JOIN ($visits) v ON v.customer_id = c.id
                      WHERE $phone AND v.visits = 1 AND DATE(v.last_visit) BETWEEN %s AND %s AND $no_future",
                    self::daysAgo( $p['to'] ), self::daysAgo( $p['from'] )
                ) );

            case self::PRESET_CANCELLED:
                // The cancellation is the customer's latest one, and since then there was
                // neither a new booking nor a visit (a visit may have been booked earlier).
                $active = self::statusList( self::activeStatuses() );
                $visit = self::statusList( self::visitStatuses() );

                return $wpdb->get_col( $wpdb->prepare(
                    "SELECT DISTINCT c.id FROM `$c` c
                       JOIN `$ca` x ON x.customer_id = c.id AND x.status = %s AND DATE(x.status_changed_at) BETWEEN %s AND %s
                      WHERE $phone
                        AND NOT EXISTS (SELECT 1 FROM `$ca` n WHERE n.customer_id = c.id AND n.status = x.status AND n.status_changed_at > x.status_changed_at)
                        AND NOT EXISTS (SELECT 1 FROM `$ca` n WHERE n.customer_id = c.id AND n.status IN ($active) AND n.created_at > x.status_changed_at)
                        AND NOT EXISTS (SELECT 1 FROM `$ca` n JOIN `$a` na ON na.id = n.appointment_id
                                         WHERE n.customer_id = c.id AND n.status IN ($visit) AND na.start_date > x.status_changed_at AND na.start_date < %s)
                        AND $no_future",
                    Entities\CustomerAppointment::STATUS_CANCELLED, self::daysAgo( $p['to'] ), self::daysAgo( $p['from'] ), $now
                ) );

            case self::PRESET_REGULAR:
                $ids = $wpdb->get_col( $wpdb->prepare(
                    "SELECT c.id FROM `$c` c JOIN ($visits) v ON v.customer_id = c.id
                      WHERE $phone AND v.visits >= %d AND DATE(v.last_visit) BETWEEN %s AND %s AND $no_future",
                    $p['min_visits'], self::daysAgo( $p['to'] ), self::daysAgo( $p['from'] )
                ) );

                return self::awayLongerThanUsual( $ids, $p['intervals'] );

            case self::PRESET_TOP:
                return self::topSpenders( $p['percent'], $p['months'], $p['recent'] );

            case self::PRESET_BIRTHDAY:
                $days = array();
                $date = date_create( current_time( 'Y-m-d' ) );
                for ( $i = 0; $i <= $p['days']; $i++ ) {
                    $days[] = $date->format( 'm-d' );
                    // Born on February 29: greeted on the 28th in a common year.
                    if ( $date->format( 'm-d' ) === '02-28' && $date->format( 'L' ) === '0' ) {
                        $days[] = '02-29';
                    }
                    $date->modify( '+1 day' );
                }

                return $wpdb->get_col(
                    "SELECT c.id FROM `$c` c WHERE $phone AND c.birthday IS NOT NULL
                        AND DATE_FORMAT(c.birthday, '%m-%d') IN ('" . implode( "','", $days ) . "')"
                );

            case self::PRESET_BOOKED:
                $future = self::statusList( self::futureStatuses() );
                $query = "SELECT DISTINCT c.id FROM `$c` c
                       JOIN `$ca` ca ON ca.customer_id = c.id AND ca.status IN ($future)
                       JOIN `$a` a ON a.id = ca.appointment_id
                      WHERE $phone AND a.start_date >= %s AND a.start_date < %s";
                $args = array( $p['from'] . ' 00:00:00', date_create( $p['to'] )->modify( '+1 day' )->format( 'Y-m-d 00:00:00' ) );
                if ( $p['staff_id'] ) {
                    $query .= ' AND a.staff_id = %d';
                    $args[] = $p['staff_id'];
                }

                return $wpdb->get_col( $wpdb->prepare( $query, $args ) );

            case self::PRESET_ALL:
                return $wpdb->get_col( "SELECT c.id FROM `$c` c WHERE $phone" );
        }

        return array();
    }

    /**
     * Customers of a custom audience filter. An empty condition does not narrow the result.
     *
     * @param array $f Normalized filter.
     * @return int[]
     */
    protected static function filterCustomers( array $f )
    {
        global $wpdb;

        $c = Entities\Customer::getTableName();
        $ca = Entities\CustomerAppointment::getTableName();
        $a = Entities\Appointment::getTableName();
        $active = self::statusList( self::activeStatuses() );

        $join = '';
        $where = array( 'c.phone <> \'\'' );
        if ( $f['count_of_appointments'] > 0 ) {
            $join .= ' JOIN (' . self::visitsSql() . ') v ON v.customer_id = c.id';
            $where[] = 'v.visits >= ' . (int) $f['count_of_appointments'];
        }
        if ( $f['sum_of_payments'] > 0 ) {
            $join .= ' JOIN (' . self::paidSql( null ) . ') s ON s.customer_id = c.id';
            $where[] = $wpdb->prepare( 's.paid >= %f', $f['sum_of_payments'] );
        }
        if ( $f['services'] || $f['staff'] ) {
            $conditions = array();
            if ( $f['services'] ) {
                $services = array();
                foreach ( $f['services'] as $id ) {
                    $services[] = $id === 'custom' ? 'a.service_id IS NULL' : 'a.service_id = ' . (int) $id;
                }
                $conditions[] = '(' . implode( ' OR ', $services ) . ')';
            }
            if ( $f['staff'] ) {
                $conditions[] = 'a.staff_id IN (' . implode( ',', array_map( 'intval', $f['staff'] ) ) . ')';
            }
            $where[] = "c.id IN (SELECT ca.customer_id FROM `$ca` ca JOIN `$a` a ON a.id = ca.appointment_id
                                  WHERE ca.status IN ($active) AND " . implode( ' AND ', $conditions ) . ')';
        }
        if ( $f['last_appointment'] !== 0 ) {
            // Latest booking of the customer, past or upcoming, on or after the chosen day.
            $day = date_create( current_time( 'Y-m-d' ) )->modify( sprintf( '%+d days', $f['last_appointment'] ) )->format( 'Y-m-d' );
            $where[] = $wpdb->prepare(
                "c.id IN (SELECT ca.customer_id FROM `$ca` ca JOIN `$a` a ON a.id = ca.appointment_id
                           WHERE ca.status IN ($active) GROUP BY ca.customer_id HAVING DATE(MAX(a.start_date)) >= %s)",
                $day
            );
        }

        return $wpdb->get_col( "SELECT c.id FROM `$c` c$join WHERE " . implode( ' AND ', $where ) );
    }

    /**
     * Customers who stay away longer than their own rhythm: the time since the last
     * visit is more than the given number of median gaps between their visits.
     *
     * @param int[] $ids
     * @param int $intervals
     * @return int[]
     */
    protected static function awayLongerThanUsual( array $ids, $intervals )
    {
        global $wpdb;

        if ( ! $ids ) {
            return array();
        }
        $ca = Entities\CustomerAppointment::getTableName();
        $a = Entities\Appointment::getTableName();
        $visit = self::statusList( self::visitStatuses() );
        $now = current_time( 'mysql' );
        $today = strtotime( current_time( 'Y-m-d' ) );

        $days = array();
        foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT DISTINCT ca.customer_id, DATE(a.start_date) AS d FROM `$ca` ca JOIN `$a` a ON a.id = ca.appointment_id
                  WHERE ca.status IN ($visit) AND a.start_date < %s AND ca.customer_id IN (" . implode( ',', array_map( 'intval', $chunk ) ) . ')
                  ORDER BY ca.customer_id, d',
                $now
            ), ARRAY_A );
            foreach ( $rows as $row ) {
                $days[ $row['customer_id'] ][] = strtotime( $row['d'] );
            }
        }

        $result = array();
        foreach ( $days as $customer_id => $list ) {
            $gaps = array();
            for ( $i = 1, $n = count( $list ); $i < $n; $i++ ) {
                $gaps[] = ( $list[ $i ] - $list[ $i - 1 ] ) / DAY_IN_SECONDS;
            }
            if ( ! $gaps ) {
                continue;
            }
            sort( $gaps );
            $m = count( $gaps );
            $median = $m % 2 ? $gaps[ ( $m - 1 ) / 2 ] : ( $gaps[ $m / 2 - 1 ] + $gaps[ $m / 2 ] ) / 2;
            if ( ( $today - end( $list ) ) / DAY_IN_SECONDS > $intervals * $median ) {
                $result[] = $customer_id;
            }
        }

        return $result;
    }

    /**
     * The given share of paying customers with the largest payments over a period,
     * who also visited recently.
     *
     * @param int $percent
     * @param int $months
     * @param int $recent Days since the last visit, at most.
     * @return int[]
     */
    protected static function topSpenders( $percent, $months, $recent )
    {
        global $wpdb;

        $c = Entities\Customer::getTableName();
        $since = date_create( current_time( 'Y-m-d' ) )->modify( sprintf( '-%d months', $months ) )->format( 'Y-m-d' );
        $ranked = $wpdb->get_col( 'SELECT s.customer_id FROM (' . self::paidSql( $since ) . ') s WHERE s.paid > 0 ORDER BY s.paid DESC' );
        $top = array_slice( $ranked, 0, max( 1, (int) ceil( count( $ranked ) * $percent / 100 ) ) );
        if ( ! $top ) {
            return array();
        }

        return $wpdb->get_col( $wpdb->prepare(
            "SELECT c.id FROM `$c` c JOIN (" . self::visitsSql() . ") v ON v.customer_id = c.id
              WHERE c.phone <> '' AND DATE(v.last_visit) >= %s AND c.id IN (" . implode( ',', array_map( 'intval', $top ) ) . ')',
            self::daysAgo( $recent )
        ) );
    }

    /**
     * Names and phones of customers, one entry per phone number.
     *
     * @param int[] $ids
     * @return array
     */
    protected static function phonesOf( array $ids )
    {
        global $wpdb;

        $result = array();
        $seen = array();
        $c = Entities\Customer::getTableName();
        foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
            $rows = $wpdb->get_results(
                "SELECT full_name, phone FROM `$c` WHERE phone <> '' AND id IN (" . implode( ',', array_map( 'intval', $chunk ) ) . ') ORDER BY full_name',
                ARRAY_A
            );
            foreach ( $rows as $row ) {
                if ( ! isset( $seen[ $row['phone'] ] ) ) {
                    $seen[ $row['phone'] ] = true;
                    $result[] = array( 'name' => $row['full_name'], 'phone' => $row['phone'] );
                }
            }
        }

        return $result;
    }

    /**
     * Past visits per customer: number of days with a visit and the last visit.
     *
     * @return string
     */
    protected static function visitsSql()
    {
        global $wpdb;

        $ca = Entities\CustomerAppointment::getTableName();
        $a = Entities\Appointment::getTableName();

        return $wpdb->prepare(
            "SELECT ca.customer_id, COUNT(DISTINCT DATE(a.start_date)) AS visits, MAX(a.start_date) AS last_visit
               FROM `$ca` ca JOIN `$a` a ON a.id = ca.appointment_id
              WHERE ca.status IN (" . self::statusList( self::visitStatuses() ) . ') AND a.start_date < %s
              GROUP BY ca.customer_id',
            current_time( 'mysql' )
        );
    }

    /**
     * Customers with an upcoming booking.
     *
     * @return string
     */
    protected static function futureSql()
    {
        global $wpdb;

        $ca = Entities\CustomerAppointment::getTableName();
        $a = Entities\Appointment::getTableName();

        return $wpdb->prepare(
            "SELECT ca.customer_id FROM `$ca` ca JOIN `$a` a ON a.id = ca.appointment_id
              WHERE ca.status IN (" . self::statusList( self::futureStatuses() ) . ') AND a.start_date >= %s',
            current_time( 'mysql' )
        );
    }

    /**
     * Amount paid per customer, optionally since a day. A payment without its own
     * customer is attributed through the booking it pays for.
     *
     * @param string|null $since Y-m-d
     * @return string
     */
    protected static function paidSql( $since )
    {
        global $wpdb;

        $p = Entities\Payment::getTableName();
        $ca = Entities\CustomerAppointment::getTableName();
        $where = $wpdb->prepare( 'p.status = %s', Entities\Payment::STATUS_COMPLETED );
        if ( $since ) {
            $where .= $wpdb->prepare( ' AND p.created_at >= %s', $since );
        }

        return "SELECT t.customer_id, SUM(t.paid) AS paid FROM (
                    SELECT COALESCE(p.customer_id, (SELECT ca.customer_id FROM `$ca` ca WHERE ca.payment_id = p.id LIMIT 1)) AS customer_id,
                           p.paid + p.child_paid AS paid
                      FROM `$p` p WHERE $where
                ) t WHERE t.customer_id IS NOT NULL GROUP BY t.customer_id";
    }

    /**
     * Statuses of a booking that took place, when it is in the past.
     *
     * @return array
     */
    protected static function visitStatuses()
    {
        return Lib\Proxy\CustomStatuses::prepareBusyStatuses( array(
            Entities\CustomerAppointment::STATUS_APPROVED,
            Entities\CustomerAppointment::STATUS_DONE,
        ) );
    }

    /**
     * Statuses of a booking that is still going to happen.
     *
     * @return array
     */
    protected static function futureStatuses()
    {
        return Lib\Proxy\CustomStatuses::prepareBusyStatuses( array(
            Entities\CustomerAppointment::STATUS_PENDING,
            Entities\CustomerAppointment::STATUS_APPROVED,
        ) );
    }

    /**
     * Statuses of a booking that occupies the time: upcoming or taken place.
     *
     * @return array
     */
    protected static function activeStatuses()
    {
        return array_unique( array_merge( self::visitStatuses(), self::futureStatuses() ) );
    }

    /**
     * @param array $statuses
     * @return string Quoted list for an SQL IN clause.
     */
    protected static function statusList( array $statuses )
    {
        return "'" . implode( "','", array_map( 'esc_sql', $statuses ) ) . "'";
    }

    /**
     * @param int $days
     * @return string Y-m-d of the day that many days before today.
     */
    protected static function daysAgo( $days )
    {
        return date_create( current_time( 'Y-m-d' ) )->modify( sprintf( '-%d days', $days ) )->format( 'Y-m-d' );
    }

    /**
     * @param mixed $ids
     * @param bool $allow_custom Keep the 'custom' entry that stands for bookings without a service.
     * @return array
     */
    protected static function ids( $ids, $allow_custom = false )
    {
        $result = array();
        foreach ( (array) $ids as $id ) {
            if ( $allow_custom && $id === 'custom' ) {
                $result[] = 'custom';
            } elseif ( (int) $id > 0 ) {
                $result[] = (int) $id;
            }
        }

        return array_values( array_unique( $result ) );
    }
}
