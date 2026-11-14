<?php
namespace Bookly\Backend\Modules\CloudBilling;

use Bookly\Lib;
use Bookly\Backend\Components;
use Bookly\Backend\Modules;

class Page extends Lib\Base\Component
{
    /**
     * Render page.
     */
    public static function render()
    {
        $cloud = Lib\Cloud\API::getInstance();
        if ( ! $cloud->account->loadProfile() ) {
            Components\Cloud\LoginRequired\Page::render( __( 'Bookly Cloud Billing', 'bookly-responsive-appointment-booking-tool' ), self::pageSlug() );
        } else {
            self::enqueueStyles( array(
                'alias'   => array( 'bookly-backend-globals', ),
            ) );

            self::enqueueScripts( array(
                // The invoice details dialog lives in the account page's bundle
                'bookly' => array( 'backend/modules/cloud_settings/resources/js/cloud-settings.js' => array( 'bookly-backend-globals' ) ),
                'module' => array( 'js/cloud-billing.js' => array( 'bookly-cloud-settings.js' ), ),
            ) );

            $datatables = Lib\Utils\Tables::getSettings( Lib\Utils\Tables::CLOUD_PURCHASES );

            $invoice_data = Lib\Cloud\API::getInstance()->account->getInvoiceData();

            wp_localize_script( 'bookly-cloud-billing.js', 'BooklyL10n', array(
                'zeroRecords' => __( 'No records for selected period.', 'bookly-responsive-appointment-booking-tool' ),
                'search'      => __( 'Quick search', 'bookly-responsive-appointment-booking-tool' ),
                'datePicker'  => Lib\Utils\DateTime::datePickerOptions(),
                'dateRange'   => Lib\Utils\DateTime::dateRangeOptions( array( 'lastMonth' => __( 'Last month', 'bookly-responsive-appointment-booking-tool' ), ) ),
                'filters'     => array(
                    'date' => __( 'Date', 'bookly-responsive-appointment-booking-tool' ),
                ),
                'invoice'     => array(
                    'button' => __( 'Invoice', 'bookly-responsive-appointment-booking-tool' ),
                    'alert'  => __( 'To generate an invoice, please enter your company details first.', 'bookly-responsive-appointment-booking-tool' ),
                    'link'   => $cloud->account->getInvoiceLink(),
                    'valid'  => isset ( $invoice_data['company_name'], $invoice_data['company_address'] ) && $invoice_data['company_name'] != '' && $invoice_data['company_address'] != '',
                    // Who the invoices are made out to — a dialog from the table toolbar
                    'details_button' => __( 'Invoice details', 'bookly-responsive-appointment-booking-tool' ) . '…',
                    'details' => $invoice_data,
                    'email' => $cloud->account->getUserName(),
                    'l10n' => Modules\CloudSettings\Page::getInvoiceL10n(),
                ),
                'datatables'  => $datatables,
            ) );

            self::renderTemplate( 'index', compact( 'datatables' ) );
        }
    }
}
