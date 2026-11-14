<?php defined( 'ABSPATH' ) || exit; // Exit if accessed directly
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
                    <button class="btn btn-success" data-action="save-administrator-phone"><?php esc_html_e( 'Save administrator phone', 'bookly-responsive-appointment-booking-tool' ) ?></button>
                </div>
            </div>
        </div>
        <small class="form-text text-muted"><?php esc_html_e( 'Enter a phone number in international format. E.g. for the United States a valid phone number would be +17327572923.', 'bookly-responsive-appointment-booking-tool' ) ?></small>
    </div>

    <div id="bookly-whatsapp_notifications-datatables"></div>

<?php Dialogs\Whatsapp\Dialog::render() ?>
