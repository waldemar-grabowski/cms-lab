<?php
namespace Bookly\Backend\Components\Gutenberg\BooklyForm;

use Bookly\Lib;

class Block extends Lib\Base\Block
{
    /**
     * @inheritDoc
     */
    public static function registerBlockType()
    {
        self::enqueueScripts( array(
            'module' => array(
                'js/booking-form-block.js' => array( 'jquery', 'wp-blocks', 'wp-components', 'wp-element', 'wp-editor', 'bookly-backend-globals' ),
            ),
        ) );

        self::enqueueData( array(
            'casest',
            'custom_location_settings',
        ) );

        wp_localize_script( 'bookly-booking-form-block.js', 'BooklyFormL10n', array(
            'block' => array(
                'title' => 'Bookly - ' . __( 'Booking form', 'bookly-responsive-appointment-booking-tool' ),
                'description' => __( 'A custom block for displaying booking form', 'bookly-responsive-appointment-booking-tool' ),
            ),
            'selectLocation' => __( 'Select location', 'bookly-responsive-appointment-booking-tool' ),
            'selectCategory' => __( 'Select category', 'bookly-responsive-appointment-booking-tool' ),
            'selectService' => __( 'Select service', 'bookly-responsive-appointment-booking-tool' ),
            'any' => __( 'Any', 'bookly-responsive-appointment-booking-tool' ),
            'formFields' => __( 'Form fields', 'bookly-responsive-appointment-booking-tool' ),
            'location' => __( 'Default value for location', 'bookly-responsive-appointment-booking-tool' ),
            'category' => __( 'Default value for category', 'bookly-responsive-appointment-booking-tool' ),
            'service' => __( 'Default value for service', 'bookly-responsive-appointment-booking-tool' ),
            'staff' => __( 'Default value for employee', 'bookly-responsive-appointment-booking-tool' ),
            'nop' => __( 'Number of persons', 'bookly-responsive-appointment-booking-tool' ),
            'quantity' => __( 'Quantity', 'bookly-responsive-appointment-booking-tool' ),
            'date' => __( 'Date', 'bookly-responsive-appointment-booking-tool' ),
            'weekDays' => __( 'Week days', 'bookly-responsive-appointment-booking-tool' ),
            'timeRange' => __( 'Time range', 'bookly-responsive-appointment-booking-tool' ),
            'hide' => __( 'hide', 'bookly-responsive-appointment-booking-tool' ),
            'fields' => __( 'Fields', 'bookly-responsive-appointment-booking-tool' ),
            'duration' => __( 'Duration', 'bookly-responsive-appointment-booking-tool' ),
            'serviceHelp' => __( 'Please be aware that a value in this field is required in the frontend. If you choose to hide this field, please be sure to select a default value for it', 'bookly-responsive-appointment-booking-tool' ),
        ) );

        register_block_type( 'bookly/form-block', array(
            'editor_script' => 'bookly-booking-form-block.js',
        ) );
    }
}