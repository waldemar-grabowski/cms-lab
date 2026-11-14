<?php defined( 'ABSPATH' ) || exit; // Exit if accessed directly
use Bookly\Backend\Components\Settings\Inputs;
/** @var \Bookly\Lib\Cloud\WhatsApp $whatsapp */
?>
<div class="row">
    <div class="col-md-12">
        <?php Inputs::renderTextValue( 'access_token', $whatsapp->access_token, __( 'Permanent access token', 'bookly-responsive-appointment-booking-tool' ) ) ?>
        <?php Inputs::renderTextValue( 'phone_id', $whatsapp->phone_id, __( 'Phone number ID', 'bookly-responsive-appointment-booking-tool' ) ) ?>
        <?php Inputs::renderTextValue( 'business_account_id', $whatsapp->business_account_id, __( 'WhatsApp Business Account ID', 'bookly-responsive-appointment-booking-tool' ) ) ?>
    </div>
</div>
