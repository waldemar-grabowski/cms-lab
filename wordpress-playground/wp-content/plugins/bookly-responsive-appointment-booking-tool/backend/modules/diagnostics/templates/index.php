<?php defined( 'ABSPATH' ) || exit; // Exit if accessed directly
use Bookly\Backend\Components\PageHeader\Renderer as PageHeaderRenderer;
use Bookly\Backend\Modules\Diagnostics\Tests\Test;
use Bookly\Backend\Modules\Diagnostics\Tools\Tool;
use Bookly\Lib;

/** @var Test[] $tests */
/** @var array $tools */
?>
<div id="bookly-tbs" class="wrap bookly-css-root bookly-main-page-wrap">
    <?php
    // Autorun is ON by default — tests run automatically on load. The header
    // shows "Stop autorun" while it's on and "Run tests" once stopped; the two
    // toggle each other (see diagnostics.js). Initial visibility comes from the
    // cookie so the right button paints immediately.
    $autorun_off = isset( $_COOKIE['bookly_diagnostic_autorun_tests'] ) && $_COOKIE['bookly_diagnostic_autorun_tests'] === '0';
    PageHeaderRenderer::render( $self::pageSlug(), __( 'Diagnostics', 'bookly-responsive-appointment-booking-tool' ), array(
        array(
            'icon'   => 'stop',
            'label'  => __( 'Stop autorun', 'bookly-responsive-appointment-booking-tool' ),
            'class'  => 'bookly-js-stop-autorun',
            'hidden' => $autorun_off,
        ),
        array(
            'icon'   => 'play',
            'label'  => __( 'Run tests', 'bookly-responsive-appointment-booking-tool' ),
            'class'  => 'bookly-js-run-autorun',
            'hidden' => ! $autorun_off,
        ),
    ) ) ?>
    <div class="bookly:card">
        <div class="bookly:card-body bookly-js-tests pb-2">
            <?php foreach ( $tools as $tool ) : ?>
                <?php /** @var Tool $tool */ ?>
                <div class="card bookly-collapse-with-arrow bookly-js-tool">
                    <div class="card-header bg-light d-flex align-items-center bookly-collapsed bookly-cursor-pointer" href="#<?php echo esc_attr( $tool->getSlug() ) ?>" data-toggle="bookly-collapse" style="min-height: 62px;">
                        <div class="d-flex w-100 align-items-center">
                            <div class="flex-fill bookly-collapse-title bookly-js-test-title"><?php echo esc_html( $tool->getTitle() ) ?></div>
                            <?php if ( $tool->hasError() ) : ?>
                                <button class="btn btn-danger bookly-cursor-default bookly-js-has-error" type="button" disabled>
                                    <?php esc_html_e( 'Error', 'bookly-responsive-appointment-booking-tool' ) ?>
                                </button>
                            <?php endif ?>
                        </div>
                    </div>
                    <div id="<?php echo esc_attr( $tool->getSlug() ) ?>" class="bookly-collapse">
                        <div class="card-body">
                            <?php echo Lib\Utils\Common::html( $tool->render() ) ?>
                        </div>
                    </div>
                </div>
            <?php endforeach ?>
            <?php foreach ( $tests as $test ) : ?>
                <div class="card bookly-collapse-with-arrow bookly-js-test" data-test="<?php echo esc_attr( $test->getSlug() ) ?>" data-class="<?php echo esc_attr( basename( str_replace( '\\', '/', get_class( $test ) ) ) ) ?>" data-error-type="<?php echo esc_attr( $test->getErrorType() ) ?>">
                    <div class="card-header bg-white d-flex align-items-center bookly-collapsed bookly-cursor-pointer" href="#<?php echo esc_attr( $test->getSlug() ) ?>" data-toggle="bookly-collapse">
                        <div class="d-flex w-100 align-items-center">
                            <div class="flex-fill bookly-collapse-title bookly-js-test-title"><?php echo esc_html( $test->getTitle() ) ?></div>
                            <div class="bookly-js-status-test">
                                <button class="btn btn-success mr-2 bookly-js-success-test bookly-cursor-default" type="button" disabled style="display: none; width: 140px;">
                                    <?php esc_html_e( 'Success', 'bookly-responsive-appointment-booking-tool' ) ?>
                                </button>
                                <button class="btn btn-danger mr-2 bookly-js-failed-test bookly-cursor-default" type="button" disabled style="display: none; width: 140px;">
                                    <?php esc_html_e( 'Failed', 'bookly-responsive-appointment-booking-tool' ) ?>
                                </button>
                                <button class="btn btn-warning mr-2 bookly-js-warning-test bookly-cursor-default" type="button" disabled style="display: none; width: 140px;">
                                    <?php esc_html_e( 'Warning', 'bookly-responsive-appointment-booking-tool' ) ?>
                                </button>
                            </div>
                            <div class="bookly-js-button-test" style="min-height: 40px">
                                <button class="btn btn-default bookly-js-loading-test" type="button" disabled style="display: none;">
                                    <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                                </button>
                                <button class="btn btn-default bookly-js-reload-test" type="button" style="display: none;">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div id="<?php echo esc_attr( $test->getSlug() ) ?>" class="bookly-collapse">
                        <div class="card-body">
                            <?php echo Lib\Utils\Common::html( $test->getDescription() ) ?>
                            <div class="bookly-js-test-errors text-danger w-100 mt-2"></div>
                        </div>
                    </div>
                </div>
            <?php endforeach ?>
        </div>
    </div>
</div>