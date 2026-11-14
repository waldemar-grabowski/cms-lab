<?php
namespace Bookly\Backend\Components\Cloud\LoginRequired;

use Bookly\Lib;
use Bookly\Backend\Components\Cloud\Account;

class Page extends Lib\Base\Component
{
    /**
     * Render page.
     *
     * @param string $title
     * @param string $slug
     */
    public static function render( $title, $slug )
    {
        self::enqueueStyles( array(
            'alias' => array( 'bookly-backend-globals', ),
        ) );

        self::enqueueScripts( array(
            'alias' => array( 'bookly-backend-globals', ),
        ) );

        Account\Panel::requireLogin();

        self::renderTemplate( 'index', compact( 'title', 'slug' ) );
    }
}