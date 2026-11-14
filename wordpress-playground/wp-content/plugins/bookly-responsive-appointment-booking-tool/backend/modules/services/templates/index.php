<?php defined( 'ABSPATH' ) || exit; // Exit if accessed directly
use Bookly\Backend\Components\Controls;
use Bookly\Backend\Components\PageHeader\Renderer as PageHeaderRenderer;
use Bookly\Backend\Modules\Services\Proxy;
use Bookly\Backend\Components\Dialogs;

/**
 * @var array $categories
 * @var array $datatable
 */
?>
<div id="bookly-tbs" class="wrap bookly-css-root bookly-main-page-wrap">
    <?php PageHeaderRenderer::render( $self::pageSlug(), __( 'Services', 'bookly-responsive-appointment-booking-tool' ) ) ?>
    <div class="bookly:card">
        <div class="bookly:card-body">
            <div id="bookly-services-datatables"></div>
        </div>
    </div>
    <?php Dialogs\Common\CascadeDelete::render() ?>
    <?php Dialogs\Service\Create\Dialog::render() ?>
    <?php Dialogs\Service\Edit\Dialog::render() ?>
    <?php Dialogs\Service\Categories\Dialog::render() ?>
    <?php Proxy\Shared::renderAddOnsComponents() ?>
    <?php Dialogs\TableSettings\Dialog::render() ?>
    <div id="bookly-update-service-settings" class="bookly-modal bookly-fade" tabindex=-1 role="dialog">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><?php esc_attr_e( 'Update service setting', 'bookly-responsive-appointment-booking-tool' ) ?></h5>
                    <button type="button" class="close" data-dismiss="bookly-modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <p><?php esc_html_e( 'You are about to change a service setting which is also configured separately for each staff member. Do you want to update it in staff settings too?', 'bookly-responsive-appointment-booking-tool' ) ?></p>
                    <div class="custom-control custom-checkbox">
                        <input class="custom-control-input" id="bookly-remember-my-choice" type="checkbox"/>
                        <label class="custom-control-label" for="bookly-remember-my-choice"><?php esc_html_e( 'Remember my choice', 'bookly-responsive-appointment-booking-tool' ) ?></label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="reset" class="btn btn-default bookly-no" data-dismiss="bookly-modal" aria-hidden="true">
                        <?php esc_html_e( 'No, update just here in services', 'bookly-responsive-appointment-booking-tool' ) ?>
                    </button>
                    <button type="submit" class="btn btn-success bookly-yes"><?php esc_html_e( 'Yes', 'bookly-responsive-appointment-booking-tool' ) ?></button>
                </div>
            </div>
        </div>
    </div>
</div>