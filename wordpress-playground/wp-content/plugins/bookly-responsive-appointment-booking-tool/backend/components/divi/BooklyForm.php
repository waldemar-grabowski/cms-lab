<?php
namespace Bookly\Backend\Components\Divi;

use Bookly\Lib\Config;

class BooklyForm extends \ET_Builder_Module
{
    public $slug = 'bookly_divi_form';

    /** @var array */
    private $categories = array();
    /** @var array */
    private $services = array();
    /** @var array */
    private $staff = array();

    public function init()
    {
        $this->name = 'Bookly - ' . esc_html__( 'Step-by-step form', 'bookly-responsive-appointment-booking-tool' );

        if ( ! is_admin() ) {
            return;
        }

        $casest = Config::getCaSeSt();
        $this->categories = array( 0 => __( 'Select category', 'bookly-responsive-appointment-booking-tool' ) );
        $this->services = array( 0 => __( 'Select service', 'bookly-responsive-appointment-booking-tool' ) );
        $this->staff = array( 0 => __( 'Any', 'bookly-responsive-appointment-booking-tool' ) );

        foreach ( $casest['categories'] as $category ) {
            $this->categories[ $category['id'] ] = $category['name'];
        }
        foreach ( $casest['services'] as $service ) {
            $this->services[ $service['id'] ] = $service['name'];
        }
        foreach ( $casest['staff'] as $value ) {
            $this->staff[ $value['id'] ] = $value['name'];
        }
    }

    public function get_fields()
    {
        $fields = array();

        $fields['category_id'] = array(
            'label' => __( 'Category', 'bookly-responsive-appointment-booking-tool' ),
            'type' => 'select',
            'options' => $this->categories,
            'default' => '0',
            'toggle_slug' => 'main_content',
        );
        $fields['hide_categories'] = array(
            'label' => __( 'Hide category', 'bookly-responsive-appointment-booking-tool' ),
            'type' => 'yes_no_button',
            'options' => array(
                'off' => esc_html__( 'No', 'bookly-responsive-appointment-booking-tool' ),
                'on' => esc_html__( 'Yes', 'bookly-responsive-appointment-booking-tool' ),
            ),
            'default' => 'off',
            'toggle_slug' => 'main_content',
        );
        $fields['service_id'] = array(
            'label' => __( 'Service', 'bookly-responsive-appointment-booking-tool' ),
            'type' => 'select',
            'options' => $this->services,
            'default' => '0',
            'toggle_slug' => 'main_content',
        );
        $fields['hide_services'] = array(
            'label' => __( 'Hide service', 'bookly-responsive-appointment-booking-tool' ),
            'type' => 'yes_no_button',
            'options' => array(
                'off' => esc_html__( 'No', 'bookly-responsive-appointment-booking-tool' ),
                'on' => esc_html__( 'Yes', 'bookly-responsive-appointment-booking-tool' ),
            ),
            'default' => 'off',
            'toggle_slug' => 'main_content',
        );
        $fields['staff_member_id'] = array(
            'label' => __( 'Staff', 'bookly-responsive-appointment-booking-tool' ),
            'type' => 'select',
            'options' => $this->staff,
            'default' => '0',
            'toggle_slug' => 'main_content',
        );
        $fields['hide_staff_members'] = array(
            'label' => __( 'Hide employee', 'bookly-responsive-appointment-booking-tool' ),
            'type' => 'yes_no_button',
            'options' => array(
                'off' => esc_html__( 'No', 'bookly-responsive-appointment-booking-tool' ),
                'on' => esc_html__( 'Yes', 'bookly-responsive-appointment-booking-tool' ),
            ),
            'default' => 'off',
            'toggle_slug' => 'main_content',
        );

        $fields = Proxy\Shared::prepareBooklyFormFields( $fields );

        $fields['hide_date'] = array(
            'label' => __( 'Hide date', 'bookly-responsive-appointment-booking-tool' ),
            'type' => 'yes_no_button',
            'options' => array(
                'off' => esc_html__( 'No', 'bookly-responsive-appointment-booking-tool' ),
                'on' => esc_html__( 'Yes', 'bookly-responsive-appointment-booking-tool' ),
            ),
            'default' => 'off',
            'toggle_slug' => 'main_content',
        );
        $fields['hide_week_days'] = array(
            'label' => __( 'Hide week days', 'bookly-responsive-appointment-booking-tool' ),
            'type' => 'yes_no_button',
            'options' => array(
                'off' => esc_html__( 'No', 'bookly-responsive-appointment-booking-tool' ),
                'on' => esc_html__( 'Yes', 'bookly-responsive-appointment-booking-tool' ),
            ),
            'default' => 'off',
            'toggle_slug' => 'main_content',
        );
        $fields['hide_time_range'] = array(
            'label' => __( 'Hide time range', 'bookly-responsive-appointment-booking-tool' ),
            'type' => 'yes_no_button',
            'options' => array(
                'off' => esc_html__( 'No', 'bookly-responsive-appointment-booking-tool' ),
                'on' => esc_html__( 'Yes', 'bookly-responsive-appointment-booking-tool' ),
            ),
            'default' => 'off',
            'toggle_slug' => 'main_content',
        );

        return $fields;
    }

    public function render( $attrs, $content = null, $render_slug = null )
    {
        $short_code = '[bookly-form';
        $hide = array();

        if ( ! empty( $this->props['category_id'] ) && $this->props['category_id'] !== '0' ) {
            $short_code .= ' category_id="' . (int) $this->props['category_id'] . '"';
        }
        if ( isset( $this->props['hide_categories'] ) && $this->props['hide_categories'] === 'on' ) {
            $hide[] = 'categories';
        }

        if ( ! empty( $this->props['service_id'] ) && $this->props['service_id'] !== '0' ) {
            $short_code .= ' service_id="' . (int) $this->props['service_id'] . '"';
        }
        if ( isset( $this->props['hide_services'] ) && $this->props['hide_services'] === 'on' ) {
            $hide[] = 'services';
        }

        if ( ! empty( $this->props['staff_member_id'] ) && $this->props['staff_member_id'] !== '0' ) {
            $short_code .= ' staff_member_id="' . (int) $this->props['staff_member_id'] . '"';
        }
        if ( isset( $this->props['hide_staff_members'] ) && $this->props['hide_staff_members'] === 'on' ) {
            $hide[] = 'staff_members';
        }
        if ( isset( $this->props['hide_date'] ) && $this->props['hide_date'] === 'on' ) {
            $hide[] = 'date';
        }
        if ( isset( $this->props['hide_week_days'] ) && $this->props['hide_week_days'] === 'on' ) {
            $hide[] = 'week_days';
        }
        if ( isset( $this->props['hide_time_range'] ) && $this->props['hide_time_range'] === 'on' ) {
            $hide[] = 'time_range';
        }

        list( $short_code, $hide ) = Proxy\Shared::prepareBooklyFormShortcode( array( $short_code, $hide ), $this->props );

        if ( $hide ) {
            $short_code .= ' hide="' . implode( ',', $hide ) . '"';
        }

        $short_code .= ']';

        return do_shortcode( $short_code );
    }
}