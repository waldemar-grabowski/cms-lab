<?php
namespace Bookly\Backend\Modules\AiAdmin\Tools;

use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\AllowedValues;

/**
 * get_settings({keys}) — current value of each option and the values allowed on this site,
 * in the form the option's entry declares (list, grid, range); allowed_depends — options the
 * list was computed from; unavailable — the control exists only with an inactive add-on.
 */
class GetSettings extends Tool
{
    const NAME = 'get_settings';

    public function getName()
    {
        return self::NAME;
    }

    protected function description()
    {
        return 'Current value of each requested option and the values allowed on this site.';
    }

    protected function parameters()
    {
        return array(
            'type'       => 'object',
            'properties' => array(
                'keys' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Option names.' ),
            ),
            'required'   => array( 'keys' ),
        );
    }

    public function execute( array $arguments )
    {
        return self::json( self::settings( isset( $arguments['keys'] ) ? (array) $arguments['keys'] : array() ) );
    }

    /**
     * What get_settings returns for these options; option cards carry the same (GetKnowledge).
     *
     * @param array $names
     * @return array
     */
    public static function settings( array $names )
    {
        $result = array();
        foreach ( $names as $name ) {
            $name = (string) $name;
            $rule = Access::rule( 'option:' . $name );
            if ( ! $rule || empty( $rule['read'] ) || ! Access::allowed( $rule['read'] ) ) {
                $result[ $name ] = array( 'error' => 'unknown option' );
                continue;
            }
            $value = get_option( $name );
            $item  = array( 'value' => $value );
            if ( ! empty( $rule['addon'] ) && ! Access::addonActive( $rule['addon'] ) ) {
                $item['unavailable'] = $rule['addon'];
            } else {
                $allowed = AllowedValues::of( $rule, $value, array( 'ref' => 'option:' . $name ) );
                if ( $allowed !== null ) {
                    $item['allowed'] = $allowed;
                }
                if ( ! empty( $rule['allowed_depends'] ) ) {
                    $item['allowed_depends'] = array_map( function ( $ref ) {
                        return preg_replace( '/^option:/', '', $ref );
                    }, $rule['allowed_depends'] );
                }
            }
            $result[ $name ] = $item;
        }

        return $result;
    }
}
