<?php defined( 'ABSPATH' ) || exit; // Exit if accessed directly
use Bookly\Backend\Components\Controls\Buttons;
use Bookly\Backend\Components\Dialogs;
?>
    <input type="hidden" name="form-notifications">
    <div class="form-group">
        <label for="admin_phone">
            <?php esc_html_e( 'Administrator phone', 'bookly-responsive-appointment-booking-tool' ) ?>
        </label>
        <div class="form-row">
            <div class="col-auto">
                <input class="form-control w-100 mb-3 mb-md-0" id="admin_phone" name="bookly_sms_administrator_phone" type="text" value="<?php form_option( 'bookly_sms_administrator_phone' ) ?>">
            </div>
            <div class="col-auto">
                <div class="btn-group">
                    <button class="btn btn-success" id="test_call"><?php esc_html_e( 'Make a test call', 'bookly-responsive-appointment-booking-tool' ) ?></button>
                    <button type="button" class="btn btn-success bookly-dropdown-toggle" data-toggle="bookly-dropdown" aria-haspopup="true" aria-expanded="false">
                        <span class="caret"></span>
                        <span class="sr-only">Toggle Dropdown</span>
                    </button>
                    <div class="bookly-dropdown-menu">
                        <a href="#" class="bookly-dropdown-item" data-action="save-administrator-phone"><?php esc_html_e( 'Save administrator phone', 'bookly-responsive-appointment-booking-tool' ) ?></a>
                    </div>
                </div>
            </div>
        </div>
        <small class="form-text text-muted"><?php esc_html_e( 'Enter a phone number in international format. E.g. for the United States a valid phone number would be +17327572923.', 'bookly-responsive-appointment-booking-tool' ) ?></small>
    </div>

    <div id="bookly-voice_notifications-datatables"></div>

    <div class="bookly:mt-3">
        <?php Buttons::renderDefault( 'bookly-js-test-voice-notifications', null, __( 'Test voice notifications', 'bookly-responsive-appointment-booking-tool' ), array(), true ) ?>
    </div>

<?php Dialogs\Voice\Dialog::render() ?>
<?php Dialogs\VoiceTest\Dialog::render() ?>
