<?php defined( 'ABSPATH' ) || exit; // Exit if accessed directly ?>
<div id="bookly-promo-banner" class="bookly:alert bookly:alert-info bookly:mb-4" role="alert" data-id="sms_sender_id">
    <svg class="bookly:alert-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
    <div class="bookly:alert-content">
        <div><b><?php esc_html_e( 'Sender ID is not set', 'bookly-responsive-appointment-booking-tool' ) ?></b></div>
        <div class="bookly:mt-1"><?php esc_html_e( 'Your messages come from a shared number. With a Sender ID, customers see your business name and know right away who is texting.', 'bookly-responsive-appointment-booking-tool' ) ?></div>
        <div class="bookly:alert-actions">
            <button type="button" class="bookly:alert-btn bookly:alert-btn-outline bookly-js-request-sender-id" data-button="request_sender_id"><?php echo esc_html( __( 'Request Sender ID', 'bookly-responsive-appointment-booking-tool' ) . '…' ) ?></button>
        </div>
    </div>
    <button type="button" class="bookly:alert-close bookly-js-dismiss" aria-label="<?php esc_attr_e( 'Close', 'bookly-responsive-appointment-booking-tool' ) ?>">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
    </button>
</div>
