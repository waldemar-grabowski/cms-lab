<?php defined( 'ABSPATH' ) || exit; // Exit if accessed directly
use Bookly\Backend\Modules\Notifications;
use Bookly\Backend\Components\Dialogs;
use Bookly\Backend\Components\PageHeader\Renderer as PageHeaderRenderer;
use Bookly\Backend\Components\Controls\Buttons;
use Bookly\Backend\Components\Notices\PromoBanner\Banner as PromoBanner;
/**
 * @var string $tab
 */
?>
<div id="bookly-tbs" class="wrap bookly-css-root bookly-main-page-wrap">
    <?php PageHeaderRenderer::render( $self::pageSlug(), __( 'Email notifications', 'bookly-responsive-appointment-booking-tool' ) ) ?>
    <div class="bookly:card">
        <div class="bookly:card-body">
            <?php PromoBanner::render( array( PromoBanner::EMAIL_SMS_REMINDERS, PromoBanner::SMS_CAMPAIGNS ) ) ?>
            <ul class="bookly:nav bookly:nav-tabs bookly:mb-3 bookly-js-notifications-tabs" role="tablist">
                <li class="bookly:nav-item">
                    <a class="bookly:nav-link<?php if ( $tab === 'notifications' ) : ?> bookly:active<?php endif ?>" href="<?php echo add_query_arg( array( 'page' => Notifications\Page::pageSlug() ), admin_url( 'admin.php' ) ) ?>" data-toggle="bookly-tab" data-tab="notifications"><?php esc_html_e( 'Notifications', 'bookly-responsive-appointment-booking-tool' ) ?></a>
                </li>
                <?php Notifications\Proxy\Pro::renderLogsTab( $tab ) ?>
                <li class="bookly:nav-item">
                    <a class="bookly:nav-link<?php if ( $tab === 'settings' ) : ?> bookly:active<?php endif ?>" href="<?php echo add_query_arg( array( 'page' => Notifications\Page::pageSlug(), 'tab' => 'settings' ), admin_url( 'admin.php' ) ) ?>" data-toggle="bookly-tab" data-tab="settings"><?php esc_html_e( 'Settings', 'bookly-responsive-appointment-booking-tool' ) ?></a>
                </li>
            </ul>
            <div class="bookly-js-notifications-wrap">
            </div>
        </div>
        <div class="bookly:card-footer bookly:justify-end bookly-js-notifications-footer" hidden>
            <?php Buttons::renderSubmit( null, 'bookly-js-save', __( 'Save', 'bookly-responsive-appointment-booking-tool' ) ) ?>
        </div>
    </div>
    <?php Dialogs\Notifications\Dialog::render() ?>
    <?php Dialogs\TableSettings\Dialog::render() ?>
</div>