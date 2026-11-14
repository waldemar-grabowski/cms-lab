<?php defined( 'ABSPATH' ) || exit; // Exit if accessed directly ?>
<div class="wrap bookly-css-root bookly-notice-wrap">
    <div id="bookly-collect-stats-notice" class="bookly:alert bookly:alert-info" data-action="bookly_dismiss_collect<?php if ( $enabled ): ?>ing<?php endif ?>_stats_notice">
        <svg class="bookly:alert-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
        <div class="bookly:alert-content">
            <?php if ( $enabled ): ?>
                <?php esc_html_e( 'To help us improve Bookly, the plugin anonymously collects usage information. You can opt out of sharing the information in Settings > General.', 'bookly-responsive-appointment-booking-tool' ) ?>
                <div class="bookly:alert-actions">
                    <button type="button" class="bookly:alert-btn bookly:alert-btn-outline" data-dismiss="alert"><?php esc_html_e( 'Close', 'bookly-responsive-appointment-booking-tool' ) ?></button>
                </div>
            <?php else: ?>
                <?php esc_html_e( 'Let the plugin anonymously collect usage information to help Bookly team improve the product.', 'bookly-responsive-appointment-booking-tool' ) ?>
                <div class="bookly:alert-actions">
                    <button type="button" id="bookly-enable-collecting-stats-btn" class="bookly:alert-btn bookly:alert-btn-primary"><?php esc_html_e( 'Agree', 'bookly-responsive-appointment-booking-tool' ) ?></button>
                    <button type="button" class="bookly:alert-btn bookly:alert-btn-outline" data-dismiss="alert"><?php esc_html_e( 'Disagree', 'bookly-responsive-appointment-booking-tool' ) ?></button>
                </div>
            <?php endif ?>
        </div>
        <button type="button" class="bookly:alert-close" data-dismiss="alert" aria-label="<?php esc_attr_e( 'Close', 'bookly-responsive-appointment-booking-tool' ) ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
    </div>
</div>
