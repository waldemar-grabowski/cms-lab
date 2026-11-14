<?php
namespace Bookly\Backend\Modules\AiAdmin\Tools;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Actions\NotificationData;

/**
 * list_whatsapp_templates() — the WhatsApp message templates of the owner's Meta account as the
 * Template list of the WhatsApp notification dialog shows them.
 */
class ListWhatsappTemplates extends Tool
{
    const NAME = 'list_whatsapp_templates';

    public function getName()
    {
        return self::NAME;
    }

    protected function description()
    {
        return 'WhatsApp message templates of the Meta account: name, language, status, header, body, the numbers of the variables and whether the template can be put into a notification (usable). A template is identified by name and language together.';
    }

    protected function parameters()
    {
        return array( 'type' => 'object', 'properties' => array() );
    }

    public function execute( array $arguments )
    {
        if ( ! Lib\AiAdmin\Access::allowed( array( 'admin' ) ) ) {
            return self::json( array( 'error' => 'only a Bookly administrator can read WhatsApp templates' ) );
        }
        $cloud = NotificationData::cloud();
        if ( ! $cloud['signed_in'] ) {
            return self::json( array( 'signed_in' => false ) );
        }
        if ( ! $cloud['whatsapp'] ) {
            return self::json( array( 'product_active' => false ) );
        }
        $list = NotificationData::whatsappTemplates();
        if ( $list['error'] !== null ) {
            return self::json( array( 'error' => $list['error'] ) );
        }
        $templates = array();
        foreach ( $list['templates'] as $tpl ) {
            $item = array(
                'name' => $tpl['name'],
                'language' => $tpl['language'],
                'status' => $tpl['status'],
            );
            if ( $tpl['header'] !== null ) {
                $item['header'] = $tpl['header'];
            }
            $item['body'] = $tpl['body'];
            $item['header_variables'] = $tpl['header_variables'];
            $item['body_variables'] = $tpl['body_variables'];
            $item['usable'] = $tpl['usable'];
            if ( isset( $tpl['reason'] ) ) {
                $item['reason'] = $tpl['reason'];
            }
            $templates[] = $item;
        }

        return self::json( array( 'signed_in' => true, 'templates' => $templates ) );
    }
}
