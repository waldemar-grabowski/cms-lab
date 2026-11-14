<?php defined( 'ABSPATH' ) || exit; // Exit if accessed directly
use Bookly\Backend\Components\Controls\Buttons;
use Bookly\Backend\Components\Controls\Inputs as ControlsInputs;
use Bookly\Backend\Components\Settings\Selects;
use Bookly\Backend\Modules\Settings\Proxy;
use Bookly\Lib\Utils\Common;
use Bookly\Lib\Config;
use Bookly\Backend\Components\Ace;
use Bookly\Backend\Modules\Settings\Codes;

$help = __( 'Select status for newly booked appointments.', 'bookly-responsive-appointment-booking-tool' );
if ( Config::customerGroupsActive() ) {
    $help .= ' ' . sprintf( __( 'Please note that this setting will be overridden by Customer Groups > General settings > <a href="%s" target="_blank">Default appointment status</a>.', 'bookly-responsive-appointment-booking-tool' ), Common::escAdminUrl( 'bookly-customer-groups' ) );
}
?>
<form method="post" action="<?php echo esc_url( add_query_arg( 'tab', 'appointments' ) ) ?>">
    <div class="card-body">
        <?php Selects::renderSingleValue( 'bookly_appointments_create_with_wizard', get_option( 'bookly_appointments_create_with_wizard', '1' ), __( 'Use the booking wizard', 'bookly-responsive-appointment-booking-tool' ), __( 'If enabled, appointments are created, viewed and rescheduled in the booking wizard. It opens when you click the \'New appointment\' button, an empty calendar slot or an existing appointment. If disabled, the classic appointment form is used everywhere.', 'bookly-responsive-appointment-booking-tool' ) ) ?>
        <?php Selects::renderSingle( 'bookly_appointment_default_status', __( 'Default appointment status', 'bookly-responsive-appointment-booking-tool' ), $help, $statuses ) ?>
        <?php Selects::renderSingle( 'bookly_successful_payment_appointment_status', __( 'Successful payment appointment status', 'bookly-responsive-appointment-booking-tool' ), __( 'Select the status that will be automatically assigned to an appointment once its payment is successfully completed. Only appointments with the status defined in the \'Default appointment status\' setting will be affected.', 'bookly-responsive-appointment-booking-tool' ), array_merge( array( array( 'disabled', __( 'Disabled', 'bookly-responsive-appointment-booking-tool' ) ) ), $statuses ) ) ?>
        <?php Proxy\Pro::renderAppointmentsSettings() ?>
        <div class="form-group">
            <label for="bookly-ics-customer-editor"><?php esc_html_e( 'Description for customer\'s calendar', 'bookly-responsive-appointment-booking-tool' ) ?></label>
            <?php Ace\Editor::render( 'bookly-placeholders', 'bookly-ics-customer-editor', Codes::getJson( 'ics_for_customer' ), get_option( 'bookly_l10n_ics_customer_template', '' ), 'bookly-ace-editor-h80' ) ?>
            <input type="hidden" name="bookly_l10n_ics_customer_template" value="<?php echo esc_attr( get_option( 'bookly_l10n_ics_customer_template', '' ) ) ?>"/>
        </div>
        <div class="form-group">
            <label for="bookly-ics-staff-editor"><?php esc_html_e( 'ICS description for staff', 'bookly-responsive-appointment-booking-tool' ) ?></label>
            <?php Ace\Editor::render( 'bookly-placeholders', 'bookly-ics-staff-editor', Codes::getJson( 'ics_for_staff' ), get_option( 'bookly_ics_staff_template', '' ), 'bookly-ace-editor-h80' ) ?>
            <input type="hidden" name="bookly_ics_staff_template" value="<?php echo esc_attr( get_option( 'bookly_ics_staff_template', '' ) ) ?>"/>
        </div>
        <?php Selects::renderSingle( 'bookly_appointment_end_date_method', __( 'The value returned by {appointment_end_date} placeholder', 'bookly-responsive-appointment-booking-tool' ), __( 'Choose \'Accurate date\' if you want the {appointment_end_date} placeholder to return the exact end date of the appointment. Select \'+1 day\' if you prefer the placeholder to include an additional day in the end date calculation. Please note that this setting will affect only appointments with a duration longer or equal to one day.', 'bookly-responsive-appointment-booking-tool' ), array(
                array( 'default', __( '+1 day', 'bookly-responsive-appointment-booking-tool' ) ),
                array( 'accurate', __( 'Accurate date', 'bookly-responsive-appointment-booking-tool' ) ),
        ) ) ?>
    </div>

    <div class="card-footer bg-transparent d-flex justify-content-end">
        <?php ControlsInputs::renderCsrf() ?>
        <?php Buttons::renderSubmit() ?>
        <?php Buttons::renderReset( null, 'ml-2' ) ?>
    </div>
</form>