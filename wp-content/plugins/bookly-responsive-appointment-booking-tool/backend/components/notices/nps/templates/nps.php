<?php defined( 'ABSPATH' ) || exit; // Exit if accessed directly ?>
<div class="wrap bookly-css-root bookly-notice-wrap bookly-js-nps-notice">
    <div id="bookly-nps-notice" class="bookly:alert bookly:alert-info">
        <svg class="bookly:alert-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
        <div class="bookly:alert-content">
            <div id="bookly-nps-quiz">
                <label><?php esc_html_e( 'How likely is it that you would recommend Bookly to a friend or colleague?', 'bookly-responsive-appointment-booking-tool' ) ?></label>
                <div class="bookly:flex bookly:gap-1 bookly:mt-2">
                    <?php for ( $i = 1; $i <= 10; ++ $i ): ?><button type="button" class="bookly-js-star bookly:alert-star" aria-label="<?php echo esc_attr( $i ) ?>"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/></svg></button><?php endfor ?>
                </div>
            </div>
            <div id="bookly-nps-form" class="bookly:mt-4 bookly:max-w-96" style="display: none">
                <div class="bookly:mb-3">
                    <label for="bookly-nps-msg" class="bookly:block bookly:mb-1"><?php esc_html_e( 'What do you think should be improved?', 'bookly-responsive-appointment-booking-tool' ) ?></label>
                    <textarea id="bookly-nps-msg" class="bookly:input bookly:w-full" rows="3"></textarea>
                </div>
                <div class="bookly:mb-3">
                    <label for="bookly-nps-email" class="bookly:block bookly:mb-1"><?php esc_html_e( 'Please enter your email (optional)', 'bookly-responsive-appointment-booking-tool' ) ?></label>
                    <input type="text" id="bookly-nps-email" class="bookly:input bookly:input-h-9 bookly:w-full" value="<?php echo esc_attr( $current_user->user_email ) ?>"/>
                </div>
                <button type="button" id="bookly-nps-btn" class="bookly:alert-btn bookly:alert-btn-primary"><?php esc_html_e( 'Send', 'bookly-responsive-appointment-booking-tool' ) ?></button>
            </div>
        </div>
        <button type="button" class="bookly:alert-close" data-dismiss="alert" aria-label="<?php esc_attr_e( 'Close', 'bookly-responsive-appointment-booking-tool' ) ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
    </div>
</div>
