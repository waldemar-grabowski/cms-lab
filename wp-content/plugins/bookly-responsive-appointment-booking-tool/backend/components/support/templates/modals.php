<?php defined( 'ABSPATH' ) || exit; // Exit if accessed directly
use Bookly\Backend\Components\Controls\Buttons;
use Bookly\Backend\Components\Controls\Inputs;
/**
 * @var \WP_User $current_user
 */
?>
<?php /* Contact us modal */ ?>
<div id="bookly-contact-us-modal" class="bookly-modal bookly-fade text-left" tabindex=-1>
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?php esc_html_e( 'Leave us a message', 'bookly-responsive-appointment-booking-tool' ) ?></h5>
                <button type="button" class="close" data-dismiss="bookly-modal" aria-label="Close"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label for="bookly-support-name"><?php esc_html_e( 'Your name', 'bookly-responsive-appointment-booking-tool' ) ?></label>
                    <input type="text" id="bookly-support-name" class="form-control" value="<?php echo esc_attr( $current_user->user_firstname . ' ' . $current_user->user_lastname ) ?>"/>
                </div>
                <div class="form-group">
                    <label for="bookly-support-email"><?php esc_html_e( 'Email address', 'bookly-responsive-appointment-booking-tool' ) ?> <span class="text-danger">*</span></label>
                    <input type="text" id="bookly-support-email" class="form-control" value="<?php echo esc_attr( $current_user->user_email ) ?>"/>
                </div>
                <div class="form-group">
                    <label for="bookly-support-msg"><?php esc_html_e( 'How can we help you?', 'bookly-responsive-appointment-booking-tool' ) ?> <span class="text-danger">*</span></label>
                    <textarea id="bookly-support-msg" class="form-control" rows="10"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <?php Inputs::renderCsrf() ?>
                <?php Buttons::render( 'bookly-support-send', 'btn-success', __( 'Send', 'bookly-responsive-appointment-booking-tool' ) ) ?>
                <?php Buttons::renderCancel() ?>
            </div>
        </div>
    </div>
</div>

<?php /* Feature requests modal (only rendered if user hasn't dismissed it) */ ?>
<?php $dismiss = get_user_meta( get_current_user_id(), 'bookly_dismiss_feature_requests_description', true ); ?>
<?php if ( ! $dismiss ) : ?>
    <div id="bookly-feature-requests-modal" class="bookly-modal bookly-fade text-left" tabindex=-1>
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><?php esc_html_e( 'Feature requests', 'bookly-responsive-appointment-booking-tool' ) ?></h5>
                    <button type="button" class="close" data-dismiss="bookly-modal" aria-label="Close"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <p><?php esc_html_e( 'In the Feature Requests section of our Community, you can make suggestions about what you\'d like to see in our future releases.', 'bookly-responsive-appointment-booking-tool' ) ?></p>
                    <p><?php esc_html_e( 'Before you post, please check if the same suggestion has already been made. If so, vote for ideas you like and add a comment with the details about your situation.', 'bookly-responsive-appointment-booking-tool' ) ?></p>
                    <p><?php esc_html_e( 'It\'s much easier for us to address a suggestion if we clearly understand the context of the issue, the problem, and why it matters to you. When commenting or posting, please consider these questions so we can get a better idea of the problem you\'re facing:', 'bookly-responsive-appointment-booking-tool' ) ?></p>
                    <ul>
                        <li><?php esc_html_e( 'What is the issue you\'re struggling with?', 'bookly-responsive-appointment-booking-tool' ) ?></li>
                        <li><?php esc_html_e( 'Where in your workflow do you encounter this issue?', 'bookly-responsive-appointment-booking-tool' ) ?></li>
                        <li><?php esc_html_e( 'Is this something that impacts just you, your whole team, or your customers?', 'bookly-responsive-appointment-booking-tool' ) ?></li>
                    </ul>
                    <div class="custom-control custom-checkbox">
                        <input class="custom-control-input form-check-input" id="bookly-js-dont-show-again-feature" type=checkbox/>
                        <label class="custom-control-label" for="bookly-js-dont-show-again-feature"><?php esc_html_e( 'don\'t show this notification again', 'bookly-responsive-appointment-booking-tool' ) ?></label>
                    </div>
                </div>
                <div class="modal-footer">
                    <?php Buttons::renderSubmit( null, 'bookly-js-proceed-requests', __( 'Proceed to Feature requests', 'bookly-responsive-appointment-booking-tool' ) ) ?>
                    <?php Buttons::renderCancel() ?>
                </div>
            </div>
        </div>
    </div>
<?php endif ?>

<?php /* View demo modal — rendered only for pages with a demo counterpart (non-Pro) and not yet dismissed */ ?>
<?php $demo_url = \Bookly\Backend\Components\Support\Buttons::getDemoUrl( $page_slug ); ?>
<?php if ( $demo_url && ! get_user_meta( get_current_user_id(), 'bookly_dismiss_demo_site_description', true ) ) : ?>
    <div id="bookly-demo-site-info-modal" class="bookly-modal bookly-fade text-left" tabindex=-1>
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><?php esc_html_e( 'Visit demo', 'bookly-responsive-appointment-booking-tool' ) ?></h5>
                    <button type="button" class="close" data-dismiss="bookly-modal" aria-label="Close"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <p><?php esc_html_e( 'The demo is a version of Bookly Pro with all installed add-ons so that you can try all the features and capabilities of the system and then choose the most suitable configuration according to your business needs.', 'bookly-responsive-appointment-booking-tool' ) ?></p>
                    <div class="custom-control custom-checkbox">
                        <input class="custom-control-input form-check-input" id="bookly-js-dont-show-again-demo" type="checkbox"/>
                        <label class="custom-control-label" for="bookly-js-dont-show-again-demo"><?php esc_html_e( 'don\'t show this notification again', 'bookly-responsive-appointment-booking-tool' ) ?></label>
                    </div>
                </div>
                <div class="modal-footer">
                    <?php Buttons::renderSubmit( null, 'bookly-js-proceed-to-demo', __( 'Proceed to demo', 'bookly-responsive-appointment-booking-tool' ), array( 'data-target' => $demo_url ) ) ?>
                    <?php Buttons::renderCancel() ?>
                </div>
            </div>
        </div>
    </div>
<?php endif ?>
