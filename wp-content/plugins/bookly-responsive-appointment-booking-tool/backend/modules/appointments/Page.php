<?php
namespace Bookly\Backend\Modules\Appointments;

use Bookly\Lib;
use Bookly\Lib\Entities\CustomerAppointment;

class Page extends Lib\Base\Component
{
    /**
     * Render page.
     */
    public static function render()
    {
        self::enqueueStyles( array(
            'alias' => array( 'bookly-backend-globals', ),
        ) );

        self::enqueueScripts( array(
            'module' => array( 'js/appointments.js' => array( 'bookly-backend-globals' ) ),
            'frontend' => array( 'js/intlTelInput.min.js' => array( 'bookly-backend-globals' ) ),
            'backend' => array( 'js/booking-wizard.js' => array( 'bookly-backend-globals' ) ),
        ) );

        $datatables = Lib\Utils\Tables::getSettings( Lib\Utils\Tables::APPOINTMENTS );

        // Filter options data
        $staff_members = Lib\Entities\Staff::query( 's' )->select( 's.id, s.full_name' )->whereNot( 'visibility', 'archive' )->fetchArray();
        // When the customer base is large we don't preload the whole list into the
        // filter dropdown — it switches to remote (typeahead) mode backed by the
        // bookly_get_customers_list AJAX endpoint. Otherwise the static list is used.
        $customers_remote = Lib\Entities\Customer::query()->count() >= Lib\Entities\Customer::REMOTE_LIMIT;
        $customers = $customers_remote
            ? array()
            : Lib\Entities\Customer::query( 'c' )->select( 'c.id, c.full_name' )->fetchArray();
        $services = Lib\Entities\Service::query( 's' )->select( 's.id, s.title' )->where( 'type', Lib\Entities\Service::TYPE_SIMPLE )->fetchArray();

        $locations = Proxy\Locations::getFilterOptions();
        if ( ! is_array( $locations ) ) {
            $locations = array();
        }

        $statuses = array();
        foreach ( CustomerAppointment::getStatuses() as $status ) {
            $statuses[] = array(
                'value' => $status,
                'label' => CustomerAppointment::statusToString( $status ),
            );
        }

        wp_localize_script( 'bookly-appointments.js', 'BooklyL10n', array(
            'datePicker' => Lib\Utils\DateTime::datePickerOptions(),
            'dateRange' => Lib\Utils\DateTime::dateRangeOptions( array( 'anyTime' => __( 'Any time', 'bookly-responsive-appointment-booking-tool' ), 'createdAtAnyTime' => __( 'Created at any time', 'bookly-responsive-appointment-booking-tool' ), ) ),
            'are_you_sure' => __( 'Are you sure?', 'bookly-responsive-appointment-booking-tool' ),
            'search' => __( 'Quick search by ID, customer, staff, service', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'zeroRecords' => __( 'No appointments for selected period.', 'bookly-responsive-appointment-booking-tool' ),
            'processing' => __( 'Processing', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'edit' => __( 'Edit', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'no_result_found' => __( 'No results found', 'bookly-responsive-appointment-booking-tool' ),
            'new_appointment' => __( 'New appointment', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'empty_title' => __( 'No appointments yet', 'bookly-responsive-appointment-booking-tool' ),
            'empty_text' => __( 'Appointments booked online or created here will appear in this list.', 'bookly-responsive-appointment-booking-tool' ),
            'searching' => __( 'Searching', 'bookly-responsive-appointment-booking-tool' ),
            'attachments' => __( 'Attachments', 'bookly-responsive-appointment-booking-tool' ),
            'tasks' => array(
                'enabled' => Lib\Config::tasksActive(),
                'title' => Proxy\Tasks::getFilterText(),
            ),
            'filters' => array(
                'date' => __( 'Date', 'bookly-responsive-appointment-booking-tool' ),
                'created' => __( 'Created', 'bookly-responsive-appointment-booking-tool' ),
                'status' => __( 'Status', 'bookly-responsive-appointment-booking-tool' ),
                'customer' => __( 'Customer', 'bookly-responsive-appointment-booking-tool' ),
                'staff' => __( 'Staff', 'bookly-responsive-appointment-booking-tool' ),
                'service' => __( 'Service', 'bookly-responsive-appointment-booking-tool' ),
                'location' => __( 'Location', 'bookly-responsive-appointment-booking-tool' ),
                'searchPlaceholder' => __( 'Search', 'bookly-responsive-appointment-booking-tool' ). '…',
                'noLocation' => __( 'W/o location', 'bookly-responsive-appointment-booking-tool' ),
            ),
            'filterOptions' => array(
                'staff' => $staff_members,
                'customers' => $customers,
                'customersRemote' => $customers_remote,
                'services' => $services,
                'locations' => $locations,
                'statuses' => $statuses,
            ),
            'delete' => __( 'Delete', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'reschedule' => __( 'Reschedule', 'bookly-responsive-appointment-booking-tool' ),
            // An appointment without a time is a task: the wizard does not move it, it
            // gives it a time.
            'setTime' => __( 'Set time', 'bookly-responsive-appointment-booking-tool' ),
            'export' => __( 'Export', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'print' => __( 'Print', 'bookly-responsive-appointment-booking-tool' ) . '…',
            'reorder' => _x( 'Reorder', 'order of elements', 'bookly-responsive-appointment-booking-tool' ),
            'proEnabled' => Lib\Config::proActive(),
            'createWithWizard' => get_option( 'bookly_appointments_create_with_wizard', '1' ) === '1',
            'datatables' => $datatables,
        ) );
        wp_localize_script( 'bookly-booking-wizard.js', 'BooklyL10nBookingWizard', \Bookly\Backend\Components\Dialogs\BookingWizard\Ajax::getL10n() );

        self::renderTemplate( 'index', compact( 'datatables' ) );
    }
}