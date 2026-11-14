<?php defined( 'ABSPATH' ) || exit; // Exit if accessed directly
use Bookly\Backend\Components\Controls;
use Bookly\Backend\Components\Dialogs;
use Bookly\Backend\Components\PageHeader\Renderer as PageHeaderRenderer;
use Bookly\Lib;

/** @var array $datatables */
?>
<div id="bookly-tbs" class="wrap bookly-css-root bookly-main-page-wrap">
    <?php PageHeaderRenderer::render( $self::pageSlug(), Lib\Utils\Common::isCurrentUserAdmin()
        ? __( 'Staff Members', 'bookly-responsive-appointment-booking-tool' )
        : __( 'Profile', 'bookly-responsive-appointment-booking-tool' ) ) ?>
    <div class="bookly:card">
        <div class="bookly:card-body">
            <div id="bookly-staff_members-datatables"></div>
        </div>
    </div>

    <?php Dialogs\Common\CascadeDelete::render() ?>
    <?php Dialogs\Common\UnsavedChanges::render() ?>
    <?php Dialogs\Staff\Proxy\Pro::renderCategoriesDialog() ?>
    <?php Dialogs\Staff\Proxy\Pro::renderDuplicateDialog() ?>
    <?php Dialogs\Staff\Edit\Dialog::render() ?>
    <?php Dialogs\Staff\Edit\Proxy\Packages::renderStaffServicesTip() ?>
    <?php Dialogs\TableSettings\Dialog::render() ?>
</div>