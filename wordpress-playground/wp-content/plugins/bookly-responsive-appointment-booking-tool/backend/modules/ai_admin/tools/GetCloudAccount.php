<?php
namespace Bookly\Backend\Modules\AiAdmin\Tools;

use Bookly\Lib\AiAdmin\Actions\CloudAccount;

/**
 * get_cloud_account() — the Bookly Cloud account of this site as the Account, Billing and Products
 * pages show it, in the shape set_cloud_account and set_cloud_invoice_details take.
 */
class GetCloudAccount extends Tool
{
    const NAME = 'get_cloud_account';

    public function getName()
    {
        return self::NAME;
    }

    protected function description()
    {
        return 'The Bookly Cloud account of this site: sign-in, balance, Auto-Recharge, weekly summary, invoice details and products.';
    }

    protected function parameters()
    {
        return array( 'type' => 'object', 'properties' => array() );
    }

    public function execute( array $arguments )
    {
        return self::json( CloudAccount::data() );
    }
}
