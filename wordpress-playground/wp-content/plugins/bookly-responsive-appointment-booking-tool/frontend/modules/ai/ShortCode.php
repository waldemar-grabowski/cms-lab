<?php
namespace Bookly\Frontend\Modules\Ai;

use Bookly\Lib;
use Bookly\Backend\Modules\Appearance\ModernAppearance;

class ShortCode extends Lib\Base\ShortCode
{
    public static $code = 'bookly-ai-assistant-form';

    /**
     * @inheritDoc
     */
    public static function linkStyles()
    {
        self::enqueueStyles( array(
            'bookly' => array(
                'backend/resources/tailwind/tailwind.css' => array( 'bookly-frontend-globals' ),
            ),
        ) );
    }

    /**
     * @inheritDoc
     */
    public static function linkScripts()
    {
        self::enqueueScripts( array(
            'module' => array(
                'js/ai-assistant.js' => array( 'bookly-frontend-globals', 'bookly-bookly-core.js', 'moment' ),
            ),
        ) );
    }

    /**
     * Inline custom properties the widget styles itself with — main color plus
     * the two chat bubble colors. Bubble vars are emitted only when set, so an
     * unset question bubble keeps falling back to --bookly-color in the CSS
     * (see assets/svelte5/src/frontend/ai-assistant/Bubble.svelte).
     *
     * @param array $appearance
     * @return string
     */
    protected static function getCssVars( array $appearance )
    {
        $vars = array( '--bookly-color: ' . $appearance['main_color'] );

        $bubbles = array(
            'q' => 'question_bubble_color',
            'a' => 'answer_bubble_color',
        );
        foreach ( $bubbles as $prefix => $key ) {
            if ( ! empty( $appearance[ $key ] ) ) {
                $vars[] = sprintf( '--bookly-%s-bubble-bg: %s', $prefix, $appearance[ $key ] );
                $vars[] = sprintf( '--bookly-%s-bubble-fg: %s', $prefix, ModernAppearance::contrastTextColor( $appearance[ $key ] ) );
            }
        }

        return implode( '; ', $vars ) . ';';
    }

    /**
     * Render shortcode.
     *
     * @param array $attributes
     * @return string
     */
    public static function render( $attributes )
    {
        // The chat engine lives in Bookly Cloud — without an active 'ai'
        // product the widget would mount and silently never respond, so
        // skip rendering entirely rather than show a dead widget.
        if ( ! Lib\Cloud\API::getInstance()->account->productActive( Lib\Cloud\Account::PRODUCT_AI ) ) {
            return '';
        }

        // Disable caching — a fully cached page would keep serving a
        // visitor an eventually-stale nonce (see Component::csrfTokenValid()
        // / the 'bookly' nonce action).
        Lib\Utils\Common::noCache();

        // Same convention as Cancellation/Search form shortcodes: the
        // shortcode's own (non key=value) word is the target form's token.
        $token = is_array( $attributes ) ? current( $attributes ) : null;
        $appearance = ModernAppearance::getAppearance( Lib\Entities\Form::TYPE_AI_ASSISTANT, is_string( $token ) ? $token : null );
        $form_id = uniqid( 'bookly-ai-assistant-app-', false );
        $css_vars = self::getCssVars( $appearance );

        return self::renderTemplate( 'short_code', compact( 'appearance', 'form_id', 'css_vars' ), false );
    }
}
