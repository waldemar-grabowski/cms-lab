<?php
namespace Bookly\Backend\Modules\CloudProducts;

use Bookly\Backend\Modules\Appearance\Page as AppearancePage;
use Bookly\Backend\Modules\Settings\Page as SettingsPage;
use Bookly\Backend\Modules\CloudSMS\Page as CloudSMSPage;
use Bookly\Backend\Modules\CloudZapier\Page as CloudZapierPage;
use Bookly\Backend\Modules\CloudVoice\Page as CloudVoicePage;
use Bookly\Backend\Modules\CloudWhatsapp\Page as CloudWhatsAppPage;
use Bookly\Backend\Modules\CloudMobileStaffCabinet\Page as CloudMobileStaffCabinetPage;
use Bookly\Lib;
use Bookly\Lib\Cloud\Account;

class Page extends Lib\Base\Component
{
    /**
     * Render page.
     */
    public static function render()
    {
        self::enqueueScripts( array(
            'module' => array(
                'js/cloud-products.js' => array( 'bookly-backend-globals' ),
                'js/cloud-products-page.js' => array( 'bookly-cloud-products.js' ),
            ),
        ) );

        $cloud = Lib\Cloud\API::getInstance();
        // Get actual products data
        $cloud->general->loadInfo();

        wp_localize_script( 'bookly-cloud-products-page.js', 'BooklyL10nCloudProducts', array(
            'loggedIn' => (bool) $cloud->account->loadProfile(),
            'products' => self::getProductsData( $cloud ),
            // Whether the setup assistant's button is shown on Bookly pages
            'assistantButton' => (bool) get_option( 'bookly_ai_admin_button' ),
            'l10n' => self::getL10n(),
        ) );

        self::renderTemplate( 'index' );
    }

    /**
     * Products of the catalogue, with what this account has of each.
     *
     * The catalogue is served from outside and this installation may not be updated
     * for years, so every field is read as optional and the newer ones (short,
     * features, highlighted, image) have a stand-in when the catalogue has none.
     *
     * @param Lib\Cloud\API $cloud
     * @return array
     */
    protected static function getProductsData( Lib\Cloud\API $cloud )
    {
        $subscriptions = $cloud->account->getSubscriptions();
        $plugin_version = Lib\Plugin::getVersion();
        $products = array();

        foreach ( Lib\Utils\Common::sortCloudProducts( (array) $cloud->general->getProducts() ) as $product ) {
            if ( ! isset ( $product['id'], $product['texts'] ) ) {
                continue;
            }
            $id = $product['id'];
            $texts = $product['texts'];
            $active = $cloud->account->productActive( $id );

            $prices = array();
            if ( isset ( $product['prices'] ) && is_array( $product['prices'] ) ) {
                foreach ( $product['prices'] as $price ) {
                    $prices[] = array(
                        'id' => (string) $price['id'],
                        'caption' => Lib\Utils\Common::stripScripts( $price['caption'] ),
                        'tags' => isset ( $price['tags'] ) ? array_values( (array) $price['tags'] ) : array(),
                    );
                }
            }

            // The subscription of this account on one of the product's plans
            $subscription = null;
            foreach ( $prices as $price ) {
                foreach ( $subscriptions as $item ) {
                    if ( (string) $item['product_price_id'] === $price['id'] ) {
                        $subscription = array(
                            'priceId' => $price['id'],
                            'nextBillingDate' => Lib\Utils\DateTime::formatDate( $item['next_billing_date'] ),
                            'cancelOnRenewal' => ! empty ( $item['cancel_on_renewal'] ),
                            'usage' => isset ( $item['usage'] ) ? self::usageText( $id, $item['usage'] ) : null,
                        );
                        break 2;
                    }
                }
            }

            $features = array();
            if ( isset ( $texts['features'] ) && is_array( $texts['features'] ) ) {
                foreach ( $texts['features'] as $feature ) {
                    if ( is_array( $feature ) && ! empty ( $feature['title'] ) ) {
                        $features[] = array(
                            'title' => (string) $feature['title'],
                            'body' => isset ( $feature['body'] ) ? (string) $feature['body'] : '',
                        );
                    }
                }
            }

            $products[] = array(
                'id' => $id,
                'title' => Lib\Utils\Common::stripScripts( $texts['title'] ),
                'short' => isset ( $texts['short'] ) ? (string) $texts['short'] : '',
                'description' => Lib\Utils\Common::stripScripts( $texts['description'] ),
                'priceText' => Lib\Utils\Common::stripScripts( $texts['price'] ),
                'icon' => $product['icon_url'],
                'image' => empty ( $product['image'] ) ? null : $product['image'],
                'highlighted' => isset ( $product['highlighted'] ) ? (int) $product['highlighted'] : 0,
                'features' => $features,
                'info' => ! empty ( $product['button'] ) && ! empty ( $texts['info-button'] )
                    ? array( 'button' => $texts['info-button'], 'title' => $texts['info-title'] )
                    : null,
                'prices' => $prices,
                'active' => $active,
                'subscription' => $subscription,
                'proMissing' => ! empty ( $product['pro_required'] ) && ! Lib\Config::proActive(),
                // Version of Bookly the product needs, when this one is older
                'updateRequired' => isset ( $product['version'] ) && version_compare( $product['version'], $plugin_version, '>' )
                    ? (string) $product['version']
                    : null,
                'texts' => array(
                    'on' => $texts['action-on'],
                    'off' => $texts['action-off'],
                    'revert' => $texts['action-revert-cancel'],
                ),
                'settingsUrl' => self::settingsUrl( $id ),
            );
        }

        return $products;
    }

