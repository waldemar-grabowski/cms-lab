<?php defined( 'ABSPATH' ) || exit; // Exit if accessed directly
use Bookly\Backend\Components\PageHeader\Renderer as PageHeaderRenderer;

/**
 * @var Bookly\Backend\Modules\CloudProducts\Page $self
 */
?>
<div class="wrap bookly-css-root bookly-main-page-wrap">
    <?php PageHeaderRenderer::render( $self::pageSlug(), __( 'Bookly Cloud', 'bookly-responsive-appointment-booking-tool' ) ) ?>
    <div id="bookly-cloud-products"></div>
</div>
