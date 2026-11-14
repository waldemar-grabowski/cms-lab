<?php defined( 'ABSPATH' ) || exit; // Exit if accessed directly ?>
<div class="wrap bookly-css-root bookly-notice-wrap">
    <div id="<?php echo esc_attr( $id ) ?>" class="bookly:alert bookly:alert-success<?php if ( $hidden ) : ?> bookly-js-hidden<?php endif ?>" role="alert">
        <img class="bookly:alert-avatar" src="<?php echo plugins_url( 'bookly-responsive-appointment-booking-tool/backend/components/notices/base/images/photo.png' ) ?>" alt="Daniel Williams, PO at Bookly" width="48" height="48"/>
        <div class="bookly:alert-content">
            <div><b class="bookly-js-alert-title"><?php echo esc_html( $title ) ?></b> <?php echo esc_html( $sub_title ) ?></div>
            <div class="bookly:font-semibold bookly:mt-1"><?php echo nl2br( esc_html( $message ) ) ?></div>
            <small class="bookly:text-muted-foreground">Daniel Williams, PO at Bookly</small>
            <div class="bookly:alert-actions">
                <?php foreach ( $buttons as $button ) : ?>
                    <button type="button" class="bookly:alert-btn <?php echo esc_attr( $button['class'] ) ?>"><?php echo esc_html( $button['caption'] ) ?></button>
                <?php endforeach ?>
            </div>
        </div>
        <button type="button" class="bookly:alert-close <?php echo esc_attr( $dismiss_js_class ) ?>" aria-label="Close">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
    </div>
</div>
