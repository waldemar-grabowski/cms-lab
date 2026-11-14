<?php defined( 'ABSPATH' ) || exit; // Exit if accessed directly
use Bookly\Lib\Mailing\Audience;
/**
 * @var array $banner
 */
$count = $banner['count'];
$number = number_format_i18n( $count );
// The counts are taken with the default limits of the audiences, the texts name those limits.
switch ( $banner['preset'] ) {
    case Audience::PRESET_LAPSED:
        $title = sprintf( _n( '%s customer hasn\'t visited in over 90 days', '%s customers haven\'t visited in over 90 days', $count, 'bookly-responsive-appointment-booking-tool' ), $number );
        $lead = __( 'Bring past customers back for another appointment.', 'bookly-responsive-appointment-booking-tool' );
        break;
    case Audience::PRESET_REGULAR:
        $title = sprintf( _n( '%s regular customer is visiting less often than usual', '%s regular customers are visiting less often than usual', $count, 'bookly-responsive-appointment-booking-tool' ), $number );
        $lead = __( 'Find regular customers who haven\'t visited as often as usual.', 'bookly-responsive-appointment-booking-tool' );
        break;
    case Audience::PRESET_CANCELLED:
        $title = sprintf( _n( '%s customer cancelled and didn\'t rebook', '%s customers cancelled and didn\'t rebook', $count, 'bookly-responsive-appointment-booking-tool' ), $number );
        $lead = __( 'Help customers find a new time that works for them.', 'bookly-responsive-appointment-booking-tool' );
        break;
    case Audience::PRESET_ONCE:
        $title = sprintf( _n( '%s customer came once and didn\'t return', '%s customers came once and didn\'t return', $count, 'bookly-responsive-appointment-booking-tool' ), $number );
        $lead = __( 'Encourage first-time customers to come back.', 'bookly-responsive-appointment-booking-tool' );
        break;
    case Audience::PRESET_BIRTHDAY:
        $title = sprintf( _n( '%s customer has a birthday in the next 7 days', '%s customers have a birthday in the next 7 days', $count, 'bookly-responsive-appointment-booking-tool' ), $number );
        $lead = __( 'A greeting with a reason to book.', 'bookly-responsive-appointment-booking-tool' );
        break;
    default:
        $title = sprintf( _n( '%s customer can get your SMS', '%s customers can get your SMS', $count, 'bookly-responsive-appointment-booking-tool' ), $number );
        $lead = __( 'Share news, holiday hours, or information about new services.', 'bookly-responsive-appointment-booking-tool' );
}
?>
<div id="bookly-promo-banner" class="bookly:alert bookly:alert-info bookly:mb-4" role="alert" data-id="sms_campaigns">
    <svg class="bookly:alert-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg>
    <div class="bookly:alert-content">
        <div><b><?php echo esc_html( $title ) ?></b></div>
        <div class="bookly:mt-1"><?php echo esc_html( __( 'All of them have a phone number.', 'bookly-responsive-appointment-booking-tool' ) . ' ' . $lead ) ?></div>
        <div class="bookly:alert-actions">
            <a class="bookly:alert-btn bookly:alert-btn-primary bookly-js-new-campaign" href="<?php echo esc_url( $banner['url'] ) ?>" data-preset="<?php echo esc_attr( $banner['preset'] ) ?>" data-button="create_campaign"><?php echo esc_html( $banner['dialog'] ? __( 'Create campaign', 'bookly-responsive-appointment-booking-tool' ) . '…' : __( 'Create campaign', 'bookly-responsive-appointment-booking-tool' ) ) ?></a>
        </div>
    </div>
    <button type="button" class="bookly:alert-close bookly-js-dismiss" aria-label="<?php esc_attr_e( 'Close', 'bookly-responsive-appointment-booking-tool' ) ?>">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
    </button>
</div>
