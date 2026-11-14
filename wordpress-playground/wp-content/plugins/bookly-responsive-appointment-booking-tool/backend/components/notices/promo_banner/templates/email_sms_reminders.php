<?php defined( 'ABSPATH' ) || exit; // Exit if accessed directly
/**
 * @var array $banner
 */
?>
<div id="bookly-promo-banner" class="bookly:alert bookly:alert-info bookly:mb-4" role="alert" data-id="email_sms_reminders">
    <svg class="bookly:alert-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m22 7-8.991 5.727a2 2 0 0 1-2.009 0L2 7"/><rect x="2" y="4" width="20" height="16" rx="2"/></svg>
    <div class="bookly:alert-content">
        <div><b><?php
            if ( $banner['reminders'] ) {
                printf( esc_html( _n( '%s email reminder sent in the last 30 days', '%s email reminders sent in the last 30 days', $banner['sent'], 'bookly-responsive-appointment-booking-tool' ) ), esc_html( number_format_i18n( $banner['sent'] ) ) );
            } elseif ( $banner['days'] == 30 ) {
                printf( esc_html( _n( '%s email sent in the last 30 days', '%s emails sent in the last 30 days', $banner['sent'], 'bookly-responsive-appointment-booking-tool' ) ), esc_html( number_format_i18n( $banner['sent'] ) ) );
            } else {
                printf( esc_html( _n( '%s email sent in the last 7 days', '%s emails sent in the last 7 days', $banner['sent'], 'bookly-responsive-appointment-booking-tool' ) ), esc_html( number_format_i18n( $banner['sent'] ) ) );
            }
        ?></b></div>
        <div class="bookly:mt-1"><?php esc_html_e( 'About 20% of emails get opened, while SMS open rate is 98%. Send yourself a test SMS — it\'s free.', 'bookly-responsive-appointment-booking-tool' ) ?></div>
        <div class="bookly:alert-actions">
            <a class="bookly:alert-btn bookly:alert-btn-primary" href="<?php echo esc_url( $banner['test_url'] ) ?>" data-button="send_test"><?php esc_html_e( 'Send test SMS', 'bookly-responsive-appointment-booking-tool' ) ?></a>
            <?php if ( $banner['info_url'] ) : ?>
                <a class="bookly:alert-btn bookly:alert-btn-outline" href="<?php echo esc_url( $banner['info_url'] ) ?>" data-button="how_it_works"><?php esc_html_e( 'How it works', 'bookly-responsive-appointment-booking-tool' ) ?></a>
            <?php endif ?>
        </div>
    </div>
    <button type="button" class="bookly:alert-close bookly-js-dismiss" aria-label="<?php esc_attr_e( 'Close', 'bookly-responsive-appointment-booking-tool' ) ?>">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
    </button>
</div>
