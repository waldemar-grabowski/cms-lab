<?php
namespace Bookly\Backend\Modules\AiAdmin;

use Bookly\Lib;

/**
 * Tools of the setup assistant, keyed by name: every class in tools/ extending Tools\Tool
 * (read tools of assets/ai-setup/read_tools.yaml, propose, offer_choices, get_knowledge).
 */
class Tools
{
    /**
     * @param Lib\Entities\AiConversation|null $conversation
     * @return Tools\Tool[]
     */
    public static function all( $conversation = null )
    {
        $by_name = array();
        foreach ( glob( __DIR__ . '/tools/*.php' ) ?: array() as $file ) {
            $class = '\Bookly\Backend\Modules\AiAdmin\Tools\\' . basename( $file, '.php' );
            if ( basename( $file, '.php' ) === 'Tool' || ! class_exists( $class ) || ! is_subclass_of( $class, '\Bookly\Backend\Modules\AiAdmin\Tools\Tool' ) ) {
                continue;
            }
            /** @var Tools\Tool $tool */
            $tool = new $class( $conversation );
            if ( $tool->available() ) {
                $by_name[ $tool->getName() ] = $tool;
            }
        }
        ksort( $by_name );

        return $by_name;
    }
}
