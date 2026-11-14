<?php
namespace Bookly\Lib\Cloud;

use Bookly\Lib\Plugin;

/**
 * The assistants in wp-admin for the site owner (product AI Admin Assistant): a subscription
 * and a messages limit of their own, apart from the booking chat's (Ai). Activation goes through
 * the common product routes.
 */
class AiAdmin extends Ai
{
    const ACTIVATE                = '/1.2/users/%token%/products/ai-admin/activate';                // POST
    const DEACTIVATE_NOW          = '/1.0/users/%token%/products/ai-admin/deactivate/now';          // POST
    const DEACTIVATE_NEXT_RENEWAL = '/1.0/users/%token%/products/ai-admin/deactivate/next-renewal'; // POST
    const REVERT_CANCEL           = '/1.0/users/%token%/products/ai-admin/revert-cancel';           // POST
    const TRANSCRIBE              = '/1.0/users/%token%/products/ai-admin/transcribe';              // POST
    const COMPLETE                = '/1.0/users/%token%/products/ai-admin/complete';                // POST
    const KNOWLEDGE               = '/1.0/users/%token%/products/ai-admin/knowledge';               // POST

    /**
     * Which file of an agent's knowledge base a path as the model wrote it stands for: Cloud
     * forgives the usual slips (no folder, a goal without "goal.") and, when nothing matches,
     * names the nearest paths. The text stays in Cloud; it comes with the file's path the
     * options the file describes.
     *
     * @param string $agent
     * @param string $path
     * @return array array( 'path' => string|null, 'options' => string[], 'suggestions' => string[] )
     */
    public function knowledgeFile( $agent, $path )
    {
        // Cloud keeps a base per plugin version and answers with the one of this version.
        $response = $this->api->sendPostRequest( self::KNOWLEDGE, array( 'agent' => $agent, 'path' => $path, 'version' => Plugin::getVersion() ) );

        return array(
            'path'        => $response && isset( $response['path'] ) ? (string) $response['path'] : null,
            'options'     => $response && isset( $response['options'] ) && is_array( $response['options'] ) ? array_map( 'strval', $response['options'] ) : array(),
            'suggestions' => $response && isset( $response['suggestions'] ) && is_array( $response['suggestions'] ) ? array_map( 'strval', $response['suggestions'] ) : array(),
        );
    }
}
