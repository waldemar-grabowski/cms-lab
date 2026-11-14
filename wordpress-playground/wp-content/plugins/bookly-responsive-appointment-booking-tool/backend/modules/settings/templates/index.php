<?php defined( 'ABSPATH' ) || exit; // Exit if accessed directly
use Bookly\Backend\Modules\Settings\Proxy;
use Bookly\Backend\Components;
?>
<div id="bookly-tbs" class="wrap bookly-css-root bookly-main-page-wrap">
    <?php Components\PageHeader\Renderer::render( $self::pageSlug(), __( 'Settings', 'bookly-responsive-appointment-booking-tool' ) ) ?>

    <div class="bookly:card bookly:overflow-hidden">
        <div class="bookly:flex bookly:flex-col bookly:sm:flex-row">
            <div id="bookly-sidebar" class="bookly:shrink-0 bookly:p-3 bookly:border-border bookly:border-b bookly:sm:border-b-0 bookly:sm:border-r">
                <div class="nav bookly:vnav" role="tablist">
                    <?php Components\Settings\Menu::renderItem( __( 'General', 'bookly-responsive-appointment-booking-tool' ), 'general' ) ?>
                    <?php Components\Settings\Menu::renderItem( __( 'URL Settings', 'bookly-responsive-appointment-booking-tool' ), 'url' ) ?>
                    <?php Components\Settings\Menu::renderItem( __( 'Calendar', 'bookly-responsive-appointment-booking-tool' ), 'calendar' ) ?>
                    <?php Components\Settings\Menu::renderItem( __( 'Company', 'bookly-responsive-appointment-booking-tool' ), 'company' ) ?>
                    <?php Components\Settings\Menu::renderItem( __( 'Customers', 'bookly-responsive-appointment-booking-tool' ), 'customers' ) ?>
                    <?php Components\Settings\Menu::renderItem( __( 'Appointments', 'bookly-responsive-appointment-booking-tool' ), 'appointments' ) ?>
                    <?php Proxy\Mailchimp::renderMenuItem() ?>
                    <?php Proxy\Pro::renderMenuItem( __( 'Google Calendar', 'bookly-responsive-appointment-booking-tool' ), 'google_calendar' ) ?>
                    <?php Proxy\Shared::renderMenuItem() ?>
                    <?php Proxy\Pro::renderMenuItem( __( 'Online Meetings', 'bookly-responsive-appointment-booking-tool' ), 'online_meetings' ) ?>
                    <?php Proxy\Pro::renderMenuItem( __( 'User Permissions', 'bookly-responsive-appointment-booking-tool' ), 'user_permissions' ) ?>
                    <?php Components\Settings\Menu::renderItem( __( 'Payments', 'bookly-responsive-appointment-booking-tool' ), 'payments' ) ?>
                    <?php Proxy\Pro::renderMenuItem( __( 'Additional', 'bookly-responsive-appointment-booking-tool' ), 'additional' ) ?>
                    <?php Components\Settings\Menu::renderItem( __( 'Business Hours', 'bookly-responsive-appointment-booking-tool' ), 'business_hours' ) ?>
                    <?php Components\Settings\Menu::renderItem( __( 'Holidays', 'bookly-responsive-appointment-booking-tool' ), 'holidays' ) ?>
                </div>
            </div>

            <div id="bookly_settings_controls" class="bookly:flex-1 bookly:min-w-0 bookly:[&_.card-footer]:border-t-0 bookly:[&_.card-footer]:pt-0">
                <?php // No tab is active in the markup — every pane stays hidden (.tab-pane{display:none})
                      // until settings.js activates the requested one, so opening ?tab=… never flashes
                      // the General tab. Covers add-on (proxy) panes too, since they're hidden as well. ?>
                <div class="tab-content">
                    <div class="tab-pane" id="bookly_settings_general">
                        <?php self::renderTemplate( '_generalForm', $values ) ?>
                    </div>
                    <div class="tab-pane" id="bookly_settings_url">
                        <?php include '_urlForm.php' ?>
                    </div>
                    <div class="tab-pane" id="bookly_settings_calendar">
                        <?php include '_calendarForm.php' ?>
                    </div>
                    <div class="tab-pane" id="bookly_settings_company">
                        <?php include '_companyForm.php' ?>
                    </div>
                    <div class="tab-pane" id="bookly_settings_customers">
                        <?php include '_customers.php' ?>
                    </div>
                    <div class="tab-pane" id="bookly_settings_appointments">
                        <?php self::renderTemplate( '_appointmentsForm', array( 'statuses' => $values['statuses'] ) ) ?>
                    </div>
                    <?php Proxy\Mailchimp::renderTab() ?>
                    <?php Proxy\Shared::renderTab() ?>
                    <?php Proxy\CustomStatuses::renderTab() ?>
                    <div class="tab-pane" id="bookly_settings_payments">
                        <?php include '_paymentsForm.php' ?>
                    </div>
                    <div class="tab-pane" id="bookly_settings_business_hours">
                        <?php include '_hoursForm.php' ?>
                    </div>
                    <div class="tab-pane" id="bookly_settings_holidays">
                        <?php include '_holidaysForm.php' ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
