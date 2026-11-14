<?php defined( 'ABSPATH' ) || exit; // Exit if accessed directly
use Bookly\Backend\Components\PageHeader\Renderer as PageHeaderRenderer;
use Bookly\Lib;
?>
<style>
    .bookly-css-root {
        --bookly-color: <?php echo esc_attr( get_option( 'bookly_app_color', '#f4662f' ) ) ?>;
    }
</style>
<?php
/**
 * No #bookly-tbs here. That id is the scope Bootstrap is compiled against, and this
 * page has no Bootstrap markup left — carrying the id only imported its rules, most
 * visibly `#bookly-tbs a:hover { text-decoration: underline }`, which underlined
 * every button rendered as a link.
 *
 * The license dialog is the one Bootstrap component on the page, and it brings its
 * own #bookly-tbs wrapper, so it keeps its styling with no help from here.
 */
?>
<div class="wrap bookly-css-root bookly-main-page-wrap">
    <?php PageHeaderRenderer::render( $self::pageSlug(), __( 'Add-ons', 'bookly-responsive-appointment-booking-tool' ) ) ?>
    <div id="bookly-addons-form"></div>
    <?php Lib\Proxy\Pro::renderLicenseDialog() ?>
</div>
