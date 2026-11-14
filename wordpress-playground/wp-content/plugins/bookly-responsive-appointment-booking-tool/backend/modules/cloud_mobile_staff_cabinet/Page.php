<?php
namespace Bookly\Backend\Modules\CloudMobileStaffCabinet;

use Bookly\Lib;
use Bookly\Backend\Components;

class Page extends Lib\Base\Component
{
    /**
     * Render page.
     */
    public static function render()
    {
        $cloud = Lib\Cloud\API::getInstance();
        if ( ! $cloud->account->loadProfile() ) {
            Components\Cloud\LoginRequired\Page::render( __( 'Staff Cabinet Mobile App', 'bookly-responsive-appointment-booking-tool' ), self::pageSlug() );
        } elseif ( $cloud->account->productActive( Lib\Cloud\Account::PRODUCT_MOBILE_STAFF_CABINET ) ) {
            self::enqueueStyles( array(
                'alias'   => array( 'bookly-backend-globals', ),
            ) );

            self::enqueueScripts( array(
                'module'  => array( 'js/staff-cabinet.js' => array( 'bookly-backend-globals', 'bookly-grant-auth-dialog.js' ), ),
            ) );

            $datatables = Lib\Utils\Tables::getSettings( Lib\Utils\Tables::CLOUD_MOBILE_STAFF_CABINET );

            wp_localize_script( 'bookly-staff-cabinet.js', 'BooklyL10n', array(
                'areYouSure'           => __( 'Are you sure?', 'bookly-responsive-appointment-booking-tool' ),
                'new_token'            => __( 'New access token', 'bookly-responsive-appointment-booking-tool' ) . '…',
                'empty_title'          => __( 'No access tokens yet', 'bookly-responsive-appointment-booking-tool' ),
                'empty_text'           => __( 'Create an access token to let a staff member sign in to the mobile app.', 'bookly-responsive-appointment-booking-tool' ),
                'edit'                 => __( 'Edit', 'bookly-responsive-appointment-booking-tool' ) . '…',
                'copy_token'           => __( 'Copy token', 'bookly-responsive-appointment-booking-tool' ),
                'copy_link'            => __( 'Copy link', 'bookly-responsive-appointment-booking-tool' ),
                'copied'               => __( 'Copied to clipboard', 'bookly-responsive-appointment-booking-tool' ),
                'revokeTokensMessage'  => __( 'You are going to delete access token(s). Please note that tokens will be automatically revoked, so user(s) associated with deleted token(s) will lose access', 'bookly-responsive-appointment-booking-tool' ),
                'zeroRecords'          => esc_attr__( 'No matching records found', 'bookly-responsive-appointment-booking-tool' ),
                'staff'                => __( 'Staff', 'bookly-responsive-appointment-booking-tool' ),
                'wp_user'              => __( 'WordPress user', 'bookly-responsive-appointment-booking-tool' ),
                'revoke'               => __( 'Revoke', 'bookly-responsive-appointment-booking-tool' ) . '…',
                'revoke_confirm'       => __( 'Revoke', 'bookly-responsive-appointment-booking-tool' ),
                'cancel'               => __( 'Cancel', 'bookly-responsive-appointment-booking-tool' ),
                'datatables'           => $datatables,
            ) );

            self::renderTemplate( 'index', compact( 'datatables' ) );
        } else {
            wp_redirect( add_query_arg(
                array( 'page' => \Bookly\Backend\Modules\CloudProducts\Page::pageSlug() ),
                admin_url( 'admin.php' ) )
            );
            exit;
        }
    }

    /**
     * Show 'Staff Cabinet' submenu with counter inside Bookly Cloud main menu.
     *
     * @param array $product
     */
    public static function addBooklyCloudMenuItem( $product )
    {
        $title = $product['texts']['title'];

        add_submenu_page(
            'bookly-cloud-menu',
            $title,
            $title,
            Lib\Utils\Common::getRequiredCapability(),
            self::pageSlug(),
            function () {
                \Bookly\Backend\Modules\CloudMobileStaffCabinet\Page::render();
            }
        );
    }
}
