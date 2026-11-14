<?php
namespace Bookly\Backend\Modules\AiAdmin;

use Bookly\Backend\Modules\CloudProducts\Page as CloudProductsPage;
use Bookly\Lib;
use Bookly\Lib\AiAdmin\Knowledge;

/**
 * The setup assistant on every Bookly page in wp-admin: a floating button in the bottom-right
 * corner opens a chat panel where the owner asks to configure Bookly and applies the proposed
 * changes. The button and the panel are the Svelte bundle resources/js/ai-admin.js
 * (assets/svelte5/src/admin/ai-admin).
 */
class Launcher extends Lib\Base\Component
{
    /**
     * Enqueue the assistant for administrators, unless its button is hidden on the product's
     * page in Bookly Cloud. Without a Cloud account or the product the panel opens locked: it
     * says what is missing and links to that page.
     */
    public static function enqueue()
    {
        if ( ! Lib\Utils\Common::isCurrentUserAdmin() ) {
            return;
        }
        $visible = (bool) get_option( 'bookly_ai_admin_button' );
        // A hidden button is still loaded on the Cloud products page, where its switch is: the
        // switch shows it at once (cloud/products/ProductPage.svelte).
        if ( ! $visible && self::parameter( 'page' ) !== CloudProductsPage::pageSlug() ) {
            return;
        }

        $product_url = add_query_arg(
            array( 'page' => CloudProductsPage::pageSlug(), 'product' => Lib\Cloud\Account::PRODUCT_AI_ADMIN ),
            admin_url( 'admin.php' )
        );
        if ( get_option( 'bookly_cloud_token' ) == '' ) {
            $locked = array(
                'text' => __( 'The assistant works through Bookly Cloud. Log in to your Cloud account to start.', 'bookly-responsive-appointment-booking-tool' ),
                'button' => __( 'Log in', 'bookly-responsive-appointment-booking-tool' ) . '…',
                // The product's page opens with the sign-in dialog (cloud/account/Account.svelte).
                'url' => $product_url . '#login',
            );
        } elseif ( ! Knowledge::available() ) {
            $locked = array(
                'text' => __( 'Turn on the assistant in Bookly Cloud to start.', 'bookly-responsive-appointment-booking-tool' ),
                'button' => __( 'Go to Bookly Cloud', 'bookly-responsive-appointment-booking-tool' ),
                'url' => $product_url,
            );
        } else {
            $locked = null;
        }

        self::enqueueScripts( array(
            'module' => array( 'js/ai-admin.js' => array( 'bookly-backend-globals' ) ),
        ) );
        wp_add_inline_script(
            'bookly-ai-admin.js',
            sprintf( 'window.BooklyAiAdmin && BooklyAiAdmin.show(%s);', wp_json_encode( array(
                'visible' => $visible,
                'locked' => $locked,
                'l10n' => array(
                    // Message times in the site's time format (Settings → General of WordPress).
                    'timeFormat'         => get_option( 'time_format' ),
                    'title'              => __( 'Admin assistant', 'bookly-responsive-appointment-booking-tool' ),
                    'subtitle'           => __( 'Nothing changes until you apply it', 'bookly-responsive-appointment-booking-tool' ),
                    'greeting'           => __( 'What would you like to set up?', 'bookly-responsive-appointment-booking-tool' ),
                    'emptyState'         => __( 'Describe it in your own words, e.g. "clients should book at least 2 hours ahead"', 'bookly-responsive-appointment-booking-tool' ),
                    'placeholder'        => __( 'Ask what to set up…', 'bookly-responsive-appointment-booking-tool' ),
                    'send'               => __( 'Send', 'bookly-responsive-appointment-booking-tool' ),
                    'resetChat'          => __( 'New conversation', 'bookly-responsive-appointment-booking-tool' ),
                    'closeChat'          => __( 'Close', 'bookly-responsive-appointment-booking-tool' ),
                    'weSuggest'          => __( 'We suggest', 'bookly-responsive-appointment-booking-tool' ),
                    'why'                => __( 'Why', 'bookly-responsive-appointment-booking-tool' ),
                    'apply'              => __( 'Apply', 'bookly-responsive-appointment-booking-tool' ),
                    'undo'               => __( 'Undo', 'bookly-responsive-appointment-booking-tool' ),
                    'openSetting'        => __( 'Open setting', 'bookly-responsive-appointment-booking-tool' ),
                    'applied'            => __( 'Applied', 'bookly-responsive-appointment-booking-tool' ),
                    'undone'             => __( 'Undone', 'bookly-responsive-appointment-booking-tool' ),
                    'replaced'           => __( 'Replaced by a newer proposal', 'bookly-responsive-appointment-booking-tool' ),
                    /* translators: %d — number of hidden warnings */
                    'moreWarnings'       => __( '%d more', 'bookly-responsive-appointment-booking-tool' ),
                    'fewerWarnings'      => __( 'Show less', 'bookly-responsive-appointment-booking-tool' ),
                    'failed'             => __( 'Not applied: the settings changed meanwhile', 'bookly-responsive-appointment-booking-tool' ),
                    'error'              => __( 'Something went wrong. Try again in a moment.', 'bookly-responsive-appointment-booking-tool' ),
                    'errorQuotaExceeded' => __( 'The assistant\'s limit is reached. Try again later.', 'bookly-responsive-appointment-booking-tool' ),
                    // The booking chat's texts (Appearance → AI assistant), with its defaults.
                    'voiceStart'         => __( 'Voice input', 'bookly-responsive-appointment-booking-tool' ),
                    'voiceStop'          => __( 'Stop recording', 'bookly-responsive-appointment-booking-tool' ),
                    'voiceCancel'        => __( 'Cancel recording', 'bookly-responsive-appointment-booking-tool' ),
                    'voiceTimeLeft'      => _x( 'left', 'time', 'bookly-responsive-appointment-booking-tool' ),
                    'voiceDenied'        => __( 'Allow access to the microphone to dictate a message.', 'bookly-responsive-appointment-booking-tool' ),
                    'voiceError'         => __( 'Could not recognize the speech. Please try again or type your message.', 'bookly-responsive-appointment-booking-tool' ),
                ),
            ) ) )
        );
    }
}
