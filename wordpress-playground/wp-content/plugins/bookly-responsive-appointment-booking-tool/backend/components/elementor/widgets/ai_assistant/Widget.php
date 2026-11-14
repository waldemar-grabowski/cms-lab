<?php
namespace Bookly\Backend\Components\Elementor\Widgets\AiAssistant;

use Bookly\Backend\Components\Elementor\Base;
use Bookly\Lib\Entities\Form;
use Elementor\Controls_Manager;

/**
 * Core (not Pro-gated) — there's no Elementor precedent for non-classic form
 * types at all (Pro doesn't provide one either, unlike Divi/TinyMCE), so
 * this establishes the pattern for AI assistant directly, modeled on the
 * existing bookly_form widget's structure.
 */
class Widget extends Base\Widget
{
    protected $name = 'ai-assistant';
    protected $icon = 'bookly';

    /**
     * @inheritDoc
     */
    public function get_title()
    {
        return __( 'AI assistant', 'bookly-responsive-appointment-booking-tool' );
    }

    /**
     * @inheritDoc
     */
    protected function register_controls()
    {
        $this->start_controls_section(
            'bookly_ai_assistant_section',
            array(
                'label' => '<div class="bookly-elementor-section"><p>Bookly</p><br><p class="bookly-elementor-section-description">'
                    . esc_html__( 'A custom block for displaying AI assistant form', 'bookly-responsive-appointment-booking-tool' ) . '</p></div>',
            )
        );

        $forms = array( '' => __( 'Default', 'bookly-responsive-appointment-booking-tool' ) );
        $rows = Form::query()->select( 'name, token' )->where( 'type', Form::TYPE_AI_ASSISTANT )->fetchArray();
        foreach ( $rows as $row ) {
            $forms[ $row['token'] ] = $row['name'];
        }

        $this->add_control(
            'form_token',
            array(
                'label' => __( 'Form', 'bookly-responsive-appointment-booking-tool' ),
                'type' => Controls_Manager::SELECT,
                'options' => $forms,
                'default' => '',
            )
        );

        $this->end_controls_section();
    }

    /**
     * @inheritDoc
     */
    protected function render()
    {
        $settings = $this->get_settings_for_display();
        $token = isset( $settings['form_token'] ) ? trim( $settings['form_token'] ) : '';

        echo '[bookly-ai-assistant-form' . ( $token !== '' ? ' ' . $token : '' ) . ']';
    }
}
