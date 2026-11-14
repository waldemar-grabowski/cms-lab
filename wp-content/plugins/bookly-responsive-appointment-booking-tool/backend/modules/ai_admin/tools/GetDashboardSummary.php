<?php
namespace Bookly\Backend\Modules\AiAdmin\Tools;

use Bookly\Backend\Components\Dashboard\Appointments\Ajax as DashboardAjax;
use Bookly\Backend\Modules\Dashboard\Proxy as DashboardProxy;
use Bookly\Lib;
use Bookly\Lib\AiAdmin\Access;

/**
 * get_dashboard_summary({from, to, based_on, compared_to, staff_ids, service_ids, breakdown}) — the
 * numbers of Bookly > Dashboard counted by the dashboard's own code: KPI for the period and the
 * comparison period and, with breakdown (Pro), the staff × service table. Reads only: the page's
 * filter is not saved and the dashboard's options are not touched.
 */
class GetDashboardSummary extends Tool
{
    const NAME = 'get_dashboard_summary';

    public function getName()
    {
        return self::NAME;
    }

    protected function description()
    {
        return 'The numbers of Bookly > Dashboard for a period, counted as the dashboard counts them.';
    }

    protected function parameters()
    {
        $ints = array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) );

        return array(
            'type'       => 'object',
            'properties' => array(
                'from'        => array( 'type' => 'string', 'description' => 'YYYY-MM-DD' ),
                'to'          => array( 'type' => 'string', 'description' => 'YYYY-MM-DD, inclusive' ),
                'based_on'    => array( 'type' => 'string', 'enum' => array( 'start_date', 'created_at' ) ),
                'compared_to' => array( 'type' => 'string', 'enum' => array( 'previous_period', 'previous_year' ) ),
                'staff_ids'   => $ints,
                'service_ids' => $ints,
                'breakdown'   => array( 'type' => 'boolean' ),
            ),
            'required'   => array( 'from', 'to' ),
        );
    }

    public function execute( array $arguments )
    {
        // The dashboard is an administrator's page only.
        if ( ! Access::allowed( array( 'admin' ) ) ) {
            return self::json( array( 'error' => 'forbidden' ) );
        }
        $from = isset( $arguments['from'] ) ? (string) $arguments['from'] : '';
        $to   = isset( $arguments['to'] ) ? (string) $arguments['to'] : '';
        if ( ! Lib\Utils\DateTime::validateDate( $from ) || ! Lib\Utils\DateTime::validateDate( $to ) ) {
            return self::json( array( 'error' => 'from and to are dates YYYY-MM-DD' ) );
        }
        if ( $to < $from ) {
            return self::json( array( 'error' => 'to is before from' ) );
        }
        $based_on    = isset( $arguments['based_on'] ) && $arguments['based_on'] === 'created_at' ? 'created_at' : 'start_date';
        $compared_to = isset( $arguments['compared_to'] ) && $arguments['compared_to'] === 'previous_year' ? 'previous_year' : 'previous_period';
        $staff       = self::ints( $arguments, 'staff_ids', 1 );
        $services    = self::ints( $arguments, 'service_ids', 0 );
        $range       = $from . ' - ' . $to;

        $kpi    = DashboardAjax::buildKpiData( $range, $based_on, $compared_to, $staff, $services );
        $result = array(
            'period'     => array( 'from' => $from, 'to' => $to ),
            'based_on'   => $based_on,
            'comparison' => array( 'from' => $kpi['comparison']['from'], 'to' => $kpi['comparison']['to'] ),
            'current'    => self::stats( $kpi['current'] ),
            'previous'   => self::stats( $kpi['previous'] ),
        );

        if ( ! empty( $arguments['breakdown'] ) ) {
            if ( ! Lib\Config::proActive() ) {
                $result['unavailable'] = array( 'breakdown' => 'pro' );
            } else {
                $report = DashboardProxy\Pro::getDashboardAnalytics( $range, $based_on, array( 'staff' => $staff, 'services' => $services ) );
                $result += self::breakdown( is_array( $report ) ? $report : array() );
            }
        }

        return self::json( $result );
    }

    /**
     * KPI of one period without the page's links.
     *
     * @param array $stats
     * @return array
     */
    private static function stats( array $stats )
    {
        return array(
            'appointments' => $stats['appointments'],
            'revenue'      => round( (float) $stats['revenue'], 2 ),
            'customers'    => $stats['customers'],
            'sales'        => $stats['sales'],
        );
    }

    /**
     * Rows and totals of the Pro staff × service table: ids instead of the display names' suffix.
     *
     * @param array $report
     * @return array array( 'breakdown', 'breakdown_total' )
     */
    private static function breakdown( array $report )
    {
        $rows      = isset( $report['data'] ) ? $report['data'] : array();
        $staff_ids = array();
        foreach ( $rows as $row ) {
            $staff_ids[] = (int) $row['staff_id'];
        }
        $staff = array();
        if ( $staff_ids ) {
            foreach ( Lib\Entities\Staff::query()->select( 'id, full_name, visibility' )->whereIn( 'id', array_unique( $staff_ids ) )->fetchArray() as $member ) {
                $staff[ (int) $member['id'] ] = $member;
            }
        }
        $breakdown = array();
        foreach ( $rows as $row ) {
            $staff_id    = (int) $row['staff_id'];
            $breakdown[] = array(
                'staff_id'       => $staff_id,
                'staff'          => isset( $staff[ $staff_id ] ) ? $staff[ $staff_id ]['full_name'] : $row['staff'],
                'staff_archived' => isset( $staff[ $staff_id ] ) && $staff[ $staff_id ]['visibility'] === 'archive',
                'service_id'     => $row['service_id'],
                'service'        => $row['service'],
                'appointments'   => $row['appointments'],
                'customers'      => (int) $row['customers']['total'],
                'revenue'        => round( (float) $row['revenue']['total'], 2 ),
            );
        }
        $total = isset( $report['total'] ) ? $report['total'] : array();

        return array(
            'breakdown'       => $breakdown,
            'breakdown_total' => array(
                'revenue'       => isset( $total['revenue']['total'] ) ? round( (float) $total['revenue']['total'], 2 ) : 0.0,
                'customers'     => isset( $total['customers']['total'] ) ? (int) $total['customers']['total'] : 0,
                'sales_revenue' => isset( $total['sales_revenue']['total'] ) ? round( (float) $total['sales_revenue']['total'], 2 ) : 0.0,
                'grand_revenue' => isset( $total['grand_revenue']['total'] ) ? round( (float) $total['grand_revenue']['total'], 2 ) : 0.0,
            ),
        );
    }

    /**
     * Distinct integer ids of an argument, each at least $min (the Pro report puts them into SQL).
     *
     * @param array  $arguments
     * @param string $key
     * @param int    $min
     * @return int[]
     */
    private static function ints( array $arguments, $key, $min )
    {
        if ( ! isset( $arguments[ $key ] ) || ! is_array( $arguments[ $key ] ) ) {
            return array();
        }
        $ids = array();
        foreach ( $arguments[ $key ] as $id ) {
            if ( is_numeric( $id ) && (int) $id >= $min ) {
                $ids[] = (int) $id;
            }
        }

        return array_values( array_unique( $ids ) );
    }
}
