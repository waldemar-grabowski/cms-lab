<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\AllowedValues;
use Bookly\Lib\AiAdmin\Constraints;
use Bookly\Lib\AiAdmin\Format;
use Bookly\Lib\AiAdmin\SettingsTab;
use Bookly\Lib\AiAdmin\State;

/**
 * set_option — change one or more Bookly options (assets/ai-setup/actions/set_option.yaml).
 * Validation ids follow the action's validation list.
 *
 * Each option is saved by the handler of its entry (save.handler, dev.handlers of the action):
 * settings_tab (default) — the Settings tab's own save code with the whole tab, see SettingsTab;
 * appearance and direct — update_option() as Appearance and the screens without checks do,
 * plus the side effects of the entry (wpml_register).
 */
class SetOption implements ActionInterface
{
    /**
     * @inheritDoc
     */
    public function validate( array $args, State $state )
    {
        $errors  = array();
        $changes = isset( $args['changes'] ) && is_array( $args['changes'] ) ? $args['changes'] : array();
        if ( ! $changes ) {
            return array( 'changes: at least one {option, value}' );
        }
        foreach ( $changes as $change ) {
            $name  = isset( $change['option'] ) ? (string) $change['option'] : '';
            $ref   = 'option:' . $name;
            $rule  = Access::rule( $ref );
            $value = array_key_exists( 'value', $change ) ? $change['value'] : null;
            if ( ! $rule ) {
                $errors[] = 'unknown_option: ' . $name;
                continue;
            }
            if ( empty( $rule['edit'] ) || ! Access::allowed( $rule['edit'] ) ) {
                $errors[] = 'forbidden: ' . $name . ' cannot be changed by the assistant';
                continue;
            }
            if ( ! empty( $rule['addon'] ) && ! Access::addonActive( $rule['addon'] ) ) {
                $errors[] = 'addon_inactive: ' . $name . ' needs the ' . $rule['addon'] . ' add-on';
                continue;
            }
            if ( ! Constraints::visible( $rule, $state->reader() ) ) {
                $errors[] = 'control_hidden: ' . $name . ' is not shown in the form in the current settings';
                continue;
            }
            $addon = AllowedValues::valueAddon( $ref, $value );
            if ( $addon !== null ) {
                $errors[] = 'addon_inactive: ' . $name . ' = ' . wp_json_encode( $value ) . ' needs the ' . $addon . ' add-on';
                continue;
            }
            $before  = $state->get( $ref );
            $allowed = AllowedValues::of( $rule, $before, array( 'ref' => $ref ) );
            if ( ! AllowedValues::contains( $value, $allowed ) ) {
                $errors[] = 'value_not_allowed: ' . $name . ' = ' . wp_json_encode( $value );
                continue;
            }
            foreach ( Constraints::failed( $rule, $value, $before, $state->reader() ) as $failed ) {
                $errors[] = 'entry_constraints: ' . $name . ' — ' . $failed;
            }
            if ( isset( $rule['type'] ) && $rule['type'] === 'color' && ! preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $value ) ) {
                $errors[] = 'color_hex: ' . $name;
            }
            if ( isset( $rule['type'] ) && $rule['type'] === 'url' && (string) $value !== '' && ! preg_match( '~^https?://~i', (string) $value ) ) {
                $errors[] = 'url_http: ' . $name;
            }
            $state->set( $ref, $value );
        }

