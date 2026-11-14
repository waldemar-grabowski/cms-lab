<?php
namespace Bookly\Backend\Modules\Customers;

use Bookly\Lib;

class Page extends Lib\Base\Component
{
    /**
     * Render page.
     */
    public static function render()
    {
        if ( self::hasParameter( 'import-customers' ) ) {
            Proxy\Pro::importCustomers();
        }

        self::enqueueStyles( array(
            'alias' => array( 'bookly-backend-globals', ),
        ) );

        self::enqueueScripts( array(
            'module' => array( 'js/customers.js' => array( 'bookly-backend-globals' ), ),
            'frontend' => array( 'js/intlTelInput.min.js' => array( 'bookly-backend-globals' ) ),
        ) );

        $datatables = Lib\Utils\Tables::getSettings( Lib\Utils\Tables::CUSTOMERS );

        wp_localize_script( 'bookly-customers.js', 'BooklyL10n', array(
            'infoFields' => Lib\Proxy\CustomerInformation::getFieldsWhichMayHaveData() ?: array(),
            'tagsData' => Lib\Proxy\Pro::getTagsData( 'customer' ) ?: array(),
            'edit' => __( 'Edit', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'are_you_sure' => __( 'Are you sure?', 'bookly-responsive-appointment-booking-tool' ),
            'wp_users' => get_users( array( 'fields' => array( 'ID', 'display_name' ), 'orderby' => 'display_name' ) ),
            'zeroRecords' => __( 'No customers found.', 'bookly-responsive-appointment-booking-tool' ),
            'processing' => __( 'Processing', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'emptyTable' => __( 'No data available in table', 'bookly-responsive-appointment-booking-tool' ),
            'edit_customer' => __( 'Edit customer', 'bookly-responsive-appointment-booking-tool' ),
            'new_customer' => __( 'New customer', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'empty_title' => __( 'No customers yet', 'bookly-responsive-appointment-booking-tool' ),
            'empty_text' => __( 'Customers who book online will appear here automatically. You can also add them manually.', 'bookly-responsive-appointment-booking-tool' ),
            'create_customer' => __( 'Create customer', 'bookly-responsive-appointment-booking-tool' ),
            'save' => __( 'Save', 'bookly-responsive-appointment-booking-tool' ),
            'search' => __( 'Quick search by name, email, phone', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'download' => __( 'Download', 'bookly-responsive-appointment-booking-tool' ),
            'delete' => __( 'Delete', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'rowsPerPage' => __( 'Rows per page', 'bookly-responsive-appointment-booking-tool' ),
            'merge' => __( 'Merge', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'export' => __( 'Export', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'import' => __( 'Import', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'datatables' => $datatables,
            'proEnabled' => Lib\Config::proActive(),
        ) );

        self::renderTemplate( 'index', array( 'datatable' => $datatables[ Lib\Utils\Tables::CUSTOMERS ] ) );
    }
}