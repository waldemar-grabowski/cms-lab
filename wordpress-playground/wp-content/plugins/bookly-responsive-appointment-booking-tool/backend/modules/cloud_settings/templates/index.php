<?php defined( 'ABSPATH' ) || exit; // Exit if accessed directly
use Bookly\Backend\Components\PageHeader\Renderer as PageHeaderRenderer;

/**
 * @var Bookly\Backend\Modules\CloudSettings\Page $self
 */
?>
<div class="wrap bookly-css-root bookly-main-page-wrap">
    <?php PageHeaderRenderer::render( $self::pageSlug(), __( 'Bookly Cloud Account', 'bookly-responsive-appointment-booking-tool' ) ) ?>
    <div id="bookly-cloud-settings"></div>
</div>
