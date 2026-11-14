<?php defined( 'ABSPATH' ) || exit; // Exit if accessed directly
use Bookly\Backend\Components\Dialogs;
use Bookly\Backend\Components\PageHeader\Renderer as PageHeaderRenderer;
use Bookly\Backend\Modules\Appointments\Proxy;

/** @var array $datatables */
?>
<div id="bookly-tbs" class="wrap bookly-css-root bookly-main-page-wrap">
    <?php PageHeaderRenderer::render( $self::pageSlug(), __( 'Appointments', 'bookly-responsive-appointment-booking-tool' ) ) ?>
    <div class="bookly:card">
        <div class="bookly:card-body">
            <div id="bookly-appointments-datatables"></div>
        </div>
    </div>

    <?php Proxy\Pro::renderExportDialog( $datatables['appointments'] ) ?>
    <?php Proxy\Pro::renderPrintDialog( $datatables['appointments'] ) ?>

    <?php Dialogs\Appointment\Delete\Dialog::render() ?>
    <?php Dialogs\TableSettings\Dialog::render() ?>
    <?php Dialogs\Appointment\Edit\Dialog::render() ?>
    <?php Dialogs\Appointment\Card\Dialog::render() ?>
    <?php Dialogs\Queue\Dialog::render() ?>
    <?php Proxy\Shared::renderAddOnsComponents() ?>
</div>
