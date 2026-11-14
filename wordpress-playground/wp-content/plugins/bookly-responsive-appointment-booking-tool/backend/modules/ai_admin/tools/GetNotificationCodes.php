<?php
namespace Bookly\Backend\Modules\AiAdmin\Tools;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Actions\NotificationData;

/**
 * get_notification_codes(type, gateway) — the codes the text of a notification type may use in a
 * channel, and the lists it may iterate.
 */
class GetNotificationCodes extends Tool
{
    const NAME = 'get_notification_codes';

    public function getName()
    {
        return self::NAME;
    }

    protected function description()
    {
        return 'Codes the text of a notification type may use in a channel, with descriptions; loops with the codes of one item.';
    }

    protected function parameters()
    {
        return array(
            'type'       => 'object',
            'properties' => array(
                'type'    => array( 'type' => 'string' ),
                'gateway' => array( 'type' => 'string', 'enum' => NotificationData::gateways(), 'default' => 'email' ),
            ),
            'required'   => array( 'type' ),
        );
    }

    /**
     * @param array $codes
     * @return array
     */
    protected static function plain( array $codes )
    {
        $out = array();
        foreach ( $codes as $code => $def ) {
            if ( ! isset( $def['loop'] ) ) {
                $out[] = array(
                    'code' => $code,
                    'description' => isset( $def['description'] ) ? $def['description'] : '',
                    'if' => ! empty( $def['if'] ),
                );
            }
        }

        return $out;
    }

    public function execute( array $arguments )
    {
        if ( ! Lib\AiAdmin\Access::allowed( array( 'admin' ) ) ) {
            return self::json( array( 'error' => 'only a Bookly administrator can read notifications' ) );
        }
        $gateway = isset( $arguments['gateway'] ) ? $arguments['gateway'] : 'email';
        if ( ! in_array( $gateway, NotificationData::gateways(), true ) ) {
            return self::json( array( 'error' => 'gateway is email, sms, voice or whatsapp' ) );
        }
        $type  = isset( $arguments['type'] ) ? (string) $arguments['type'] : '';
        $types = NotificationData::allowedTypes( $gateway );
        if ( ! isset( $types[ $type ] ) ) {
            return self::json( array( 'unavailable' => $type ) );
        }
        $codes = NotificationData::codes( $gateway, $type );
        $loops = array();
        // A WhatsApp variable takes plain text with codes: the dialog offers no loops.
        if ( $gateway !== 'whatsapp' ) {
            foreach ( $codes as $code => $def ) {
                if ( isset( $def['loop'] ) ) {
                    $loops[] = array(
                        'code' => $code,
                        'description' => isset( $def['description'] ) ? $def['description'] : '',
                        'item' => $def['loop']['item'],
                        'codes' => self::plain( $def['loop']['codes'] ),
                    );
                }
            }
        }

        return self::json( array(
            'gateway' => $gateway,
            'type' => $type,
            'codes' => self::plain( $codes ),
            'loops' => $loops,
        ) );
    }
}
