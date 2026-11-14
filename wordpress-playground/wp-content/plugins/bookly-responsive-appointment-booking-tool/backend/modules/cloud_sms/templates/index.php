<?php defined( 'ABSPATH' ) || exit; // Exit if accessed directly
use Bookly\Backend\Components\PageHeader\Renderer as PageHeaderRenderer;
use Bookly\Backend\Components\Dialogs\TableSettings;
use Bookly\Backend\Components\Notices\PromoBanner\Banner as PromoBanner;
use Bookly\Lib\Utils\DateTime;
/**
 * @var Bookly\Lib\Cloud\SMS $sms
 * @var int $undelivered_count
 */
?>
<div id="bookly-tbs" class="wrap bookly-css-root bookly-main-page-wrap">
    <?php PageHeaderRenderer::render( $self::pageSlug(), __( 'SMS Notifications', 'bookly-responsive-appointment-booking-tool' ) ) ?>
    <div class="bookly:card">
        <div class="bookly:card-body">
            <?php PromoBanner::render( array( PromoBanner::SMS_SENDER_ID, PromoBanner::SMS_CAMPAIGNS ) ) ?>
            <ul class="bookly:nav bookly:nav-tabs bookly:mb-3" id="sms_tabs">
                <li class="bookly:nav-item"><a class="bookly:nav-link" href="#notifications"><?php esc_html_e( 'Notifications', 'bookly-responsive-appointment-booking-tool' ) ?></a></li>
                <li class="bookly:nav-item"><a class="bookly:nav-link" href="#campaigns"><?php esc_html_e( 'Campaigns', 'bookly-responsive-appointment-booking-tool' ) ?></a></li>
                <li class="bookly:nav-item"><a class="bookly:nav-link" href="#sms_details"><?php esc_html_e( 'SMS Details', 'bookly-responsive-appointment-booking-tool' ); if ( $undelivered_count ) : ?> <span class="badge bg-danger"><?php echo esc_html( $undelivered_count ) ?></span><?php endif ?></a></li>
                <li class="bookly:nav-item"><a class="bookly:nav-link" href="#price_list"><?php esc_html_e( 'Price list', 'bookly-responsive-appointment-booking-tool' ) ?></a></li>
                <li class="bookly:nav-item"><a class="bookly:nav-link" href="#sender_id"><?php esc_html_e( 'Sender ID', 'bookly-responsive-appointment-booking-tool' ) ?></a></li>
            </ul>
            <div class="tab-content mt-3" id="sms_tabs_content">
                <div class="bookly:tab-pane" id="notifications"><?php include '_notifications.php' ?></div>
                <div class="bookly:tab-pane" id="campaigns"><?php include '_campaigns.php' ?></div>
                <div class="bookly:tab-pane" id="sms_details"><?php include '_sms_details.php' ?></div>
                <div class="bookly:tab-pane" id="price_list"><?php include '_price.php' ?></div>
                <div class="bookly:tab-pane" id="sender_id"><?php include '_sender_id.php' ?></div>
            </div>
        </div>
    </div>

    <?php TableSettings\Dialog::render() ?>
</div>