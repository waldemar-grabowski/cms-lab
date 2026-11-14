<?php
namespace Bookly\Backend\Modules\Services;

use Bookly\Lib;

class Page extends Lib\Base\Ajax
{
    /**
     * Render page.
     */
    public static function render()
    {
        wp_enqueue_media();
        self::enqueueStyles( array(
            'wp' => array( 'wp-color-picker' ),
            'alias' => array( 'bookly-backend-globals' ),
        ) );

        self::enqueueScripts( array(
            'wp' => array( 'wp-color-picker' ),
            'backend' => array(
                'js/range-tools.js' => array( 'bookly-backend-globals' ),
                'js/sortable.min.js',
            ),
            'module' => array( 'js/services-list.js' => array( 'bookly-range-tools.js' ) ),
        ) );

        $categories = Lib\Entities\Category::query()->sortBy( 'position' )->fetchArray();
        foreach ( $categories as &$category ) {
            $category['attachment'] = Lib\Utils\Common::getAttachmentUrl( $category['attachment_id'], 'thumbnail' ) ?: null;
        }

        $datatables = Lib\Utils\Tables::getSettings( Lib\Utils\Tables::SERVICES );

        // Full simple-service list (position order) for the reorder dialog. The
        // server-side table can't supply the whole list, so it's localized here.
        $service_order = array();
        $service_order_rows = Lib\Entities\Service::query( 's' )
            ->select( 'id, title' )
            ->whereIn( 's.type', array_keys( Proxy\Shared::prepareServiceTypes( array( Lib\Entities\Service::TYPE_SIMPLE => Lib\Entities\Service::TYPE_SIMPLE ) ) ) )
            ->sortBy( 'position' )
            ->fetchArray();
        foreach ( $service_order_rows as $row ) {
            $service_order[] = array( 'id' => (int) $row['id'], 'title' => $row['title'] );
        }

        wp_localize_script( 'bookly-services-list.js', 'BooklyL10n', array(
            'are_you_sure' => esc_attr__( 'Are you sure?', 'bookly-responsive-appointment-booking-tool' ),
            'appointmentsUrl' => Lib\Utils\Common::escAdminUrl( \Bookly\Backend\Modules\Appointments\Ajax::pageSlug() ),
            'private_warning' => esc_attr__( 'The service will be created with the visibility of Private.', 'bookly-responsive-appointment-booking-tool' ),
            'edit' => esc_attr__( 'Edit', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'duplicate' => esc_attr__( 'Duplicate', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'reorder' => esc_attr_x( 'Reorder', 'order of elements', 'bookly-responsive-appointment-booking-tool' ),
            'categories' => $categories,
            'uncategorized' => esc_attr__( 'Uncategorized', 'bookly-responsive-appointment-booking-tool' ),
            'noResultFound' => esc_attr__( 'No results found', 'bookly-responsive-appointment-booking-tool' ),
            'zeroRecords' => __( 'No matching records found', 'bookly-responsive-appointment-booking-tool' ),
            'processing' => esc_attr__( 'Processing', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'emptyTable' => __( 'No data available in table', 'bookly-responsive-appointment-booking-tool' ),
            'show_type' => count( Proxy\Shared::prepareServiceTypes( array() ) ) > 0,
            'new_service' => __( 'New service', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'empty_title' => __( 'No services yet', 'bookly-responsive-appointment-booking-tool' ),
            'empty_text' => __( 'Create your first service to start taking online bookings.', 'bookly-responsive-appointment-booking-tool' ),
            'search' => __( 'Quick search by title, category', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'delete' => __( 'Delete', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'order' => _x( 'Order', 'drag to reorder', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'service_order' => $service_order,
            'service_order_title' => __( 'Services order', 'bookly-responsive-appointment-booking-tool' ),
            'service_order_hint' => __( 'Adjust the order of services in your booking form', 'bookly-responsive-appointment-booking-tool' ),
            'tags' => __( 'Tags', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'manage_categories' => __( 'Categories', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'filters' => array(
                'category' => __( 'Category', 'bookly-responsive-appointment-booking-tool' ),
                'searchPlaceholder' => __( 'Search', 'bookly-responsive-appointment-booking-tool' ) . '…',
            ),
            'rowsPerPage' => __( 'Rows per page', 'bookly-responsive-appointment-booking-tool' ),
            'proEnabled' => Lib\Config::proActive(),
            'datatables' => $datatables,
        ) );

        $data['categories'] = $categories;
        $data['datatable'] = $datatables[ Lib\Utils\Tables::SERVICES ];

        self::renderTemplate( 'index', $data );
    }

    /**
     * Get data for staff drop-down.
     *
     * @return array
     */
    public static function getStaffDropDownData()
    {
        if ( Lib\Config::proActive() ) {
            return Lib\Proxy\Pro::getStaffDataForDropDown();
        } else {
            $items = Lib\Entities\Staff::query()
                ->select( 'id, full_name' )
                ->whereNot( 'visibility', 'archive' )
                ->sortBy( 'position' )
                ->fetchArray();

            return array(
                0 => array(
                    'name' => '',
                    'items' => $items,
                ),
            );
        }
    }
}