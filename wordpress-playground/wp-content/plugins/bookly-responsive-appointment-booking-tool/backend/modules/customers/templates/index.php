<?php defined( 'ABSPATH' ) || exit; // Exit if accessed directly
use Bookly\Backend\Components\Controls\Buttons;
use Bookly\Backend\Components\Dialogs;
use Bookly\Backend\Components\Notices\PromoBanner\Banner as PromoBanner;
use Bookly\Backend\Components\PageHeader\Renderer as PageHeaderRenderer;
use Bookly\Backend\Modules\Customers\Proxy;
use Bookly\Lib\Utils\Common;
/** @var array $datatable */
?>
<div id="bookly-tbs" class="wrap bookly-css-root bookly-main-page-wrap">
    <?php PageHeaderRenderer::render( $self::pageSlug(), __( 'Customers', 'bookly-responsive-appointment-booking-tool' ) ) ?>
    <div class="bookly:card">
        <div class="bookly:card-body">
            <?php PromoBanner::render( array( PromoBanner::SMS_CAMPAIGNS ) ) ?>
            <div id="bookly-customers-datatables"></div>
        </div>
    </div>
    <div id="bookly-merge-dialog" class="bookly-modal bookly-fade" tabindex=-1 role="dialog">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><?php esc_html_e( 'Merge customers', 'bookly-responsive-appointment-booking-tool' ) ?></h5>
                    <button type="button" class="close" data-dismiss="bookly-modal" aria-label="Close"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="bookly:mb-4">
                    <?php esc_html_e( 'You are about to merge the selected customers. All appointments will be transferred to the chosen customer, and the other customer records will be removed.', 'bookly-responsive-appointment-booking-tool' ) ?>
                    </div>
                    <div class="bookly:mb-2">
                    <?php esc_html_e( 'Please select the customer from the dropdown who will remain after the merge.', 'bookly-responsive-appointment-booking-tool' ) ?>
                    </div>
                    <div id="bookly-merge-customers-target"></div>
                </div>
                <div class="modal-footer">
                    <?php Buttons::render( 'bookly-merge', 'btn-danger', __( 'Merge', 'bookly-responsive-appointment-booking-tool' ), array(), '<span class="ladda-label"><i class="fas fa-fw fa-road mr-1"></i>{caption}</span>' ) ?>
                    <?php Buttons::renderCancel() ?>
                </div>
            </div>
        </div>
    </div>
    <?php Proxy\Pro::renderImportDialog() ?>
    <?php Proxy\Pro::renderExportDialog( $datatable['settings'], $datatable['titles'] ) ?>
    <?php Dialogs\Customer\Delete\Dialog::render() ?>
    <?php Dialogs\TableSettings\Dialog::render() ?>
    <?php Dialogs\Customer\Edit\Dialog::render() ?>
</div>