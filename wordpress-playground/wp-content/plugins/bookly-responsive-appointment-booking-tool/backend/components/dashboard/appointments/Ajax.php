<?php
namespace Bookly\Backend\Components\Dashboard\Appointments;

use Bookly\Lib;
use Bookly\Backend\Modules;
use Bookly\Backend\Components\Dashboard\Proxy;

class Ajax extends Lib\Base\Ajax
{
    /**
     * Dashboard-page KPI + trend chart endpoint. The page requests it in parallel with
     * getDashboardAnalytics, so the above-the-fold sections paint without waiting for
     * the heaviest one. The native WP dashboard widget keeps using the lightweight
     * chart-only endpoint (getAppointmentsDataForDashboard).
     */
    public static function getDashboardData()
    {
        $range       = self::parameter( 'range' );
        $based_on    = self::parameter( 'based_on' ) === 'start_date' ? 'start_date' : 'created_at';
        $compared_to = self::parameter( 'compared_to' ) === 'previous_year' ? 'previous_year' : 'previous_period';
        $filter      = self::parameter( 'filter', array() );
        $staff       = isset( $filter['staff'] ) ? $filter['staff'] : array();
        $services    = isset( $filter['services'] ) ? $filter['services'] : array();

        // Persist the filter bar state once for the whole page (dedicated core 'dashboard'
        // key — works in free, never touches the analytics table's own settings). Only
        // this endpoint saves it — the analytics one is read-only.
        Lib\Utils\Tables::updateSettings( 'dashboard', null, null, array(
            'range'       => $range,
            'based_on'    => $based_on,
            'compared_to' => $compared_to,
            'staff'       => array_map( 'intval', (array) $staff ),
            'services'    => array_map( 'intval', (array) $services ),
        ) );

        wp_send_json_success( array(
            'kpi'   => self::buildKpiData( $range, $based_on, $compared_to, $staff, $services ),
            'chart' => self::buildChartData( $range, $based_on, $staff, $services ),
        ) );
    }

    /**
     * Detailed report (Pro analytics) for the dashboard page — requested in parallel
     * with getDashboardData so the report neither delays the first paint nor takes
     * the KPI / chart down with it if it fails on a heavy dataset.
     */
    public static function getDashboardAnalytics()
    {
        $range    = self::parameter( 'range' );
        $based_on = self::parameter( 'based_on' ) === 'start_date' ? 'start_date' : 'created_at';
        $filter   = self::parameter( 'filter', array() );

        wp_send_json_success( Modules\Dashboard\Proxy\Pro::getDashboardAnalytics( $range, $based_on, $filter ) );
    }

    public static function getAppointmentsDataForDashboard()
    {
        $filter = self::parameter( 'filter', array() );
        wp_send_json_success( self::buildChartData(
            self::parameter( 'range' ),
            self::parameter( 'based_on', 'created_at' ),
            isset( $filter['staff'] ) ? $filter['staff'] : array(),
            isset( $filter['services'] ) ? $filter['services'] : array()
        ) );
    }

