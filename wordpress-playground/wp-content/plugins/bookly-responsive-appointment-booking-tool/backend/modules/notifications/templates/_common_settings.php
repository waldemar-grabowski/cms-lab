<?php defined( 'ABSPATH' ) || exit; // Exit if accessed directly
use Bookly\Backend\Components\Settings\Selects;

$bookly_email_sender_name = get_option( 'bookly_email_sender_name' ) == '' ?
    get_option( 'blogname' ) : get_option( 'bookly_email_sender_name' );
$bookly_email_sender = get_option( 'bookly_email_sender' ) == '' ?
    get_option( 'admin_email' ) : get_option( 'bookly_email_sender' );
?>
<div class="row">
    <div class="col-md-12">
        <div class="form-group">
            <label for="sender_name<?php echo esc_attr( $tail ) ?>"><?php esc_html_e( 'Sender name', 'bookly-responsive-appointment-booking-tool' ) ?></label>
            <input id="sender_name<?php echo esc_attr( $tail ) ?>" name="bookly_email_sender_name" class="form-control" type="text" value="<?php echo esc_attr( $bookly_email_sender_name ) ?>">
        </div>
    </div>
</div>
<div class="row">
    <div class="col-md-12">
        <div class="form-group">
            <label for="sender_email<?php echo esc_attr( $tail ) ?>"><?php esc_html_e( 'Sender email', 'bookly-responsive-appointment-booking-tool' ) ?></label>
            <input id="sender_email<?php echo esc_attr( $tail ) ?>" name="bookly_email_sender" class="form-control bookly-sender" type="text" value="<?php echo esc_attr( $bookly_email_sender ) ?>">
        </div>
    </div>
</div>
<div class="row">
    <div class="col-md-12">
        <?php Selects::renderRadios( 'bookly_email_send_as', __( 'Send emails as', 'bookly-responsive-appointment-booking-tool' ), __( 'HTML allows formatting, colors, fonts, positioning, etc. With Text you must use Text mode of rich-text editors below. On some servers only text emails are sent successfully.', 'bookly-responsive-appointment-booking-tool' ),
            array( 'html' => array( 'title' => __( 'HTML', 'bookly-responsive-appointment-booking-tool' ) ), 'text' => array( 'title' => __( 'Text', 'bookly-responsive-appointment-booking-tool' ) ) )
        ) ?>
    </div>
</div>
<div class="row">
    <div class="col-md-12">
        <?php Selects::renderRadios( 'bookly_email_reply_to_customers', __( 'Reply directly to customers', 'bookly-responsive-appointment-booking-tool' ), __( 'If this option is enabled then the email address of the customer is used as a sender email address for notifications sent to staff members and administrators.', 'bookly-responsive-appointment-booking-tool' ) ) ?>
    </div>
</div>