    /**
     * Where the product is set up and used once it is on.
     *
     * @param string $id
     * @return string|null
     */
    protected static function settingsUrl( $id )
    {
        // Not escaped: the address goes to a script, not into markup
        $url = function ( $page, $params = array() ) {
            return add_query_arg( array_merge( array( 'page' => $page ), $params ), admin_url( 'admin.php' ) );
        };
        switch ( $id ) {
            case Account::PRODUCT_SMS_NOTIFICATIONS:
                return $url( CloudSMSPage::pageSlug() );
            case Account::PRODUCT_ZAPIER:
                return $url( CloudZapierPage::pageSlug() );
            case Account::PRODUCT_VOICE:
                return $url( CloudVoicePage::pageSlug() );
            case Account::PRODUCT_WHATSAPP:
                return $url( CloudWhatsAppPage::pageSlug() );
            case Account::PRODUCT_MOBILE_STAFF_CABINET:
                return $url( CloudMobileStaffCabinetPage::pageSlug() );
            case Account::PRODUCT_STRIPE:
                return $url( SettingsPage::pageSlug(), array( 'tab' => 'payments' ) );
            case Account::PRODUCT_AI:
                return $url( AppearancePage::pageSlug() ) . '&' . Lib\Entities\Form::TYPE_AI_ASSISTANT;
            default:
                return null;
        }
    }

    /**
     * "Tasks: 120 / 500" — what the plan allows and what is used of it.
     *
     * @param string $id
     * @param array $usage
     * @return string
     */
    public static function usageText( $id, array $usage )
    {
        switch ( $id ) {
            case Account::PRODUCT_WHATSAPP:
                $prefix = __( 'Messages', 'bookly-responsive-appointment-booking-tool' );
                break;
            case Account::PRODUCT_AI:
            case Account::PRODUCT_AI_ADMIN:
                $prefix = __( 'AI answers', 'bookly-responsive-appointment-booking-tool' );
                break;
            default:
                $prefix = __( 'Tasks', 'bookly-responsive-appointment-booking-tool' );
        }
        if ( $usage['limit'] === null ) {
            return sprintf( '%s: %s', $prefix, __( 'unlimited in trial', 'bookly-responsive-appointment-booking-tool' ) );
        }

        return sprintf( '%s: %d / %d', $prefix, $usage['used'], $usage['limit'] );
    }

