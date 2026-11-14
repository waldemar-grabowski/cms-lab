<?php
namespace Bookly\Backend\Components\Gutenberg\Shortcodes;

use Bookly\Lib;

class Block extends Lib\Base\Block
{
    /**
     * @inheritDoc
     */
    public static function registerBlockType()
    {
        // Registered types minus the ones with a dedicated block of their own.
        $types = array_values( array_diff( Lib\Entities\Form::getTypes(), array(
            Lib\Entities\Form::TYPE_BOOKLY_FORM,
            Lib\Entities\Form::TYPE_AI_ASSISTANT,
        ) ) );

        if ( ! $types ) {
            return;
        }

        self::enqueueScripts( array(
            'module' => array(
                'js/shortcodes-block.js' => array( 'wp-blocks', 'wp-components', 'wp-element', 'wp-editor' ),
            ),
        ) );

        $exists_appearance = array_fill_keys( $types, false );

        $name = __( 'Default', 'bookly-responsive-appointment-booking-tool' );
        $token = '';
        $color = get_option( 'bookly_app_color', '#f4662f' );

        /** @var array $forms */
        $forms = Lib\Entities\Form::query()
            ->select( 'type, name, token, settings' )
            ->sortBy( 'type, name' )
            ->whereIn( 'type', $types )
            ->fetchArray();
        foreach ( $forms as &$form ) {
            $exists_appearance[ $form['type'] ] = true;
            $settings = json_decode( $form['settings'], true );
            $form['color'] = isset( $settings['main_color'] ) ? $settings['main_color'] : $color;
            unset( $form['settings'] );
        }
        unset( $form );

        $block = array();
        foreach ( $exists_appearance as $type => $exists ) {
            if ( ! $exists ) {
                $forms[] = compact( 'type', 'name', 'token', 'color' );
            }
            $block[ $type ] = array(
                'title' => 'Bookly - ' . Lib\Entities\Form::getTitle( $type ),
                'description' => Lib\Entities\Form::getDescription( $type ),
            );
        }

        wp_localize_script( 'bookly-shortcodes-block.js', 'BooklyShortcodesL10n',
            compact( 'block', 'forms' )
        );
    }
}
