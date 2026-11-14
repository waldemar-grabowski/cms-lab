<?php
namespace Bookly\Backend\Modules\AiAdmin\Tools;

use Bookly\Lib;

/**
 * list_taxes() — taxes of the Taxes add-on: id, title, rate in percent and the services the tax is
 * applied to; the shape set_tax takes.
 */
class ListTaxes extends Tool
{
    const NAME = 'list_taxes';

    public function getName()
    {
        return self::NAME;
    }

    public function available()
    {
        return Lib\Config::taxesActive();
    }

    protected function description()
    {
        return 'Taxes: id, title, rate in percent, ids of the services the tax is applied to.';
    }

    protected function parameters()
    {
        return array( 'type' => 'object', 'properties' => array() );
    }

    public function execute( array $arguments )
    {
        return self::json( (array) Lib\Proxy\Taxes::getTaxesList() );
    }
}
