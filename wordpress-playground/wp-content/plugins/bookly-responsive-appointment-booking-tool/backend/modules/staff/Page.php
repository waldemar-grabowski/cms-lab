<?php
namespace Bookly\Backend\Modules\Staff;

use Bookly\Lib;

class Page extends Lib\Base\Component
{
    /**
     * Render page.
     */
    public static function render()
    {
        self::enqueueStyles( array(
            'backend' => array( 'css/fontawesome-all.min.css' => array( 'bookly-backend-globals' ) ),
        ) );

        self::enqueueScripts( array(
            'module' => array( 'js/staff-list.js' => array( 'bookly-backend-globals' ) ),
            'backend' => array( 'js/nav-scrollable.js' => array( 'bookly-backend-globals' ) ),
            'frontend' => array( 'js/intlTelInput.min.js' => array( 'jquery' ) ),
        ) );

        // Allow add-ons to enqueue their assets.
        Proxy\Shared::enqueueStaffProfileStyles();
        Proxy\Shared::enqueueStaffProfileScripts();
        $errors = Proxy\Shared::prepareCalendarErrors( array(), self::parameters() );

        $categories = Proxy\Pro::getCategoriesList() ?: array();
        foreach ( $categories as &$category ) {
            $category['attachment'] = Lib\Utils\Common::getAttachmentUrl( $category['attachment_id'], 'thumbnail' ) ?: null;
        }

        $datatables = Lib\Utils\Tables::getSettings( Lib\Utils\Tables::STAFF_MEMBERS );

        // Full staff list (position order) for the reorder dialog. Server-side
        // table pagination means the dialog can't reuse the loaded rows, so the
        // complete collection is localized here.
        global $wpdb;
        $staff_order_query = Lib\Entities\Staff::query( 's' )
            ->select( 's.id, s.full_name, s.visibility = \'archive\' AS archived' )
            ->tableJoin( $wpdb->users, 'wpu', 'wpu.ID = s.wp_user_id' );
        if ( ! Lib\Utils\Common::isCurrentUserAdmin() ) {
            $staff_order_query->where( 's.wp_user_id', get_current_user_id() );
        }
        $staff_order = array();
        foreach ( $staff_order_query->sortBy( 'position' )->fetchArray() as $_staff ) {
            $staff_order[] = array(
                'id' => (int) $_staff['id'],
                'full_name' => $_staff['full_name'],
                'archived' => (int) $_staff['archived'],
            );
        }

        wp_localize_script( 'bookly-staff-list.js', 'BooklyL10n', array(
            'proRequired' => (int) ! Lib\Config::proActive(),
            'appointmentsUrl' => Lib\Utils\Common::escAdminUrl( \Bookly\Backend\Modules\Appointments\Ajax::pageSlug() ),
            'areYouSure' => esc_attr__( 'Are you sure?', 'bookly-responsive-appointment-booking-tool' ),
            'categories' => $categories,
            'uncategorized' => esc_attr__( 'Uncategorized', 'bookly-responsive-appointment-booking-tool' ),
            'edit' => esc_attr__( 'Edit', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'reorder' => esc_attr_x( 'Reorder', 'order of elements', 'bookly-responsive-appointment-booking-tool' ),
            'noResultFound' => esc_attr__( 'No results found', 'bookly-responsive-appointment-booking-tool' ),
            'zeroRecords' => __( 'No matching records found', 'bookly-responsive-appointment-booking-tool' ),
            'processing' => esc_attr__( 'Processing', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'emptyTable' => __( 'No data available in table', 'bookly-responsive-appointment-booking-tool' ),
            'errors' => $errors,
            'new_staff' => __( 'New staff member', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'empty_title' => __( 'No staff members yet', 'bookly-responsive-appointment-booking-tool' ),
            'empty_text' => __( 'Add the people who provide your services so customers can book them.', 'bookly-responsive-appointment-booking-tool' ),
            'manage_categories' => __( 'Categories', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'duplicate' => __( 'Duplicate', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'search' => __( 'Quick search by name, email, phone', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'order' => _x( 'Order', 'drag to reorder', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'staff_order' => $staff_order,
            'staff_order_title' => __( 'Staff members order', 'bookly-responsive-appointment-booking-tool' ),
            'staff_order_hint' => __( 'Adjust the order of staff members in your booking form', 'bookly-responsive-appointment-booking-tool' ),
            'archived' => __( 'Archived', 'bookly-responsive-appointment-booking-tool' ),
            'delete' => __( 'Delete', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'filters' => array(
                'show_archived' => __( 'Show archived', 'bookly-responsive-appointment-booking-tool' ),
                'visibility' => __( 'Visibility', 'bookly-responsive-appointment-booking-tool' ),
                'category' => __( 'Category', 'bookly-responsive-appointment-booking-tool' ),
            ),
            'visibility_public' => __( 'Public', 'bookly-responsive-appointment-booking-tool' ),
            'visibility_private' => __( 'Private', 'bookly-responsive-appointment-booking-tool' ),
            'rowsPerPage' => __( 'Rows per page', 'bookly-responsive-appointment-booking-tool' ),
            'datatables' => $datatables,
            'proEnabled' => Lib\Config::proActive(),
            'isAdmin' => Lib\Utils\Common::isCurrentUserAdmin(),
        ) );

        self::renderTemplate( 'index', compact( 'categories', 'datatables' ) );
    }
}