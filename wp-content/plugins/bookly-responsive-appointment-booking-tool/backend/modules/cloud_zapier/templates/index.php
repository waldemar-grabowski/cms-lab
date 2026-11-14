<?php defined( 'ABSPATH' ) || exit; // Exit if accessed directly
use Bookly\Backend\Components\PageHeader\Renderer as PageHeaderRenderer;
use Bookly\Backend\Components\Dialogs;
use Bookly\Backend\Components\Settings\Inputs;
use Bookly\Backend\Components\Controls\Buttons;
?>
<div id="bookly-tbs" class="wrap bookly-css-root bookly-main-page-wrap">
    <?php PageHeaderRenderer::render( $self::pageSlug(), 'Zapier' ) ?>
    <div class="bookly:card mb-4">
        <div class="bookly:card-body">
            <div class="form-group">
                <h4><?php esc_html_e( 'Instructions', 'bookly-responsive-appointment-booking-tool' ) ?></h4>
                <p></p>
                <ol>
                    <li><?php printf( __( 'If you have not already done so, <a href="%s" target="_blank">sign up for Zapier</a>', 'bookly-responsive-appointment-booking-tool' ), 'https://zapier.com/sign-up/' ) ?></li>
                    <li><?php printf( __( '<a href="%s" target="_blank">Sign in to Zapier</a> and click <a href="%s" target="_blank"><b>Make a Zap</b></a>', 'bookly-responsive-appointment-booking-tool' ), 'https://zapier.com/login/', 'https://zapier.com/app/editor' ) ?></li>
                    <li><?php _e( 'In the <b>Choose App & Event</b> step search for the <b>Bookly</b> app and select it', 'bookly-responsive-appointment-booking-tool' ) ?></li>
                    <li><?php _e( 'In the <b>Choose Trigger Event</b> dropdown choose a trigger and click <b>Continue</b>', 'bookly-responsive-appointment-booking-tool' ) ?></li>
                    <li><?php _e( 'In the <b>Choose Account</b> step click <b>Sign in to Bookly</b>', 'bookly-responsive-appointment-booking-tool' ) ?></li>
                    <li><?php _e( 'In the popup window enter the API Key found below on this page, and click <b>Yes, Continue</b>', 'bookly-responsive-appointment-booking-tool' ) ?></li>
                    <li><?php _e( 'Click <b>Continue</b>, then <b>Test trigger</b> and <b>Continue</b>', 'bookly-responsive-appointment-booking-tool' ) ?></li>
                    <li><?php esc_html_e( 'Continue creating your Zap by selecting the options you\'d like', 'bookly-responsive-appointment-booking-tool' ) ?></li>
                    <li><?php _e( 'Finally, click <b>Finish</b> to create your Zap', 'bookly-responsive-appointment-booking-tool' ) ?></li>
                    <li><?php esc_html_e( 'Once your Zap is created, make sure to toggle your Zap "on". It\'s now ready to go and will run automatically', 'bookly-responsive-appointment-booking-tool' ) ?></li>
                </ol>
            </div>
            <div class="form-row">
                <div class="col-lg-6 col-xs-12">
                    <?php Inputs::renderOptionCopy( 'bookly_cloud_zapier_api_key', __( 'API Key', 'bookly-responsive-appointment-booking-tool' ) ) ?>
                </div>
            </div>
            <div class="form-row">
                <div class="col-lg-6 col-xs-12">
                    <?php Buttons::renderDefault( 'bookly-zapier-generate-new-api-key', null, __( 'Generate new API Key', 'bookly-responsive-appointment-booking-tool' ), array(), true ) ?>
                </div>
            </div>
        </div>
    </div>
</div>