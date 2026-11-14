<?php
namespace Bookly\Backend\Modules\Payments;

use Bookly\Lib;
use Bookly\Backend\Modules\Appointments\Proxy as AppProxy;
use Bookly\Backend\Modules\Payments\Proxy;

class Page extends Lib\Base\Component
{
    /**
     * Render page.
     */
    public static function render()
    {
        self::enqueueStyles( array(
            'alias' => array( 'bookly-backend-globals', ),
        ) );

        self::enqueueScripts( array(
            'module' => array( 'js/payments.js' => array( 'bookly-backend-globals' ) ),
        ) );

        $datatables = Lib\Utils\Tables::getSettings( Lib\Utils\Tables::PAYMENTS );

        $types = Lib\Entities\Payment::getTypes();

        $providers = Lib\Entities\Staff::query()->select( 'id, full_name' )->sortBy( 'full_name' )->whereNot( 'visibility', 'archive' )->fetchArray();
        $services = Lib\Entities\Service::query()->select( 'id, title' )->sortBy( 'title' )->fetchArray();
        // When the customer base is large we don't preload the whole list into the
        // filter dropdown — it switches to remote (typeahead) mode backed by the
        // bookly_get_customers_list AJAX endpoint. Otherwise the static list is used.
        $customers_remote = Lib\Entities\Customer::query()->count() >= Lib\Entities\Customer::REMOTE_LIMIT;
        $customers = $customers_remote
            ? array()
            : array_map( function( $row ) {
                return array(
                    'id' => $row['id'],
                    'full_name' => $row['full_name'],
                );
            }, Lib\Entities\Customer::query( 'c' )->select( 'c.id, c.full_name' )->fetchArray() );

        $type_options = array();
        foreach ( $types as $type ) {
            $type_options[] = array( 'value' => $type, 'label' => Lib\Entities\Payment::typeToString( $type ) );
        }

        $status_options = array();
        foreach ( array(
            Lib\Entities\Payment::STATUS_COMPLETED,
            Lib\Entities\Payment::STATUS_PENDING,
            Lib\Entities\Payment::STATUS_REJECTED,
            Lib\Entities\Payment::STATUS_REFUNDED,
        ) as $st ) {
            $status_options[] = array( 'value' => $st, 'label' => Lib\Entities\Payment::statusToString( $st ) );
        }

        wp_localize_script( 'bookly-payments.js', 'BooklyL10n', array(
            'datePicker' => Lib\Utils\DateTime::datePickerOptions(),
            'dateRange' => Lib\Utils\DateTime::dateRangeOptions( array( 'lastMonth' => __( 'Last month', 'bookly-responsive-appointment-booking-tool' ), 'appAtAnyTime' => __( 'Appointment at any time', 'bookly-responsive-appointment-booking-tool' ), 'anyTime' => __( 'Any time', 'bookly-responsive-appointment-booking-tool' ), ) ),
            'zeroRecords' => __( 'No payments for selected period and criteria.', 'bookly-responsive-appointment-booking-tool' ),
            'total' => __( 'Total', 'bookly-responsive-appointment-booking-tool' ),
            'emptyTable' => __( 'No data available in table', 'bookly-responsive-appointment-booking-tool' ),
            'areYouSure' => __( 'Are you sure?', 'bookly-responsive-appointment-booking-tool' ),
            'no_result_found' => __( 'No results found', 'bookly-responsive-appointment-booking-tool' ),
            'searching' => __( 'Searching', 'bookly-responsive-appointment-booking-tool' ),
            'multiple' => __( 'See details for more items', 'bookly-responsive-appointment-booking-tool' ),
            'delete' => __( 'Delete', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'rowsPerPage' => __( 'Rows per page', 'bookly-responsive-appointment-booking-tool' ),
            'search' => __( 'Quick search by no., customer, provider, service', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'filters' => array(
                'id' => __( 'ID', 'bookly-responsive-appointment-booking-tool' ),
                'date' => __( 'Date', 'bookly-responsive-appointment-booking-tool' ),
                'appointment_date' => __( 'Appointment date', 'bookly-responsive-appointment-booking-tool' ),
                'type' => __( 'Type', 'bookly-responsive-appointment-booking-tool' ),
                'customer' => __( 'Customer', 'bookly-responsive-appointment-booking-tool' ),
                'staff' => __( 'Provider', 'bookly-responsive-appointment-booking-tool' ),
                'service' => __( 'Service', 'bookly-responsive-appointment-booking-tool' ),
                'status' => __( 'Status', 'bookly-responsive-appointment-booking-tool' ),
                'searchPlaceholder' => __( 'Search', 'bookly-responsive-appointment-booking-tool' ),
            ),
            'filterOptions' => array(
                'types' => $type_options,
                'statuses' => $status_options,
                'staff' => $providers,
                'services' => array_map( function ( $s ) { return array( 'id' => $s['id'], 'full_name' => $s['title'] ); }, $services ),
                'customers' => $customers,
                'customersRemote' => $customers_remote,
            ),
            'datatables' => $datatables,
            'invoice' => array(
                'enabled' => (int) Lib\Config::invoicesActive(),
                'button' => __( 'Invoice', 'bookly-responsive-appointment-booking-tool' ),
                'download' => __( 'Download invoices', 'bookly-responsive-appointment-booking-tool' ),
                'downloadAll' => __( 'Download all invoices', 'bookly-responsive-appointment-booking-tool' ),
                'action' => Proxy\Invoices::getDownloadUrl() ?: '',
            ),
            'tasks' => array(
                'enabled' => Lib\Config::tasksActive(),
                'title' => AppProxy\Tasks::getFilterText(),
            ),
        ) );

        self::renderTemplate( 'index', compact( 'datatables' ) );
    }
}