<?php defined( 'ABSPATH' ) || exit; // Exit if accessed directly
use Bookly\Lib\Utils\Common;
/**
 * @var string $type
 * @var array $promotion
 */
?>
<div class="wrap bookly-css-root bookly-notice-wrap">
    <div id="bookly-sms-promotion-notice" class="bookly:alert <?php echo esc_attr( $type == 'registration' ? 'bookly:alert-success' : 'bookly:alert-info' ) ?>" data-id="<?php echo esc_attr( $promotion['id'] ) ?>" data-type="<?php echo esc_attr( $type ) ?>" data-dismiss="close">
        <svg class="bookly:alert-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/></svg>
        <div class="bookly:alert-content">
            <div>
                <?php switch ( $type ) :
                    case 'registration':
                    case 'first_recharge':
                        echo Common::stripScripts( $promotion['texts']['info'] );
                        break;
                    case 'manual': ?>
                        <b><?php printf( esc_html__( 'Recharge your account balance and get up to %s extra.', 'bookly-responsive-appointment-booking-tool' ), '$' . $promotion['amount'] ) ?></b>
                        <?php esc_html_e( 'Take advantage of Bookly Cloud products which increase customers\' loyalty and involvement.', 'bookly-responsive-appointment-booking-tool' ) ?>
                        <?php break ?>
                    <?php case 'auto': ?>
                        <b><?php printf( esc_html__( 'Enable Auto-Recharge and get up to %s extra.', 'bookly-responsive-appointment-booking-tool' ), '$' . $promotion['amount'] ) ?></b>
                        <?php esc_html_e( 'Let Bookly Cloud products continuously work without interruptions.', 'bookly-responsive-appointment-booking-tool' ) ?>
                        <?php break ?>
                <?php endswitch ?>
            </div>
            <div class="bookly:alert-actions">
                <button type="button" class="bookly:alert-btn bookly:alert-btn-primary bookly-js-apply-action"><?php
                    echo esc_html( $type == 'registration'
                        ? __( 'Register', 'bookly-responsive-appointment-booking-tool' )
                        : ( $type == 'auto' ? __( 'Enable', 'bookly-responsive-appointment-booking-tool' ) : __( 'Recharge', 'bookly-responsive-appointment-booking-tool' ) ) )
                ?></button>
                <button type="button" class="bookly:alert-btn bookly:alert-btn-outline bookly-js-remind-me-later"><?php esc_html_e( 'Remind me later', 'bookly-responsive-appointment-booking-tool' ) ?></button>
            </div>
        </div>
        <button type="button" class="bookly:alert-close" data-dismiss="alert" aria-label="<?php esc_attr_e( 'Close', 'bookly-responsive-appointment-booking-tool' ) ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
    </div>
</div>
