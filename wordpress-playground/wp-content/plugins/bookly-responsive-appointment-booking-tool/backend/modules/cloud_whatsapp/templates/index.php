<?php defined( 'ABSPATH' ) || exit; // Exit if accessed directly
use Bookly\Backend\Components\PageHeader\Renderer as PageHeaderRenderer;
use Bookly\Backend\Components\Dialogs;
use Bookly\Backend\Components\Dialogs\TableSettings;
use Bookly\Backend\Components\Controls\Buttons;
/**
 * @var \Bookly\Lib\Cloud\WhatsApp $whatsapp
 */
?>
<div id="bookly-tbs" class="wrap bookly-css-root bookly-main-page-wrap">
    <?php PageHeaderRenderer::render( $self::pageSlug(), __( 'WhatsApp Notifications', 'bookly-responsive-appointment-booking-tool' ) ) ?>
    <div class="bookly:card">
        <div class="bookly:card-body">
            <ul class="bookly:nav bookly:nav-tabs bookly:mb-3" id="whatsapp_tabs">
                <li class="bookly:nav-item"><a class="bookly:nav-link bookly:active" href="#notifications"><?php esc_html_e( 'Notifications', 'bookly-responsive-appointment-booking-tool' ) ?></a></li>
                <li class="bookly:nav-item"><a class="bookly:nav-link" href="#details"><?php esc_html_e( 'Details', 'bookly-responsive-appointment-booking-tool' ) ?></a></li>
                <li class="bookly:nav-item"><a class="bookly:nav-link" href="#settings"><?php esc_html_e( 'Settings', 'bookly-responsive-appointment-booking-tool' ) ?></a></li>
            </ul>
            <div class="tab-content mt-3" id="whatsapp_tabs_content">
                <div class="bookly:tab-pane bookly:active" id="notifications"><?php include '_notifications.php' ?></div>
                <div class="bookly:tab-pane" id="details"><div id="bookly-whatsapp_details-datatables"></div></div>
                <div class="bookly:tab-pane" id="settings"><?php include '_settings.php' ?></div>
            </div>
        </div>
        <div class="bookly:card-footer bookly:justify-end bookly-js-whatsapp-settings-footer" hidden>
            <?php Buttons::renderSubmit( null, 'bookly-js-whatsapp-settings-save', __( 'Save', 'bookly-responsive-appointment-booking-tool' ) ) ?>
        </div>
    </div>

    <?php TableSettings\Dialog::render() ?>
</div>