        return $errors;
    }

    /**
     * @inheritDoc
     */
    public function preview( array $args, State $state )
    {
        $rows     = array();
        $warnings = array();
        foreach ( $args['changes'] as $change ) {
            $ref    = 'option:' . $change['option'];
            $rule   = Access::rule( $ref );
            $before = $state->get( $ref );
            $after  = $change['value'];
            $rows[] = array(
                'label'  => Format::label( $rule, $change['option'] ),
                'before' => Format::value( $rule, $before ),
                'after'  => Format::value( $rule, $after ),
                'link'   => Format::link( $rule ),
            );
            foreach ( Format::warnings( $rule, $before, $after, $state ) as $text ) {
                $warnings[] = $text;
            }
            $state->set( $ref, $after );
        }

        return array( 'rows' => $rows, 'warnings' => array_values( array_unique( $warnings ) ) );
    }

    /**
     * @inheritDoc
     */
    public function apply( array $args )
    {
        // An add-on a value needs may have been deactivated after the card was shown.
        foreach ( $args['changes'] as $change ) {
            $addon = AllowedValues::valueAddon( 'option:' . $change['option'], $change['value'] );
            if ( $addon !== null ) {
                return array( 'error' => 'addon_inactive: ' . $change['option'] . ' = ' . wp_json_encode( $change['value'] ) . ' needs the ' . $addon . ' add-on' );
            }
        }
        $previous = array();
        $values   = array();
        foreach ( $args['changes'] as $change ) {
            $name = $change['option'];
            if ( ! array_key_exists( $name, $previous ) ) {
                $previous[ $name ] = get_option( $name );
            }
            $values[ $name ] = self::requestValue( $change['value'] );
        }
        try {
            $notices = self::save( $values );
        } catch ( \Exception $e ) {
            self::restore( $previous );

            return array( 'error' => 'save_failed: ' . $e->getMessage() );
        } catch ( \Error $e ) {
            self::restore( $previous );

            return array( 'error' => 'save_failed: ' . $e->getMessage() );
        }

        // notices: errors the handlers reported while the options were saved (as the page shows them).
        return array( 'options' => $previous, 'notices' => $notices );
    }

    /**
     * @inheritDoc
     */
    public function undo( array $snapshot )
    {
        // A snapshot without "options" holds option => previous value directly.
        $previous = isset( $snapshot['options'] ) && is_array( $snapshot['options'] ) ? $snapshot['options'] : $snapshot;
        try {
            // The previous values go through the same handlers, so their side effects run again.
            self::save( $previous );
        } catch ( \Exception $e ) {
            self::restore( $previous );
        } catch ( \Error $e ) {
            self::restore( $previous );
        }
    }

    /**
     * Save options, each by the handler of its entry.
     *
     * @param array $values option name => value
     * @return string[] errors reported by the handlers
     * @throws \Exception
     */
    protected static function save( array $values )
    {
        $tabs = array();
        foreach ( $values as $name => $value ) {
            $rule    = Access::rule( 'option:' . $name );
            $handler = isset( $rule['handler'] ) ? $rule['handler'] : 'settings_tab';
            if ( $handler === 'settings_tab' && isset( $rule['page'], $rule['tab'] ) && $rule['page'] === 'settings' ) {
                // The tab's handler runs its own side effects (WPML strings included).
                $tabs[ $rule['tab'] ][ $name ] = $value;
            } else {
                update_option( $name, $value );
                if ( ! empty( $rule['side_effects'] ) && in_array( 'wpml_register', $rule['side_effects'], true ) ) {
                    do_action( 'wpml_register_single_string', 'bookly', $name, $value );
                }
            }
        }
        $notices = array();
        foreach ( $tabs as $tab => $changes ) {
            foreach ( SettingsTab::save( $tab, $changes ) as $error ) {
                $notices[] = $error;
            }
        }

        return $notices;
    }

    /**
     * Put the values back without handlers, after a handler failed half way.
     *
     * @param array $values option name => value
     */
    protected static function restore( array $values )
    {
        foreach ( $values as $name => $value ) {
            update_option( $name, $value );
        }
    }

    /**
     * A value as the request of a settings screen gives it to the handler: text is filtered
     * by wp_kses() for a user without unfiltered_html (Lib\Base\Component::getRequest()).
     *
     * @param mixed $value
     * @return mixed
     */
    protected static function requestValue( $value )
    {
        if ( current_user_can( 'unfiltered_html' ) ) {
            return $value;
        }
        $filter = function ( $item ) {
            return is_string( $item ) ? wp_kses( $item, 'post' ) : $item;
        };

        return is_array( $value ) ? Lib\Utils\Common::arrayMapRecursive( $filter, $value ) : $filter( $value );
    }
}
