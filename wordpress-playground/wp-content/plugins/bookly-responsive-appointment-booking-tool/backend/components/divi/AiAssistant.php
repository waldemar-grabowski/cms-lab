<?php
namespace Bookly\Backend\Components\Divi;

use Bookly\Lib\Entities\Form;

/**
 * Core (not Pro-gated) — unlike search_form/services_form/staff_form, whose
 * Divi modules live in bookly-addon-pro (only registered when Pro is
 * active). AI assistant is core, so it gets its own module here instead.
 */
class AiAssistant extends \ET_Builder_Module
{
    public $slug = 'bookly_divi_ai_assistant';

    /** @var array */
    private $form_options = array();

    public function init()
    {
        $this->name = 'Bookly - ' . esc_html__( 'AI assistant', 'bookly-responsive-appointment-booking-tool' );
        $this->form_options = array( '' => __( 'Select form', 'bookly-responsive-appointment-booking-tool' ) );

        if ( ! is_admin() ) {
            return;
        }

        $forms = Form::query()
            ->select( 'name, token' )
            ->where( 'type', Form::TYPE_AI_ASSISTANT )
            ->fetchArray();

        foreach ( $forms as $form ) {
            $this->form_options[ $form['token'] ] = $form['name'];
        }
    }

    public function get_fields()
    {
        return array(
            'form_token' => array(
                'label' => __( 'Form', 'bookly-responsive-appointment-booking-tool' ),
                'type' => 'select',
                'options' => $this->form_options,
                'default' => '',
                'toggle_slug' => 'main_content',
            ),
        );
    }

    public function render( $attrs, $content = null, $render_slug = null )
    {
        $token = isset( $this->props['form_token'] ) ? trim( $this->props['form_token'] ) : '';

        return do_shortcode( $token === ''
            ? '[bookly-ai-assistant-form]'
            : sprintf( '[bookly-ai-assistant-form %s]', sanitize_text_field( $token ) )
        );
    }
}
