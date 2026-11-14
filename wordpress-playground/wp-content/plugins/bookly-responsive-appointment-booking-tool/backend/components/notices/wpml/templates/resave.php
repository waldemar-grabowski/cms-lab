<?php defined( 'ABSPATH' ) || exit; // Exit if accessed directly ?>
<div class="wrap bookly-css-root bookly-notice-wrap">
    <div id="bookly-wpml-resave-notice" class="bookly:alert bookly:alert-warning" data-action="bookly_dismiss_wpml_resave_notice">
        <svg class="bookly:alert-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
        <div class="bookly:alert-content">
            <?php printf( esc_html__( 'If you use WPML and notice that some translations don\'t appear on front end, you will need to restore them. Go to WPML, select strings within %s domain, choose any translation, and click the "Save" button.', 'bookly-responsive-appointment-booking-tool' ), '<b>bookly</b>' ) ?>
        </div>
        <button type="button" class="bookly:alert-close" data-dismiss="alert" aria-label="<?php esc_attr_e( 'Close', 'bookly-responsive-appointment-booking-tool' ) ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
    </div>
</div>
