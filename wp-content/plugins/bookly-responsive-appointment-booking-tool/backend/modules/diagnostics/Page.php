<?php
namespace Bookly\Backend\Modules\Diagnostics;

use Bookly\Lib;

class Page extends Lib\Base\Component
{
    /**
     * Render page.
     */
    public static function render()
    {
        self::enqueueStyles( array(
            'backend' => array(
                'css/fontawesome-all.min.css' => array( 'bookly-backend-globals' ),
            ),
            'module' => array( 'css/style.css' ),
        ) );

        self::enqueueScripts( array(
            'module' => array(
                'js/diagnostics.js' => array( 'bookly-backend-globals' ),
                'js/database.js' => array( 'bookly-backend-globals' ),
                'js/import-appointments.js' => array( 'bookly-backend-globals' ),
            ),
        ) );

        $debug = self::hasParameter( 'debug' );

        $tools = array();
        foreach ( glob( __DIR__ . '/tools/*.php' ) as $path ) {
            $test = basename( $path, '.php' );
            if ( $test !== 'Tool' ) {
                $class_name = '\Bookly\Backend\Modules\Diagnostics\Tools\\' . $test;
                if ( class_exists( $class_name, true ) ) {
                    $class = new $class_name;
                    if ( ! $class->isHidden() || $debug ) {
                        $tools[] = $class;
                    }
                }
            }
        }

        usort( $tools, static function( $a, $b ) {
            if ( $a->position === null && $b->position !== null ) {
                return 1;
            }
            if ( $a->position !== null && $b->position === null ) {
                return -1;
            }

            return $a->position > $b->position ? 1 : -1;
        } );

        $tests = array();
        foreach ( glob( __DIR__ . '/tests/*.php' ) as $path ) {
            $test = basename( $path, '.php' );
            if ( $test !== 'Test' ) {
                $class_name = '\Bookly\Backend\Modules\Diagnostics\Tests\\' . $test;
                if ( class_exists( $class_name, true ) ) {
                    $class = new $class_name;
                    if ( ! $class->isHidden() || $debug ) {
                        $tests[] = $class;
                    }
                }
            }
        }
        $rollback_data = get_option( 'bookly_import_rollback_data' );
        wp_localize_script( 'bookly-import-appointments.js', 'BooklyL10nDiagnostics', array(
            'statuses' => Lib\Entities\CustomerAppointment::getStatuses(),
            'rollback' => $rollback_data ? Lib\Utils\DateTime::formatDateTime( $rollback_data['date'] ) : null,
            'l10n' => array(
                'file_label' => __( 'Import file', 'bookly-responsive-appointment-booking-tool' ),
                'delimiter' => __( 'Delimiter', 'bookly-responsive-appointment-booking-tool' ),
                'service_name_column' => __( 'Service name', 'bookly-responsive-appointment-booking-tool' ),
                'start_date_column' => __( 'Start date', 'bookly-responsive-appointment-booking-tool' ),
                'end_date_column' => __( 'End date / Duration', 'bookly-responsive-appointment-booking-tool' ),
                'staff_name_column' => __( 'Staff name', 'bookly-responsive-appointment-booking-tool' ),
                'client_name_column' => __( 'Client name', 'bookly-responsive-appointment-booking-tool' ),
                'client_email_column' => __( 'Client email', 'bookly-responsive-appointment-booking-tool' ),
                'client_phone_column' => __( 'Client phone', 'bookly-responsive-appointment-booking-tool' ),
                'price_column' => __( 'Price', 'bookly-responsive-appointment-booking-tool' ),
                'status_column' => __( 'Status', 'bookly-responsive-appointment-booking-tool' ),
                'import' => __( 'Import', 'bookly-responsive-appointment-booking-tool' ),
                'back' => __( 'Back', 'bookly-responsive-appointment-booking-tool' ),
                'done' => __( 'Done', 'bookly-responsive-appointment-booking-tool' ),
                'minutes' => __( 'minutes', 'bookly-responsive-appointment-booking-tool' ),
                'custom' => __( 'Custom', 'bookly-responsive-appointment-booking-tool' ),
                'empty_value' => __( 'Empty value', 'bookly-responsive-appointment-booking-tool' ),
                'warning_text' => sprintf( '<b>%s</b><br/>• %s<br/>• %s<br/>• %s<br/>• %s<br/>• %s',
                    __( 'By proceeding with the import process, the following actions will be performed:', 'bookly-responsive-appointment-booking-tool' ),
                    __( 'New staff members, services, customers, appointments and payments will be created based on the data in your CSV file.', 'bookly-responsive-appointment-booking-tool' ),
                    __( 'Services will be linked to staff members, and customers will be linked to their appointments.', 'bookly-responsive-appointment-booking-tool' ),
                    __( 'If exact service duration data is unavailable, durations will be calculated based on the start and end times of appointments.', 'bookly-responsive-appointment-booking-tool' ),
                    __( 'If a service, staff member, or customer already exists, the existing records will be used to avoid duplication.', 'bookly-responsive-appointment-booking-tool' ),
                    __( 'Instant notifications will not be sent, but all types of reminders will be sent.', 'bookly-responsive-appointment-booking-tool' )
                ),
                'info_text' => sprintf( '<b>%s</b><br/>• %s<br/>• %s<br/>• %s<br/>• %s',
                    __( 'Please note:', 'bookly-responsive-appointment-booking-tool' ),
                    __( 'Imported appointments will not be synced with external calendars.', 'bookly-responsive-appointment-booking-tool' ),
                    __( 'You must fully understand the data you are importing and its impact.', 'bookly-responsive-appointment-booking-tool' ),
                    __( 'It is strongly recommended to back up your database before proceeding, as there will be no straightforward way to undo the changes made during the import.', 'bookly-responsive-appointment-booking-tool' ),
                    __( 'If you\'re importing a large CSV file, make sure to check your server limits, as exceeding them may disrupt the import.', 'bookly-responsive-appointment-booking-tool' ) . ' ' . __( 'If you still need to import a large file, you can split it into several parts and upload them one by one.', 'bookly-responsive-appointment-booking-tool' )
                ),
                'choose_file' => __( 'Choose CSV file to import', 'bookly-responsive-appointment-booking-tool'),
                'doc_link' => sprintf( __( 'You can find full import guide in <a href="%s" target=_blank>our documentation</a>.', 'bookly-responsive-appointment-booking-tool' ), 'https://hub.bookly.pro/go/bookly-import-guide' ),
                'understand' => __( 'I understand that I am proceeding with the import at my own risk.', 'bookly-responsive-appointment-booking-tool' ),
                'proceed' => __( 'Proceed', 'bookly-responsive-appointment-booking-tool' ),
                'header_text' => sprintf( '%s %s', __( 'When importing data from a CSV file, map the columns in your file to the corresponding fields in Bookly.', 'bookly-responsive-appointment-booking-tool' ), __( 'Use the dropdown menus and input fields to specify which data from your CSV should be imported to each field.', 'bookly-responsive-appointment-booking-tool' ) ),
                'duration_help' => __( 'Set the corresponding duration in minutes.', 'bookly-responsive-appointment-booking-tool' ),
                'prices_help' => __( 'Set the corresponding price in your currency.', 'bookly-responsive-appointment-booking-tool' ),
                'statuses_help' => __( 'If the imported file does not contain a column for the status value, select Custom and set a default status for appointments.', 'bookly-responsive-appointment-booking-tool' ),
                'required' => __( 'required', 'bookly-responsive-appointment-booking-tool' ),
                'error' => __( 'Something went wrong', 'bookly-responsive-appointment-booking-tool' ),
                'rollback_text' => sprintf( '<b>%s</b><br/><br/>%s', __( 'You have a previously imported file detected.', 'bookly-responsive-appointment-booking-tool' ), __( 'If you encountered any issues during the import, you can roll back the import of this file.', 'bookly-responsive-appointment-booking-tool' ) ),
                'rollback' => __( 'Rollback', 'bookly-responsive-appointment-booking-tool' ),
                'import_success' => __( 'Import successful', 'bookly-responsive-appointment-booking-tool' ),
                'import_results' => __( 'Import results', 'bookly-responsive-appointment-booking-tool' ),
                'staff_count' => __( 'Staff members imported', 'bookly-responsive-appointment-booking-tool' ),
                'services_count' => __( 'Services imported', 'bookly-responsive-appointment-booking-tool' ),
                'customers_count' => __( 'Clients imported', 'bookly-responsive-appointment-booking-tool' ),
                'appointments_count' => __( 'Appointments imported', 'bookly-responsive-appointment-booking-tool' ),
                'are_you_sure' => __( 'Are you sure?', 'bookly-responsive-appointment-booking-tool' ),
            )
        ) );

        self::renderTemplate( 'index', compact( 'tests', 'tools', 'debug' ) );
    }

    /**
     * Show 'Diagnostics' submenu inside Bookly main menu
     */
    public static function addBooklyMenuItem()
    {
        $title = __( 'Diagnostics', 'bookly-responsive-appointment-booking-tool' );
        add_submenu_page(
            'bookly-menu', $title, $title, Lib\Utils\Common::getRequiredCapability(),
            self::pageSlug(), function() { Page::render(); }
        );
    }
}