<?php defined( 'ABSPATH' ) || exit; // Exit if accessed directly
use Bookly\Backend\Components\Settings\Selects;
use Bookly\Lib\Utils\DateTime;
use Bookly\Lib\Utils\Log;

/** @var array $options */
/** @var bool $debug */
?>
<div class="w-100 text-left">
    <div class="bookly-css-root mb-3">
        <div id="bookly-logs-datatables"></div>
    </div>
    <?php
    Selects::renderSingle( 'bookly_logs_expire', __( 'Keep logs', 'bookly-responsive-appointment-booking-tool' ), null, array( array( '7', sprintf( _n( '%d day', '%d days', 7, 'bookly-responsive-appointment-booking-tool' ), 7 ) ), array( '30', sprintf( _n( '%d day', '%d days', 30, 'bookly-responsive-appointment-booking-tool' ), 30 ) ), array( '90', sprintf( _n( '%d day', '%d days', 90, 'bookly-responsive-appointment-booking-tool' ), 90 ) ), array( '365', sprintf( _n( '%d day', '%d days', 365, 'bookly-responsive-appointment-booking-tool' ), 365 ) ) ) );
    ?>
    <?php if ( $debug ): ?>
        <div class="row">
            <?php foreach ( $options as $option_name ): ?>
                <?php $option = get_option( $option_name ) ?>
                <div class="bookly-js-optional-logs-entry col-auto" data-option="<?php echo esc_attr( $option_name ) ?>">
                    <div class="mb-2">
                        <strong><?php echo ucfirst( Log::getLogOptionTitle( $option_name ) ) ?> logs</strong>
                        <span class="text-success <?php if ( ! $option ) : ?> bookly-collapse<?php endif ?> bookly-js-optional-logs-active"> Active until <span class="bookly-js-optional-logs-until"><?php if ( $option ) echo DateTime::formatDate( $option ) ?></span></span>
                    </div>
                    <div class="btn-group">
                        <button type="button" class="btn btn-default bookly-js-enable-optional-logs" data-period="7" data-spinner-size="40" data-style="zoom-in" data-spinner-color="#666666">Enable logs</button>
                        <button type="button" class="btn btn-default bookly-dropdown-toggle bookly-dropdown-toggle-split" data-toggle="bookly-dropdown" aria-haspopup="true" aria-expanded="false"></button>
                        <ul class="bookly-dropdown-menu bookly-dropdown-menu-compact overflow-hidden bookly-js-tables-dropdown">
                            <li class="bookly-dropdown-header">Select log period</li>
                            <li class="bookly-dropdown-divider my-0 py-0"></li>
                            <li class="bookly-dropdown-item bookly-js-ladda bookly-js-enable-optional-logs" data-period="1" data-spinner-size="40" data-style="zoom-in" data-spinner-color="#666666">
                                <?php printf( _n( '%d day', '%d days', 1, 'bookly-responsive-appointment-booking-tool' ), 1 ) ?>
                            </li>
                            <li class="bookly-dropdown-item bookly-js-ladda bookly-js-enable-optional-logs" data-period="7" data-spinner-size="40" data-style="zoom-in" data-spinner-color="#666666">
                                <?php printf( _n( '%d day', '%d days', 5, 'bookly-responsive-appointment-booking-tool' ), 7 ) ?>
                            </li>
                            <li class="bookly-dropdown-item bookly-js-ladda bookly-js-enable-optional-logs" data-period="30" data-spinner-size="40" data-style="zoom-in" data-spinner-color="#666666">
                                <?php printf( _n( '%d day', '%d days', 30, 'bookly-responsive-appointment-booking-tool' ), 30 ) ?>
                            </li>
                            <li class="bookly-dropdown-item bookly-js-ladda bookly-js-enable-optional-logs" data-period="0" data-spinner-size="40" data-style="zoom-in" data-spinner-color="#666666">
                                <?php esc_html_e( 'Disable', 'bookly-responsive-appointment-booking-tool' ) ?>
                            </li>
                        </ul>
                    </div>
                </div>
            <?php endforeach ?>
        </div>
    <?php endif ?>
</div>
