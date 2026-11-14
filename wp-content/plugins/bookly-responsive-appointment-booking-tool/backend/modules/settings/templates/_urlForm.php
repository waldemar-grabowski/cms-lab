<?php defined( 'ABSPATH' ) || exit; // Exit if accessed directly
use Bookly\Backend\Components\Controls\Buttons;
use Bookly\Backend\Components\Controls\Inputs as ControlsInputs;
use Bookly\Backend\Components\Settings\Inputs;
use Bookly\Backend\Modules\Settings\Proxy;

?>
<form method="post" action="<?php echo esc_url( add_query_arg( 'tab', 'url' ) ) ?>">
    <div class="card-body">
        <?php
        Inputs::renderText( 'bookly_url_approve_page_url', __( 'Approve appointment URL (success)', 'bookly-responsive-appointment-booking-tool' ), __( 'Set the URL of a page that is shown to staff after they successfully approved the appointment.', 'bookly-responsive-appointment-booking-tool' ) );
        Inputs::renderText( 'bookly_url_approve_denied_page_url', __( 'Approve appointment URL (denied)', 'bookly-responsive-appointment-booking-tool' ), __( 'Set the URL of a page that is shown to staff when the approval of appointment cannot be done (due to capacity, changed status, etc.).', 'bookly-responsive-appointment-booking-tool' ) );
        Inputs::renderText( 'bookly_url_cancel_page_url', __( 'Cancel appointment URL (success)', 'bookly-responsive-appointment-booking-tool' ), __( 'Set the URL of a page that is shown to clients after they successfully cancelled their appointment.', 'bookly-responsive-appointment-booking-tool' ) );
        Inputs::renderText( 'bookly_url_cancel_denied_page_url', __( 'Cancel appointment URL (denied)', 'bookly-responsive-appointment-booking-tool' ), __( 'Set the URL of a page that is shown to clients when the cancellation of appointment is not available anymore.', 'bookly-responsive-appointment-booking-tool' ) );
        Proxy\Pro::renderCancellationConfirmationUrl();
        Inputs::renderText( 'bookly_url_reject_page_url', __( 'Reject appointment URL (success)', 'bookly-responsive-appointment-booking-tool' ), __( 'Set the URL of a page that is shown to staff after they successfully rejected the appointment.', 'bookly-responsive-appointment-booking-tool' ) );
        Inputs::renderText( 'bookly_url_reject_denied_page_url', __( 'Reject appointment URL (denied)', 'bookly-responsive-appointment-booking-tool' ), __( 'Set the URL of a page that is shown to staff when the rejection of appointment cannot be done (due to changed status, etc.).', 'bookly-responsive-appointment-booking-tool' ) );
        Proxy\Pro::renderFinalStepUrl();
        Proxy\Shared::renderUrlSettings();
        ?>
    </div>
    <div class="card-footer bg-transparent d-flex justify-content-end">
        <?php ControlsInputs::renderCsrf() ?>
        <?php Buttons::renderSubmit() ?>
        <?php Buttons::renderReset( null, 'ml-2' ) ?>
    </div>
</form>