    /**
     * Build the trend-chart payload (totals + per-day series + deep links). Shared by the
     * widget endpoint and the combined dashboard endpoint (getDashboardData).
     */
    public static function buildChartData( $range, $based_on = 'created_at', $staff = array(), $services = array() )
    {
        /** @global \wpdb $wpdb */
        global $wpdb;

        list ( $start, $end ) = explode( ' - ', $range );
        $start = date_create( $start );
        $end = date_create( $end );
        $from_s = $start->format( 'Y-m-d H:i:s' );
        $to_s = ( clone $end )->modify( '+1 day' )->format( 'Y-m-d H:i:s' );
        // Sales series are added per active add-on only. A key that is absent from the
        // payload means "this install cannot sell that at all" and the chart draws no
        // series for it; a key present but zero for every day means "nothing sold in
        // this period", which is a different statement and must still be drawn.
        $sales_series = self::salesSeriesData( $based_on, $from_s, $to_s, $staff, $services );

        $day = array(
            'total' => 0,
            'revenue' => 0,
        );
        $totals = array(
            'approved' => 0,
            'pending' => 0,
            'total' => 0,
            'revenue' => 0,
        );
        foreach ( array_keys( $sales_series ) as $sales_key ) {
            $day[ $sales_key ] = 0;
            $totals[ $sales_key ] = 0;
        }

        $data = array(
            'totals' => $totals,
            'filters' => array(
                'created_at' => array(
                    // The "approved" figure counts done appointments too (done ≈ approved),
                    // so its deep link must open the same set.
                    'approved' => sprintf( '%s#created-date=%s-%s&appointment-date=any&status=%s', Lib\Utils\Common::escAdminUrl( Modules\Appointments\Page::pageSlug() ), $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ), 'approved,done' ),
                    'pending' => sprintf( '%s#created-date=%s-%s&appointment-date=any&status=%s', Lib\Utils\Common::escAdminUrl( Modules\Appointments\Page::pageSlug() ), $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ), 'pending' ),
                    'total' => sprintf( '%s#created-date=%s-%s&appointment-date=any&status=any', Lib\Utils\Common::escAdminUrl( Modules\Appointments\Page::pageSlug() ), $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ) ),
                    'revenue' => sprintf( '%s#created-date=%s-%s&appointment-date=any', Lib\Utils\Common::escAdminUrl( Modules\Payments\Page::pageSlug() ), $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ) ),
                ),
                'start_date' => array(
                    'approved' => sprintf( '%s#created-date=any&appointment-date=%s-%s&status=%s', Lib\Utils\Common::escAdminUrl( Modules\Appointments\Page::pageSlug() ), $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ), 'approved,done' ),
                    'pending' => sprintf( '%s#created-date=any&appointment-date=%s-%s&status=%s', Lib\Utils\Common::escAdminUrl( Modules\Appointments\Page::pageSlug() ), $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ), 'pending' ),
                    'total' => sprintf( '%s#created-date=any&appointment-date=%s-%s&status=any', Lib\Utils\Common::escAdminUrl( Modules\Appointments\Page::pageSlug() ), $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ) ),
                    'revenue' => sprintf( '%s#created-date=any&appointment-date=%s-%s', Lib\Utils\Common::escAdminUrl( Modules\Payments\Page::pageSlug() ), $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ) ),
                ),
            ),
            'days' => array(),
            'labels' => array(),
        );
        $end->modify( '+1 day' );
        $period = new \DatePeriod( $start, \DateInterval::createFromDateString( '1 day' ), $end );
        /** @var \DateTime $dt */
        foreach ( $period as $dt ) {
            $data['labels'][] = date_i18n( 'M j', $dt->getTimestamp() );
            $data['days'][ $dt->format( 'Y-m-d' ) ] = $day;
        }

        $date_col = $based_on === 'start_date' ? 'a.start_date' : 'ca.created_at';
        update_option( 'bookly_dashboard_based_on_appointment', $based_on === 'start_date' ? 'start_date' : 'created_at' );

        // Appointment counts: grouped by day and status, the result is bounded by the
        // period length regardless of how many appointments the install holds.
        $query = Lib\Entities\CustomerAppointment::query( 'ca' )
            ->select( sprintf( 'COUNT(1) AS quantity, ca.status, DATE(%s) AS group_date', $date_col ) )
            ->leftJoin( 'Appointment', 'a', 'a.id = ca.appointment_id' )
            ->whereBetween( $date_col, $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ) )
            ->groupBy( sprintf( 'DATE(%s), ca.status', $date_col ) );

        self::applyStaffServiceFilter( $query, $staff, $services );

        $custom_statuses = Lib\Proxy\CustomStatuses::getAll() ?: array();
        foreach ( $query->fetchArray() as $record ) {
            $quantity = $record['quantity'];
            $status = $record['status'] === Lib\Entities\CustomerAppointment::STATUS_DONE
                ? Lib\Entities\CustomerAppointment::STATUS_APPROVED // done ≈ approved, same as the KPI cards
                : $record['status'];
            if ( array_key_exists( $status, $data['totals'] ) ) {
                $data['totals'][ $status ] += $quantity;
            } elseif ( isset ( $custom_statuses[ $status ] ) && $custom_statuses[ $status ]->getBusy() ) {
                // Consider as APPROVED.
                $data['totals']['approved'] += $quantity;
            }
            $data['totals']['total'] += $quantity;
            $data['days'][ $record['group_date'] ]['total'] += $quantity;
        }

        // Revenue per day. Both queries are collapsed to one row per day in SQL: the
        // chart only ever draws days, while the number of payments in the period is
        // unbounded, so summing them in PHP made the response grow with the size of
        // the install instead of with the length of the period.
        $appointments = self::appointmentRevenueQuery( $date_col, $from_s, $to_s, $staff, $services );
        $per_day = $wpdb->get_results( 'SELECT group_date, SUM(amount) AS revenue FROM (' . $appointments->composeQuery() . ') t GROUP BY group_date', ARRAY_A );

        $sales = self::standaloneSalesQuery( $from_s, $to_s, $staff, $services );
        if ( $sales ) {
            $per_day = array_merge( $per_day, $sales->fetchArray() );
        }

        foreach ( $per_day as $record ) {
            $data['totals']['revenue'] += $record['revenue'];
            if ( isset( $data['days'][ $record['group_date'] ] ) ) {
                $data['days'][ $record['group_date'] ]['revenue'] += $record['revenue'];
            }
        }
        $data['totals']['revenue'] = Lib\Utils\Price::format( $data['totals']['revenue'] );

        // Sold tickets / packages / gift cards per day — one grouped count each, bounded
        // by the length of the period the same way the appointment counts are.
        foreach ( $sales_series as $sales_key => $sold ) {
            foreach ( $sold as $record ) {
                $data['totals'][ $sales_key ] += (int) $record['quantity'];
                if ( isset( $data['days'][ $record['group_date'] ] ) ) {
                    $data['days'][ $record['group_date'] ][ $sales_key ] += (int) $record['quantity'];
                }
            }
        }

        return $data;
    }

    /**
     * Build the KPI payload (revenue, appointments by status, new/returning customers
     * for the current range AND the equal-length previous period, for deltas). Consumed
     * by the combined dashboard endpoint (getDashboardData).
     */
    public static function buildKpiData( $range, $based_on = 'created_at', $compared_to = 'previous_period', $staff = array(), $services = array() )
    {
        list ( $start, $end ) = array_pad( explode( ' - ', $range ), 2, null );
        $start = date_create( $start ?: 'today' );
        $end   = date_create( $end ?: 'today' );
        $length = (int) $start->diff( $end )->days + 1; // inclusive day count

        // Current: [start 00:00, end+1day 00:00).
        $cur_from = clone $start;
        $cur_to   = ( clone $end )->modify( '+1 day' );
        // Previous baseline depends on the chosen "compared to" mode.
        if ( $compared_to === 'previous_year' ) {
            $prev_from = ( clone $start )->modify( '-1 year' );
            $prev_to   = ( clone $cur_to )->modify( '-1 year' );
        } else {
            $prev_to   = clone $start;
            $prev_from = ( clone $start )->modify( sprintf( '-%d day', $length ) );
        }
        $prev_end = ( clone $prev_to )->modify( '-1 day' ); // inclusive end

        // Deep links to the Appointments page with the period + status preset (same hash
        // contract the page parses: created-date / appointment-date / comma-separated status).
        $appts_url = Lib\Utils\Common::escAdminUrl( Modules\Appointments\Page::pageSlug() );
        $s = $start->format( 'Y-m-d' );
        $e = $end->format( 'Y-m-d' );
        $date_hash = $based_on === 'start_date'
            ? sprintf( 'created-date=any&appointment-date=%s-%s', $s, $e )
            : sprintf( 'created-date=%s-%s&appointment-date=any', $s, $e );

        return array(
            'current'    => self::computeStats( $cur_from, $cur_to, $based_on, $staff, $services ),
            'previous'   => self::computeStats( $prev_from, $prev_to, $based_on, $staff, $services ),
            'comparison' => array(
                'from'  => $prev_from->format( 'Y-m-d' ),
                'to'    => $prev_end->format( 'Y-m-d' ),
                'label' => Lib\Utils\DateTime::formatDate( $prev_from->format( 'Y-m-d' ) ) . ' – ' . Lib\Utils\DateTime::formatDate( $prev_end->format( 'Y-m-d' ) ),
            ),
            'links'      => array(
                'revenue'      => sprintf( '%s#%s', Lib\Utils\Common::escAdminUrl( Modules\Payments\Page::pageSlug() ), $date_hash ),
                'appointments' => sprintf( '%s#%s&status=any', $appts_url, $date_hash ),
                'newCustomers' => Lib\Utils\Common::escAdminUrl( Modules\Customers\Page::pageSlug() ),
                'pending'      => sprintf( '%s#%s&status=pending', $appts_url, $date_hash ),
                'lost'         => sprintf( '%s#%s&status=cancelled,rejected', $appts_url, $date_hash ),
            ),
        );
    }

    /**
     * Apply the global staff / service filter to a CustomerAppointment query (alias ca,
     * joined Appointment alias a). Empty / 'all' means no restriction.
     *
     * @param Lib\Query $query
     * @param mixed     $staff    'all' | int[]
     * @param array     $services int[] (0 = "Custom" = NULL service)
     */
    private static function applyStaffServiceFilter( $query, $staff, $services )
    {
        if ( $staff !== 'all' && is_array( $staff ) && $staff ) {
            $query->whereIn( 'a.staff_id', array_map( 'intval', $staff ) );
        }
        if ( is_array( $services ) && $services ) {
            $ints = array_map( 'intval', $services );
            $ids = array_values( array_filter( $ints, function ( $x ) { return $x > 0; } ) );
            $has_custom = in_array( 0, $ints, true );
            if ( $has_custom && $ids ) {
                $query->whereRaw( '(a.service_id IS NULL OR a.service_id IN (' . implode( ',', $ids ) . '))', array() );
            } elseif ( $has_custom ) {
                $query->whereRaw( 'a.service_id IS NULL', array() );
            } elseif ( $ids ) {
                $query->whereIn( 'a.service_id', $ids );
            }
        }
    }

    /**
     * Compute KPI aggregates for a half-open period [$from, $to).
     *
     * @param \DateTime $from
     * @param \DateTime $to
     * @param string    $based_on 'start_date' | 'created_at'
     * @param mixed     $staff
     * @param array     $services
     * @return array
     */
    private static function computeStats( \DateTime $from, \DateTime $to, $based_on, $staff = array(), $services = array() )
    {
        $date_col = $based_on === 'start_date' ? 'a.start_date' : 'ca.created_at';
        $from_s = $from->format( 'Y-m-d H:i:s' );
        $to_s   = $to->format( 'Y-m-d H:i:s' );

        // Appointment counts: one grouped query, the result is bounded by the number of
        // statuses regardless of how many appointments the install holds.
        $query = Lib\Entities\CustomerAppointment::query( 'ca' )
            ->select( 'ca.status, COUNT(1) AS quantity' )
            ->leftJoin( 'Appointment', 'a', 'a.id = ca.appointment_id' )
            ->whereGte( $date_col, $from_s )
            ->whereLt( $date_col, $to_s )
            ->groupBy( 'ca.status' );
        self::applyStaffServiceFilter( $query, $staff, $services );

        $custom_statuses = Lib\Proxy\CustomStatuses::getAll() ?: array();
        $appointments = array(
            'total' => 0, 'approved' => 0, 'pending' => 0,
            'cancelled' => 0, 'rejected' => 0, 'waitlisted' => 0,
        );
        foreach ( $query->fetchArray() as $row ) {
            $quantity = (int) $row['quantity'];
            switch ( $row['status'] ) {
                case Lib\Entities\CustomerAppointment::STATUS_PENDING:
                    $appointments['pending'] += $quantity;
                    break;
                case Lib\Entities\CustomerAppointment::STATUS_APPROVED:
                case Lib\Entities\CustomerAppointment::STATUS_DONE: // done ≈ approved (optional/automated status)
                    $appointments['approved'] += $quantity;
                    break;
                case Lib\Entities\CustomerAppointment::STATUS_CANCELLED:
                    $appointments['cancelled'] += $quantity;
                    break;
                case Lib\Entities\CustomerAppointment::STATUS_REJECTED:
                    $appointments['rejected'] += $quantity;
                    break;
                case Lib\Entities\CustomerAppointment::STATUS_WAITLISTED:
                    $appointments['waitlisted'] += $quantity;
                    break;
                default:
                    if ( isset( $custom_statuses[ $row['status'] ] ) ) {
                        if ( $custom_statuses[ $row['status'] ]->getBusy() ) {
                            $appointments['approved'] += $quantity;
                        } else {
                            $appointments['cancelled'] += $quantity;
                        }
                    }
            }
            $appointments['total'] += $quantity;
        }

        list ( $customers_total, $customers_new ) = self::countCustomers( $from_s, $to_s, $based_on, $staff, $services );

        return array(
            'revenue'      => self::sumPaymentsOnce( $date_col, $from_s, $to_s, $staff, $services ),
            'appointments' => $appointments,
            'customers'    => array(
                'total'     => $customers_total,
                'new'       => $customers_new,
                'returning' => max( 0, $customers_total - $customers_new ),
            ),
            // Absent (null) when the install sells none of these — the KPI row then keeps
            // its three cards instead of showing a fourth one stuck at zero.
            'sales'        => self::salesTotals( $based_on, $from_s, $to_s, $staff, $services ),
        );
    }

    /**
     * Sum payments for the period, each payment counted once (a payment may cover
     * several appointments). Aggregated in SQL — no rows are fetched into PHP.
     *
     * @param string $date_col
     * @param string $from_s
     * @param string $to_s
     * @param mixed  $staff
     * @param array  $services
     * @return float
     */
    private static function sumPaymentsOnce( $date_col, $from_s, $to_s, $staff = array(), $services = array() )
    {
        /** @global \wpdb $wpdb */
        global $wpdb;

        $appointments = self::appointmentRevenueQuery( $date_col, $from_s, $to_s, $staff, $services );
        $total = (float) $wpdb->get_var( 'SELECT SUM(amount) FROM (' . $appointments->composeQuery() . ') t' );

        $sales = self::standaloneSalesQuery( $from_s, $to_s, $staff, $services );
        if ( $sales ) {
            $total += (float) $wpdb->get_var( 'SELECT SUM(revenue) FROM (' . $sales->composeQuery() . ') t' );
        }

        return $total;
    }

    /**
     * Sold items per day for every sale kind this install can report on, in chart
     * order: key => rows of quantity / group_date. Each kind is asked from its own
     * add-on via proxy, so this class never touches add-on tables itself: null
     * (the add-on is not installed, the proxy has no provider) drops the key from
     * the payload, while an empty set means "nothing sold" or "the active filter
     * excludes this kind" — a series that must still be drawn, flat at zero.
     *
     * @param string $based_on
     * @param string $from_s
     * @param string $to_s
     * @param mixed  $staff
     * @param array  $services
     * @return array key => array of [ quantity, group_date ]
     */
    private static function salesSeriesData( $based_on, $from_s, $to_s, $staff, $services )
    {
        $series = array();
        $sold = Proxy\Shared::getTicketSales( $based_on, $from_s, $to_s, $staff, $services );
        if ( $sold !== null ) {
            $series['tickets'] = $sold;
        }
        $sold = Proxy\Shared::getPackageSales( $from_s, $to_s, $staff, $services );
        if ( $sold !== null ) {
            $series['packages'] = $sold;
        }
        $sold = Proxy\Shared::getGiftCardSales( $from_s, $to_s, $staff, $services );
        if ( $sold !== null ) {
            $series['gift_cards'] = $sold;
        }

        return $series;
    }

    /**
     * Money received in the period for orders with no appointment behind them.
     *
     * Exposed for the Pro analytics report: that table breaks appointments down by staff
     * and service, so package / gift card / ticket sales have no row to live in and its
     * total is bound to fall short of the dashboard Revenue. Reporting this figure next
     * to the table turns that gap from an unexplained discrepancy into the bridge between
     * the two numbers.
     *
     * @param string $from_s
     * @param string $to_s
     * @param mixed  $staff
     * @param array  $services
     * @return float
     */
    public static function standaloneSalesRevenue( $from_s, $to_s, $staff = array(), $services = array() )
    {
        /** @global \wpdb $wpdb */
        global $wpdb;

        $query = self::standaloneSalesQuery( $from_s, $to_s, $staff, $services );

        return $query
            ? (float) $wpdb->get_var( 'SELECT SUM(revenue) FROM (' . $query->composeQuery() . ') t' )
            : 0.0;
    }

    /**
     * Items sold in the period per kind, plus their sum — the figures behind the Sales
     * KPI card. Counts only: the gross value of what was sold is not a slice of Revenue
     * (coupons, deposits and gift cards paid with another gift card all break that), and
     * putting a money figure next to Revenue that cannot be reconciled with it would be
     * worse than showing none.
     *
     * @return array|null null when the install sells none of these
     */
    private static function salesTotals( $based_on, $from_s, $to_s, $staff, $services )
    {
        $series = self::salesSeriesData( $based_on, $from_s, $to_s, $staff, $services );
        if ( ! $series ) {
            return null;
        }

        $totals = array( 'total' => 0 );
        foreach ( $series as $key => $sold ) {
            $totals[ $key ] = 0;
            // Already grouped by day and bounded by the period — summing the handful of
            // rows here is cheaper than a second aggregate round-trip.
            foreach ( $sold as $record ) {
                $totals[ $key ] += (int) $record['quantity'];
            }
            $totals['total'] += $totals[ $key ];
        }

        return $totals;
    }

    /**
     * Revenue of appointment-backed orders, one row per payment: amount, group_date.
     *
     * Two rules, both taken from the Payments page (Modules\Payments\Ajax) so the
     * dashboard and the payments list cannot disagree about the same money:
     * only parent payments take part, and each one is worth paid + child_paid.
     * A balance top-up made through the checkout form is a separate payment with
     * parent_id set; it carries no items and no customer_appointments row of its own,
     * and its amount is added to the parent once the gateway confirms it. Counting
     * children as well would double the top-up, dropping them without adding
     * child_paid would lose it.
     *
     * Driven from customer_appointments on purpose: that is the side the period
     * filter applies to, and starting from payments instead makes the whole payments
     * table the driving set.
     *
     * @param string $date_col 'a.start_date' | 'ca.created_at'
     * @param string $from_s
     * @param string $to_s
     * @param mixed  $staff
     * @param array  $services
     * @return Lib\Query
     */
    private static function appointmentRevenueQuery( $date_col, $from_s, $to_s, $staff = array(), $services = array() )
    {
        $query = Lib\Entities\CustomerAppointment::query( 'ca' )
            ->select( sprintf( '(p.paid + p.child_paid) AS amount, MIN(DATE(%s)) AS group_date', $date_col ) )
            ->innerJoin( 'Payment', 'p', 'p.id = ca.payment_id' )
            ->leftJoin( 'Appointment', 'a', 'a.id = ca.appointment_id' )
            ->where( 'p.parent_id', null )
            ->whereGte( $date_col, $from_s )
            ->whereLt( $date_col, $to_s )
            ->groupBy( 'p.id' );

        self::applyStaffServiceFilter( $query, $staff, $services );

        return $query;
    }

    /**
     * Revenue of orders with no appointment at all — packages, gift cards, event
     * tickets. Those are paid for without ever creating a customer_appointments row,
     * so the appointment-driven query above cannot see them and the dashboard used to
     * leave that money out of Revenue entirely.
     *
     * Already aggregated per day: one row per payment exists by construction, so
     * there is no need for the two-level grouping the appointment query requires.
     * Such a sale has no appointment date, so it is placed by its own created_at
     * under both "based on" modes.
     *
     * Kept as a separate query rather than folded into the one above with an OR:
     * the OR makes the payments table the driving set and roughly doubles the cost
     * of the whole revenue calculation, while two focused queries each keep their
     * own access path.
     *
     * @param string $from_s
     * @param string $to_s
     * @param mixed  $staff
     * @param array  $services
     * @return Lib\Query|null null when an active filter cannot match such a sale
     */
    private static function standaloneSalesQuery( $from_s, $to_s, $staff = array(), $services = array() )
    {
        $by_staff = $staff !== 'all' && is_array( $staff ) && $staff;
        $filtered = $by_staff || ( is_array( $services ) && $services );

        $query = Lib\Entities\Payment::query( 'p' )
            ->select( 'DATE(p.created_at) AS group_date, SUM(p.paid + p.child_paid) AS revenue' )
            ->where( 'p.parent_id', null )
            ->whereGte( 'p.created_at', $from_s )
            ->whereLt( 'p.created_at', $to_s )
            ->whereRaw( sprintf( 'NOT EXISTS (SELECT 1 FROM %s ca WHERE ca.payment_id = p.id)', Lib\Entities\CustomerAppointment::getTableName() ), array() )
            ->groupBy( 'DATE(p.created_at)' );

        if ( $filtered ) {
            // Only packages carry a staff member and a service of their own. Gift cards
            // and event tickets carry neither, so any active filter excludes them —
            // the same thing the payments list does, and the two must agree. The
            // condition itself is the Packages add-on's business: nothing back from
            // the proxy means no standalone sale can match on this install.
            $constraint = Proxy\Shared::getStandaloneSalesConstraint( $staff, $services );
            if ( ! $constraint ) {
                return null;
            }
            $query->whereRaw( $constraint, array() );
        }

        return $query;
    }

    /**
     * Distinct customers in the period and how many of them are new
     * (their first-ever appointment falls inside the period).
     *
     * @param string $from_s
     * @param string $to_s
     * @param string $based_on
     * @return array [ total, new ]
     */
    private static function countCustomers( $from_s, $to_s, $based_on, $staff = array(), $services = array() )
    {
        /** @global \wpdb $wpdb */
        global $wpdb;

        $col = $based_on === 'start_date' ? 'a.start_date' : 'ca.created_at';

        // Distinct customers with an appointment in the period — a single aggregate value,
        // no per-customer rows are fetched into PHP.
        $total_q = Lib\Entities\CustomerAppointment::query( 'ca' )
            ->select( 'COUNT(DISTINCT ca.customer_id) AS total' )
            ->leftJoin( 'Appointment', 'a', 'a.id = ca.appointment_id' )
            ->whereGte( $col, $from_s )
            ->whereLt( $col, $to_s );
        self::applyStaffServiceFilter( $total_q, $staff, $services );
        $total_rows = $total_q->fetchArray();
        $total = (int) $total_rows[0]['total'];

        // Of those, customers whose first (matching) appointment falls inside the period (= new).
        // No WHERE on the date — MIN() must see the customer's whole matching history.
        // The grouped result is collapsed to a single count in SQL.
        $new_q = Lib\Entities\CustomerAppointment::query( 'ca' )
            ->select( 'ca.customer_id' )
            ->leftJoin( 'Appointment', 'a', 'a.id = ca.appointment_id' )
            ->groupBy( 'ca.customer_id' )
            ->havingRaw( sprintf( 'MIN(%1$s) >= %%s AND MIN(%1$s) < %%s', $col ), array( $from_s, $to_s ) );
        self::applyStaffServiceFilter( $new_q, $staff, $services );
        $new = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM (' . $new_q->composeQuery() . ') t' );

        return array( $total, $new );
    }
}