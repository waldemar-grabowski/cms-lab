<?php
namespace Bookly\Backend\Modules\AiAdmin\Tools;

use Bookly\Lib\AiAdmin\Actions\SetCloudStripeMetadata;

/**
 * get_cloud_stripe_metadata() — custom metadata of Stripe Cloud payments (Settings → Payments →
 * Stripe Cloud): product state, the two switches that show the list, the list in the shape
 * set_cloud_stripe_metadata takes, its limits and the codes a value may use.
 */
class GetCloudStripeMetadata extends Tool
{
    const NAME = 'get_cloud_stripe_metadata';

    public function getName()
    {
        return self::NAME;
    }

    /**
     * Offered only while the Stripe Cloud product is active (otherwise the list is not on the page).
     */
    public function available()
    {
        return SetCloudStripeMetadata::productActive();
    }

    protected function description()
    {
        return 'Custom metadata of Stripe Cloud payments: the list, its switches, limits and allowed codes.';
    }

    protected function parameters()
    {
        return array( 'type' => 'object', 'properties' => array() );
    }

    public function execute( array $arguments )
    {
        return self::json( array(
            'product_active'       => SetCloudStripeMetadata::productActive(),
            'stripe_cloud_enabled' => (int) get_option( 'bookly_cloud_stripe_enabled' ),
            'custom_metadata'      => (int) get_option( 'bookly_cloud_stripe_custom_metadata' ),
            'metadata'             => SetCloudStripeMetadata::normalize( get_option( SetCloudStripeMetadata::OPTION, array() ) ),
            'limits'               => array(
                'max_pairs'        => SetCloudStripeMetadata::MAX_PAIRS,
                'max_name_length'  => SetCloudStripeMetadata::MAX_NAME,
                'max_value_length' => SetCloudStripeMetadata::MAX_VALUE,
            ),
            'allowed_codes'        => SetCloudStripeMetadata::allowedCodes(),
        ) );
    }
}
