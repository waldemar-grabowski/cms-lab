<?php defined( 'ABSPATH' ) || exit; // Exit if accessed directly
use Bookly\Backend\Components\PageHeader\Renderer as PageHeaderRenderer;
use Bookly\Backend\Components\Dialogs\TableSettings;
use Bookly\Backend\Components\Dialogs\MobileStaffCabinet\AccessEdit;
/** @var array $datatables */
?>
<div id="bookly-tbs" class="wrap bookly-css-root bookly-main-page-wrap">
    <?php PageHeaderRenderer::render( $self::pageSlug(), __( 'Staff Cabinet Mobile App', 'bookly-responsive-appointment-booking-tool' ) ) ?>
    <div class="bookly:card">
        <div class="bookly:card-body">
            <div id="bookly-cloud_mobile_staff_cabinet-datatables"></div>
        </div>
    </div>
    <?php AccessEdit\Dialog::render() ?>
    <?php TableSettings\Dialog::render() ?>
</div>
