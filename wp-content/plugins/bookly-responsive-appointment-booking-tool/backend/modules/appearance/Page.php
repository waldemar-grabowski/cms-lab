<?php
namespace Bookly\Backend\Modules\Appearance;

use Bookly\Lib;

class Page extends Lib\Base\Component
{
    public static function render()
    {
        if ( self::hasParameter( 'bookly-form' ) ) {
            BooklyForm::render();
        } else {
            ModernAppearance::render();
        }
    }
}