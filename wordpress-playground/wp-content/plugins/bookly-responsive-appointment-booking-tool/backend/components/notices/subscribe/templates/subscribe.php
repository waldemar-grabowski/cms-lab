<?php defined( 'ABSPATH' ) || exit; // Exit if accessed directly ?>
<div class="wrap bookly-css-root bookly-notice-wrap">
    <div id="bookly-subscribe-notice" class="bookly:alert bookly:alert-info">
        <svg class="bookly:alert-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
        <div class="bookly:alert-content">
            <label for="bookly-subscribe-email"><?php esc_html_e( 'Subscribe to monthly emails about Bookly improvements and new releases.', 'bookly-responsive-appointment-booking-tool' ) ?></label>
            <div class="bookly:alert-actions">
                <input type="text" id="bookly-subscribe-email" class="bookly:input bookly:input-h-9 bookly:w-72" placeholder="<?php esc_attr_e( 'Email', 'bookly-responsive-appointment-booking-tool' ) ?>"/>
                <button type="button" id="bookly-subscribe-btn" class="bookly:alert-btn bookly:alert-btn-primary"><?php esc_html_e( 'Send', 'bookly-responsive-appointment-booking-tool' ) ?></button>
            </div>
        </div>
        <button type="button" class="bookly:alert-close" data-dismiss="alert" aria-label="<?php esc_attr_e( 'Close', 'bookly-responsive-appointment-booking-tool' ) ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
    </div>
</div>
