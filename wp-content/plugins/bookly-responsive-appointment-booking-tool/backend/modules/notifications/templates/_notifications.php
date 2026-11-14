<?php defined( 'ABSPATH' ) || exit; // Exit if accessed directly
use Bookly\Backend\Components\Controls\Buttons;
use Bookly\Backend\Components\Controls\Inputs;
use Bookly\Backend\Components\Dialogs;
use Bookly\Backend\Modules\Notifications;

/** @var array $datatables */
?>

<div id="bookly-email_notifications-datatables"></div>
<div class="form-row mt-3">
    <div class="col-auto">
        <?php Inputs::renderCsrf() ?>
        <?php Buttons::renderDefault( 'bookly-js-test-email-notifications', null, __( 'Test email notifications', 'bookly-responsive-appointment-booking-tool' ), array(), true ) ?>
    </div>
</div>
<?php $self::renderTemplate( '_test_email_modal' ) ?>

