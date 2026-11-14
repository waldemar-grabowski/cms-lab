<?php defined( 'ABSPATH' ) || exit; // Exit if accessed directly ?>
<div class="wrap bookly-css-root bookly-notice-wrap">
    <div id="bookly-lite-rebranding-notice" class="bookly:alert bookly:alert-info" data-action="bookly_dismiss_lite_rebranding_notice">
        <svg class="bookly:alert-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
        <div class="bookly:alert-content">
            <?php printf( __( '<b>Bookly Lite rebrands into Bookly with more features available.</b><br/><br/>We have changed the architecture of Bookly Lite and Bookly to optimize the development of both plugin versions and add more features to the new free Bookly. To learn more about the major Bookly update, check our <a href="%s" target="_blank">blog post</a>.', 'bookly-responsive-appointment-booking-tool' ), 'https://www.booking-wp-plugin.com/bookly-major-update/?utm_source=bookly_admin&utm_medium=pro_not_active&utm_campaign=notification' ) ?>
        </div>
        <button type="button" class="bookly:alert-close" data-dismiss="alert" aria-label="<?php esc_attr_e( 'Close', 'bookly-responsive-appointment-booking-tool' ) ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
    </div>
</div>