    /**
     * @return array
     */
    protected static function getL10n()
    {
        return array(
            'active' => __( 'Active', 'bookly-responsive-appointment-booking-tool' ),
            'all_products' => __( 'All products', 'bookly-responsive-appointment-booking-tool' ),
            'assistant_button' => __( 'Show the assistant button', 'bookly-responsive-appointment-booking-tool' ),
            'assistant_button_help' => __( 'In the bottom-right corner of every Bookly page, for administrators.', 'bookly-responsive-appointment-booking-tool' ),
            'best_offer' => __( 'best offer', 'bookly-responsive-appointment-booking-tool' ),
            'cancel' => __( 'Cancel', 'bookly-responsive-appointment-booking-tool' ),
            'cancel_immediately' => __( 'Cancel immediately', 'bookly-responsive-appointment-booking-tool' ),
            'cancel_immediately_help' => __( 'The product will be disabled immediately. To use it again, you’ll need to subscribe again.', 'bookly-responsive-appointment-booking-tool' ),
            'cancel_on_renewal' => __( 'Cancel on next renewal', 'bookly-responsive-appointment-booking-tool' ),
            'cancel_on_renewal_help' => __( 'You will be able to use the product until the end of the already paid period. If during this period you decide to keep your subscription, you will have the option to do so.', 'bookly-responsive-appointment-booking-tool' ),
            'cancel_subscription' => __( 'Cancel subscription', 'bookly-responsive-appointment-booking-tool' ),
            'cancel_when' => __( 'When do you want to cancel?', 'bookly-responsive-appointment-booking-tool' ),
            'close' => __( 'Close', 'bookly-responsive-appointment-booking-tool' ),
            'continue' => __( 'Continue', 'bookly-responsive-appointment-booking-tool' ),
            'deactivation_date' => __( 'Deactivation date', 'bookly-responsive-appointment-booking-tool' ),
            'failed' => __( 'Oops', 'bookly-responsive-appointment-booking-tool' ),
            'from' => __( 'from', 'bookly-responsive-appointment-booking-tool' ),
            'log_in' => __( 'Log in', 'bookly-responsive-appointment-booking-tool' ),
            'next_billing_date' => __( 'Next billing date', 'bookly-responsive-appointment-booking-tool' ),
            'open_settings' => __( 'Open settings', 'bookly-responsive-appointment-booking-tool' ),
            'plan' => __( 'Plan', 'bookly-responsive-appointment-booking-tool' ),
            'pro_required' => __( 'Requires Bookly Pro', 'bookly-responsive-appointment-booking-tool' ),
            'request_failed' => __( 'Request failed. Please try again later.', 'bookly-responsive-appointment-booking-tool' ),
            'showcase_subtitle' => __( 'Cloud services that extend Bookly with notifications, payments, and automation.', 'bookly-responsive-appointment-booking-tool' ),
            'showcase_title' => __( 'Bookly Cloud products', 'bookly-responsive-appointment-booking-tool' ),
            'start_subscription_title' => __( 'Activate Product', 'bookly-responsive-appointment-booking-tool' ),
            'start_subscription_about' => __( 'You are about to activate %s', 'bookly-responsive-appointment-booking-tool' ),
            'start_subscription_charge' => __( 'An amount for the selected plan will be immediately charged from your Bookly Cloud balance.', 'bookly-responsive-appointment-booking-tool' ),
            'start_trial_title' => __( 'Activate trial', 'bookly-responsive-appointment-booking-tool' ),
            'start_trial_about' => __( 'You are about to activate a 7-day Trial for %s', 'bookly-responsive-appointment-booking-tool' ),
            'start_trial_free' => __( 'During the trial, you’ll have free access to all features of this product.', 'bookly-responsive-appointment-booking-tool' ),
            'start_trial_billing' => __( 'No payment will be taken at this stage.', 'bookly-responsive-appointment-booking-tool' ) . ' ' . __( 'After the Trial ends, the selected plan will be automatically billed from your Bookly Cloud balance.', 'bookly-responsive-appointment-booking-tool' ),
            'start_trial_warning' => __( 'Important: If you disable the Trial before it ends, it cannot be reactivated. To continue using the product, activate the selected plan.', 'bookly-responsive-appointment-booking-tool' ),
            'succeeded' => __( 'Congratulations!!!', 'bookly-responsive-appointment-booking-tool' ),
            'until' => __( 'Until %s', 'bookly-responsive-appointment-booking-tool' ),
            'update_required' => __( 'Bookly %s required', 'bookly-responsive-appointment-booking-tool' ),
            'update_required_title' => __( 'Bookly update required', 'bookly-responsive-appointment-booking-tool' ),
            'update_required_text' => __( 'This product is not supported by your version of the Bookly plugin. Please update Bookly to the latest version.', 'bookly-responsive-appointment-booking-tool' ),
            'users_choice' => __( 'users\' choice', 'bookly-responsive-appointment-booking-tool' ),
            'what_it_does' => __( 'What it does', 'bookly-responsive-appointment-booking-tool' ),
        );
    }
}
