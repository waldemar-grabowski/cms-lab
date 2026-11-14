<?php
namespace Bookly\Backend\Components\Gutenberg\BooklyAiAssistant;

use Bookly\Lib;

/**
 * Core (not Pro-gated) — unlike every other non-classic form type, whose
 * Gutenberg insertion goes through the generic "Shortcodes" block in
 * bookly-addon-pro (Backend\Components\Gutenberg\Shortcodes\Block, only
 * registered when Pro is active). AI assistant is core, so it needs its own
 * always-available block instead of depending on that Pro-only mechanism.
 */
class Block extends Lib\Base\Block
{
    /**
     * @inheritDoc
     */
    public static function registerBlockType()
    {
        self::enqueueScripts( array(
            'module' => array(
                'js/ai-assistant-block.js' => array( 'wp-blocks', 'wp-components', 'wp-element', 'wp-editor' ),
            ),
        ) );

        $default_color = get_option( 'bookly_app_color', '#F4662F' );

        $forms = Lib\Entities\Form::query()
            ->select( 'name, token, settings' )
            ->where( 'type', Lib\Entities\Form::TYPE_AI_ASSISTANT )
            ->fetchArray();
        foreach ( $forms as &$form ) {
            $settings = json_decode( $form['settings'], true );
            $form['color'] = isset( $settings['main_color'] ) ? $settings['main_color'] : $default_color;
            unset( $form['settings'] );
        }
        unset( $form );

        wp_localize_script( 'bookly-ai-assistant-block.js', 'BooklyAiAssistantBlockL10n', array(
            'block' => array(
                'title' => 'Bookly - ' . __( 'AI assistant', 'bookly-responsive-appointment-booking-tool' ),
                'description' => __( 'A custom block for displaying AI assistant form', 'bookly-responsive-appointment-booking-tool' ),
            ),
            'forms' => $forms,
            'defaultColor' => $default_color,
            'selectForm' => __( 'Appearance form name', 'bookly-responsive-appointment-booking-tool' ),
        ) );

        register_block_type( 'bookly/ai-assistant', array(
            'editor_script' => 'bookly-ai-assistant-block.js',
        ) );
    }
}
