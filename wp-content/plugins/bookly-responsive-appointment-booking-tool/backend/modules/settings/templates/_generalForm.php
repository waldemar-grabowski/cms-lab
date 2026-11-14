<?php defined( 'ABSPATH' ) || exit; // Exit if accessed directly
use Bookly\Backend\Components\Controls\Buttons;
use Bookly\Backend\Components\Controls\Inputs as ControlsInputs;
use Bookly\Backend\Components\Settings\Inputs;
use Bookly\Backend\Components\Settings\Selects;
use Bookly\Backend\Components\Dialogs;
use Bookly\Backend\Modules\Settings\Proxy;

/** @var array $bookly_gen_time_slot_length */
/** @var array $statuses */
?>
    <form method="post" action="<?php echo esc_url( add_query_arg( 'tab', 'general' ) ) ?>">
        <div class="card-body">
            <?php
            Selects::renderSingle( 'bookly_gen_delete_data_on_uninstall', __( 'Bookly data upon deleting Bookly items', 'bookly-responsive-appointment-booking-tool' ), __( 'If you choose Delete, all data associated with Bookly will be permanently deleted when deleting Bookly items (Bookly, Bookly Pro, or any Bookly add-on)', 'bookly-responsive-appointment-booking-tool' ), array( array( 0, __( 'Don\'t delete', 'bookly-responsive-appointment-booking-tool' ) ), array( 1, __( 'Delete', 'bookly-responsive-appointment-booking-tool' ) ), ) );
            Selects::renderSingle( 'bookly_gen_time_slot_length', __( 'Time slot length', 'bookly-responsive-appointment-booking-tool' ), __( 'Select a time interval which will be used as a step when building all time slots in the system.', 'bookly-responsive-appointment-booking-tool' ), $bookly_gen_time_slot_length );
            Selects::renderSingle( 'bookly_gen_service_duration_as_slot_length', __( 'Set slot length as service duration', 'bookly-responsive-appointment-booking-tool' ), __( 'Enable this option to make slot length equal to service duration at the Time step of booking form.', 'bookly-responsive-appointment-booking-tool' ) );
            Proxy\Pro::renderMinimumTimeRequirement();
            Inputs::renderNumber( 'bookly_gen_max_days_for_booking', __( 'Number of days available for booking', 'bookly-responsive-appointment-booking-tool' ), __( 'Set how far in the future the clients can book appointments.', 'bookly-responsive-appointment-booking-tool' ), 1, 1 );
            Selects::renderSingle( 'bookly_gen_use_client_time_zone', __( 'Display available time slots in client\'s time zone', 'bookly-responsive-appointment-booking-tool' ), __( 'The value is taken from client\'s browser.', 'bookly-responsive-appointment-booking-tool' ) );
            Selects::renderSingle( 'bookly_gen_allow_staff_edit_profile', __( 'Allow staff members to edit their profiles', 'bookly-responsive-appointment-booking-tool' ), __( 'If this option is enabled then all staff members who are associated with WordPress users will be able to edit their own profiles, services, schedule and days off.', 'bookly-responsive-appointment-booking-tool' ) );
            Selects::renderSingle( 'bookly_gen_link_assets_method', __( 'Method to include Bookly JavaScript and CSS files on the page', 'bookly-responsive-appointment-booking-tool' ), sprintf( __( 'Select method how to include Bookly JavaScript and CSS files on the page. For more information, see the <a href="%s" target="_blank">documentation</a> page.', 'bookly-responsive-appointment-booking-tool' ), 'https://hub.bookly.pro/go/bookly-settings-general' ), array(
                    array(
                            'enqueue',
                            __( 'All pages', 'bookly-responsive-appointment-booking-tool' ),
                    ),
                    array( 'print', __( 'Pages with Bookly form', 'bookly-responsive-appointment-booking-tool' ) ),
            ) );
            Selects::renderSingle( 'bookly_gen_collect_stats', __( 'Help us improve Bookly by sending anonymous usage stats', 'bookly-responsive-appointment-booking-tool' ) );
            Selects::renderSingle( 'bookly_gen_show_powered_by', __( 'Powered by Bookly', 'bookly-responsive-appointment-booking-tool' ), __( 'Allow the plugin to set a Powered by Bookly notice on the booking widget to spread information about the plugin. This will allow the team to improve the product and enhance its functionality', 'bookly-responsive-appointment-booking-tool' ) );
            Selects::renderSingle( 'bookly_gen_prevent_caching', __( 'Prevent caching of pages with booking form', 'bookly-responsive-appointment-booking-tool' ), __( 'Select "Enabled" if you want Bookly to prevent caching by third-party caching plugins by adding a DONOTCACHEPAGE constant on pages with booking form', 'bookly-responsive-appointment-booking-tool' ) );
            Selects::renderSingle( 'bookly_gen_badge_consider_news', __( 'Show news notifications', 'bookly-responsive-appointment-booking-tool' ), __( 'If enabled, News notification icon will be displayed', 'bookly-responsive-appointment-booking-tool' ) );
            Selects::renderSingle( 'bookly_email_gateway', __( 'Mail gateway', 'bookly-responsive-appointment-booking-tool' ), sprintf( __( 'Select a mail gateway that will be used to send email notifications. For more information, see the <a href="%s" target="_blank">documentation</a> page.', 'bookly-responsive-appointment-booking-tool' ), 'https://hub.bookly.pro/go/bookly-settings-smtp' ), array( array( 'wp', __( 'WordPress mail', 'bookly-responsive-appointment-booking-tool' ), 0 ), array( 'smtp', 'SMTP', 0 ) ), array( 'data-expand' => 'smtp' ) );
            ?>
            <div id="bookly-smtp-settings"
                 class="border-left mt-3 ml-4 pl-3 bookly_email_gateway-expander"<?php if ( get_option( 'bookly_email_gateway', 'wp' ) === 'wp' ) : ?> style="display:none;"<?php endif ?>>
                <?php
                Inputs::renderText( 'bookly_smtp_host', __( 'Hostname', 'bookly-responsive-appointment-booking-tool' ) );
                Inputs::renderText( 'bookly_smtp_port', __( 'Port', 'bookly-responsive-appointment-booking-tool' ) );
                Inputs::renderText( 'bookly_smtp_user', __( 'Username', 'bookly-responsive-appointment-booking-tool' ) );
                Inputs::renderPassword( 'bookly_smtp_password', __( 'Password', 'bookly-responsive-appointment-booking-tool' ) );
                Selects::renderSingle( 'bookly_smtp_secure', __( 'Secure', 'bookly-responsive-appointment-booking-tool' ), null, array( array( 'none', __( 'Disabled', 'bookly-responsive-appointment-booking-tool' ), 0 ), array( 'ssl', 'SSL', 0 ), array( 'tls', 'TLS', 0 ) ) );
                Buttons::render( 'bookly-test-smtp', 'btn-info mt-2', __( 'Test', 'bookly-responsive-appointment-booking-tool' ), array( 'data-toggle' => 'bookly-modal', 'data-target' => '#bookly-smtp-test-modal' ) );
                ?>
            </div>
        </div>
        <div class="card-footer bg-transparent d-flex justify-content-end">
            <?php ControlsInputs::renderCsrf() ?>
            <?php Buttons::renderSubmit() ?>
            <?php Buttons::renderReset( null, 'ml-2' ) ?>
        </div>
    </form>
<?php Dialogs\SmtpTest\Dialog::render() ?>