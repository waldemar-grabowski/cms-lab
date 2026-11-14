<?php defined( 'ABSPATH' ) || exit; // Exit if accessed directly
use Bookly\Backend\Components\PageHeader\Renderer as PageHeaderRenderer;
/** @var string $variant */
?>
<?php if ( $variant === 'v2' ) : ?>
    <?php // No #bookly-tbs / .bookly-main-page-wrap here: keeps legacy Bootstrap and fullscreen CSS off the wizard ?>
    <div id="bookly-setup-v2" class="bookly-css-root">
        <div id="bookly-setup-form"></div>
    </div>
<?php else : ?>
    <div id="bookly-tbs" class="wrap bookly-css-root bookly-main-page-wrap">
        <?php PageHeaderRenderer::render( $self::pageSlug(), __( 'Initial setup', 'bookly-responsive-appointment-booking-tool' ) ) ?>
        <div id="bookly-setup-form"></div>
    </div>
<?php endif ?>
