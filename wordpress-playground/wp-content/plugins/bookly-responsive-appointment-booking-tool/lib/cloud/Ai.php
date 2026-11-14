<?php
namespace Bookly\Lib\Cloud;

use Bookly\Lib\Plugin;

/**
 * The booking chat on the site (product AI Assistant). The assistants in wp-admin are a product
 * of their own on the same transport, see AiAdmin.
 */
class Ai extends Product
{
    const ACTIVATE                = '/1.0/users/%token%/products/ai/activate';                // POST
    const DEACTIVATE_NOW          = '/1.0/users/%token%/products/ai/deactivate/now';          // POST
    const DEACTIVATE_NEXT_RENEWAL = '/1.0/users/%token%/products/ai/deactivate/next-renewal'; // POST
    const REVERT_CANCEL           = '/1.0/users/%token%/products/ai/revert-cancel';           // POST
    const TRANSCRIBE              = '/1.0/users/%token%/products/ai/transcribe';              // POST
    const COMPLETE                = '/1.2/users/%token%/products/ai/complete';                // POST

    /**
     * @param array $payload
     * @return array
     */
    public function completeRaw( array $payload )
    {
        return $this->postRaw( static::COMPLETE, $payload );
    }

    /**
     * @param string $audio  base64 of the recording, as the browser made it
     * @param string $format webm|mp4|ogg
     * @return array
     */
    public function transcribeRaw( $audio, $format )
    {
        return $this->postRaw( static::TRANSCRIBE, array( 'audio' => $audio, 'format' => $format ) );
    }

    protected function postRaw( $path, array $payload )
    {
        $url = $this->api->buildUrl( $path );

        $response = wp_remote_post( $url, array(
            'method'    => 'POST',
            'timeout'   => 60,
            'sslverify' => false,
            'headers'   => array(
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Bookly-Version' => Plugin::getVersion(),
            ),
            'body' => wp_json_encode( $payload ),
        ) );

        if ( $response instanceof \WP_Error ) {
            return array(
                'status' => 502,
                'body'   => wp_json_encode( array(
                    'success' => false,
                    'error'   => 'ERROR_PROXY_CONNECTION',
                    'message' => implode( '; ', $response->get_error_messages() ),
                ) ),
            );
        }

        return array(
            'status' => wp_remote_retrieve_response_code( $response ) ?: 200,
            'body'   => wp_remote_retrieve_body( $response ),
        );
    }
}
