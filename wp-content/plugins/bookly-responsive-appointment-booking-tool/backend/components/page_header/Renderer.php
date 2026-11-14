<?php
namespace Bookly\Backend\Components\PageHeader;

use Bookly\Lib;
use Bookly\Backend\Components\Cloud\Account as CloudAccount;
use Bookly\Backend\Components\Support\Buttons as SupportButtons;
use Bookly\Backend\Components\Support\Lib\Urls;
use Bookly\Backend\Modules\AiAdmin\Launcher as AiAdminLauncher;

class Renderer extends Lib\Base\Component
{
    /**
     * Render the Svelte-powered page header (breadcrumbs + support links).
     *
     * @param string $page_slug Current admin page slug — used in the docs URL.
     * @param string $current_label Visible name of the current page (last breadcrumb).
     * @param array $actions Extra page-level action buttons rendered in the header
     *   bar with the same base style as the support links. Each item:
     *   [ 'icon' => string, 'label' => string, 'href'|'id'|'class'|'variant'|
     *     'danger'|'modal'|'title' => ... ]. The built-in unfiltered_html warning
     *   is prepended automatically.
     */
    public static function render( $page_slug, $current_label, array $actions = array() )
    {
        self::enqueueStyles( array(
            'backend' => array( 'tailwind/tailwind.css' ),
        ) );

        self::enqueueScripts( array(
            'backend' => array( 'js/bookly-header.js' => array( 'bookly-backend-globals' ) ),
        ) );

        $current_user = wp_get_current_user();

        // "View at Bookly Pro Demo" — only on non-Pro pages that have a demo
        // counterpart. Until the user dismisses the intro, the button opens the
        // demo info dialog (SupportDialogs.svelte in the header bundle); afterwards
        // it links straight to the demo.
        $demo_url = SupportButtons::getDemoUrl( $page_slug );
        if ( $demo_url ) {
            $label = __( 'View this page at Bookly Pro Demo', 'bookly-responsive-appointment-booking-tool' );
            if ( get_user_meta( get_current_user_id(), 'bookly_dismiss_demo_site_description', true ) ) {
                $actions[] = array( 'icon' => 'demo', 'label' => $label, 'href' => $demo_url, 'target' => '_blank', 'variant' => 'outline' );
            } else {
                $actions[] = array( 'icon' => 'demo', 'label' => $label, 'dialog' => 'demo', 'variant' => 'outline' );
            }
        }

        // Setup assistant: its floating button and chat panel, on every Bookly page.
        AiAdminLauncher::enqueue();

        // Warn users without the `unfiltered_html` capability that their HTML
        // will be sanitized on save (the role can't store raw markup). Goes
        // after the support links (trailing), as in the legacy header.
        $trailing_actions = array();
        if ( ! current_user_can( 'unfiltered_html' ) ) {
            $trailing_actions[] = array(
                'icon'    => 'alert',
                'danger'  => true,
                'title'   => sprintf( __( 'Please note that your user doesn\'t have the %s capability. All potentially unsecure HTML in Appearance, Email Notifications, and other Bookly sections will be sanitized during the saving of any changes', 'bookly-responsive-appointment-booking-tool' ), '`unfiltered_html`' ),
            );
        }

        // Current per-user "Page appearance" state (persisted to user meta by the
        // bookly_save_appearance AJAX handler — see Backend::registerHooks). Drives
        // the initial switch positions in the header's appearance panel.
        $appearance = get_user_meta( get_current_user_id(), 'bookly_appearance', true );
        if ( ! is_array( $appearance ) ) {
            $appearance = array();
        }

        // Fullscreen navigation: WordPress has already built the Bookly admin menu
        // into the $submenu global (registered in Backend::addAdminMenu) by the time
        // this renders — so we read it instead of re-hardcoding. Drives the title
        // dropdown shown in fullscreen (where the WP left menu is hidden).
        global $submenu, $menu;
        $current_page = isset( $_REQUEST['page'] ) ? $_REQUEST['page'] : '';
        // In-page tabs of pages that have them (Settings, Cloud SMS, …), keyed by page slug —
        // so any page's tabs are reachable as a submenu from the fullscreen sidebar. Add-ons
        // extend this map via Proxy (see Lib\Utils\Common::getSubmenus).
        $submenus = Lib\Utils\Common::getSubmenus();
        $current_tab = isset( $_REQUEST['tab'] ) ? $_REQUEST['tab'] : null;
        // Section icons — the same top-level menu icons WordPress shows (icon URL is $menu item [6]),
        // so the sidebar group headers carry the Bookly / Bookly Cloud glyphs.
        $group_icons = array();
        foreach ( (array) $menu as $m ) {
            if ( isset( $m[2], $m[6] ) ) {
                $group_icons[ $m[2] ] = $m[6];
            }
        }
        $nav = array();
        $nav_groups = array(
            'bookly-menu'       => 'Bookly',
            'bookly-cloud-menu' => 'Bookly Cloud',
        );
        // Curated set of configure-once pages folded into a collapsible "More" entry at the
        // bottom of the Bookly group — keeps the everyday items above the fold. Only pages
        // without in-page tabs qualify (a tabbed page inside More would need a third level).
        $more_slugs = array(
            'bookly-taxes',
            'bookly-customer-information',
            'bookly-customer-groups',
            'bookly-discounts',
            'bookly-coupons',
            'bookly-custom-fields',
            'bookly-diagnostics',
            'bookly-news',
        );
        foreach ( $nav_groups as $parent => $group_label ) {
            if ( empty( $submenu[ $parent ] ) ) {
                continue;
            }
            $items = array();
            $more_items = array();
            foreach ( $submenu[ $parent ] as $item ) {
                $slug = isset( $item[2] ) ? $item[2] : '';
                // Menu titles may carry a count bubble in the WordPress convention —
                // <span class="update-plugins …"><span class="update-count">N</span></span>
                // (News, Shop, undelivered SMS, Cloud promotions). Cut the bubble out and
                // keep the number as a separate badge, otherwise strip_all_tags glues
                // "News 60". Zero counts stay hidden — WordPress does the same via
                // .count-0 CSS, and a non-numeric bubble ("$") carries no count.
                // Everything else is part of the title and must survive: an item may
                // style a piece of its own label ("Bookly <span>Add-ons</span>"), which a
                // blanket "strip from the first <span> on" would collapse to "Bookly".
                $raw = isset( $item[0] ) ? $item[0] : '';
                $badge = '';
                if ( preg_match( '#<span[^>]*\bupdate-plugins\b.*?</span>#is', $raw, $bubble ) ) {
                    if ( preg_match( '/\d+/', $bubble[0], $count ) && (int) $count[0] > 0 ) {
                        $badge = $count[0];
                    }
                    $raw = str_replace( $bubble[0], ' ', $raw );
                }
                $label = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $raw ) ) );
                // The Bookly-menu "SMS Notifications" entry is a JS-redirect hack (empty slug + inline
                // script pointing to the SMS page, or to Cloud Products when the SMS product isn't owned).
                // Resolve it the same way so it appears under Bookly too — mirrors the WordPress menu.
                // It's a duplicate of the canonical item under Bookly Cloud, so never mark it current
                // (keep the highlight on the Bookly Cloud one).
                $duplicate = false;
                if ( $slug === '' && strpos( $raw, 'bookly-js-sms-menu-redirect' ) !== false ) {
                    $cloud = Lib\Cloud\API::getInstance();
                    $slug = ( $cloud->getToken() && $cloud->account->productActive( Lib\Cloud\Account::PRODUCT_SMS_NOTIFICATIONS ) )
                        ? 'bookly-cloud-sms'
                        : 'bookly-cloud-products';
                    $label = __( 'SMS Notifications', 'bookly-responsive-appointment-booking-tool' );
                    $duplicate = true;
                }
                if ( $slug === '' || $label === '' || ( isset( $item[1] ) && ! current_user_can( $item[1] ) ) ) {
                    continue;
                }
                if ( strpos( $slug, '://' ) !== false ) {
                    $url = $slug;
                } elseif ( strpos( $slug, '.php' ) !== false ) {
                    $url = admin_url( $slug );
                } else {
                    $url = admin_url( 'admin.php?page=' . $slug );
                }
                if ( in_array( $slug, $more_slugs, true ) ) {
                    $more_items[] = array(
                        'label'  => $label,
                        'url'    => $url,
                        'active' => $slug === $current_page,
                        'badge'  => $badge,
                    );
                    continue;
                }
                // Attach the page's in-page sections as a submenu, navigable from anywhere. Two
                // schemes: a ?tab= page (supply 'tab'), or a custom-URL page (supply 'url' + either
                // the bare query 'flag' used to detect the active one, e.g. appearance forms, or a
                // 'param' whose 'value' names the active one, e.g. cloud products). Active is
                // highlighted only while on that page.
                $item_submenu = array();
                if ( ! empty( $submenus[ $slug ] ) ) {
                    $is_current = ( ! $duplicate && $slug === $current_page );
                    foreach ( $submenus[ $slug ] as $i => $tab ) {
                        if ( isset( $tab['url'] ) ) {
                            if ( isset( $tab['param'] ) ) {
                                $active = isset( $_GET[ $tab['param'] ] ) && $_GET[ $tab['param'] ] === $tab['value'];
                            } else {
                                $active = isset( $tab['flag'] ) && isset( $_GET[ $tab['flag'] ] );
                            }
                            $item_submenu[] = array(
                                'label'  => $tab['label'],
                                'url'    => $tab['url'],
                                'active' => $is_current && $active,
                                'badge'  => isset( $tab['badge'] ) ? $tab['badge'] : '',
                            );
                        } else {
                            $item_submenu[] = array(
                                'label'  => $tab['label'],
                                'url'    => admin_url( 'admin.php?page=' . $slug . '&tab=' . $tab['tab'] ),
                                'active' => $is_current && ( $current_tab === $tab['tab'] || ( $current_tab === null && $i === 0 ) ),
                                'badge'  => isset( $tab['badge'] ) ? $tab['badge'] : '',
                            );
                        }
                    }
                }
                $items[] = array(
                    'label'   => $label,
                    'url'     => $url,
                    'current' => ! $duplicate && $slug === $current_page,
                    'badge'   => $badge,
                    'submenu' => $item_submenu,
                );
            }
            if ( $more_items ) {
                // No own URL — the sidebar renders a URL-less item as a pure expander row.
                $items[] = array(
                    'label'   => __( 'More', 'bookly-responsive-appointment-booking-tool' ),
                    'url'     => '',
                    'current' => false,
                    'badge'   => '',
                    'submenu' => $more_items,
                );
            }
            if ( $items ) {
                $nav[] = array(
                    'label' => $group_label,
                    'icon'  => isset( $group_icons[ $parent ] ) ? $group_icons[ $parent ] : null,
                    'items' => $items,
                );
            }
        }

        // Gates satisfied on THIS site — drives whether a search result navigates to its page or,
        // for a feature the client doesn't own, opens the pricing page instead. "core" is always
        // available; add-ons map from active plugins; cloud from the connected account's products.
        $search_available = array( 'core' );
        $active_plugins = (array) get_option( 'active_plugins', array() );
        if ( is_multisite() ) {
            $active_plugins = array_merge( $active_plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
        }
        foreach ( $active_plugins as $plugin ) {
            if ( preg_match( '#^(bookly-addon-[^/]+)/#', $plugin, $m ) ) {
                $search_available[] = $m[1];
            }
        }
        $cloud = Lib\Cloud\API::getInstance();
        if ( $cloud->getToken() ) {
            $search_available[] = 'cloud';
            foreach ( (array) $cloud->general->getProducts() as $product ) {
                if ( isset( $product['id'] ) && $cloud->account->productActive( $product['id'] ) ) {
                    $search_available[] = 'cloud:' . $product['id'];
                }
            }
        }

        // Pick the search index for the user's locale (search-index-<locale>.json), falling back to
        // the English base. Cache-bust by the file's mtime so a regenerated index is picked up.
        $search_rel = 'backend/resources/data/search-index.json';
        $localized = 'backend/resources/data/search-index-' . get_user_locale() . '.json';
        if ( is_readable( dirname( Lib\Plugin::getMainFile() ) . '/' . $localized ) ) {
            $search_rel = $localized;
        }
        $search_ver = @filemtime( dirname( Lib\Plugin::getMainFile() ) . '/' . $search_rel ) ?: Lib\Plugin::getVersion();

        // Pending support nudges → a red dot on the Help button and on the matching menu item (replaces
        // the legacy popovers, which don't fit the dropdown). Clicking the item fires its dismiss action.
        $support_notices = SupportButtons::getNotices();

        $options = array(
            // Section title only. The "Bookly" dropdown level was removed — it
            // duplicated the WordPress left admin menu (no hosted/embedded
            // context planned where that nav would be needed). See PageHeader.svelte.
            'breadcrumbs' => array(
                array(
                    'label' => $current_label,
                ),
            ),
            'actions' => array_values( $actions ),
            'trailingActions' => array_values( $trailing_actions ),
            'nav' => $nav,
            'appearance' => array(
                'fullscreen' => ! empty( $appearance['fullscreen'] ),
                'fixedWidth' => ! empty( $appearance['fixed_width'] ),
                'fontSize'   => isset( $appearance['font_size'] ) && in_array( $appearance['font_size'], array( 's', 'm', 'l' ), true ) ? $appearance['font_size'] : 'm',
                // Rightmost button in fullscreen links back to the WordPress dashboard.
                'wpAdminUrl' => admin_url(),
                'l10n' => array(
                    'help'           => __( 'Help', 'bookly-responsive-appointment-booking-tool' ),
                    'title'          => __( 'Page appearance', 'bookly-responsive-appointment-booking-tool' ),
                    'fontSize'       => __( 'Font size', 'bookly-responsive-appointment-booking-tool' ),
                    'fontSmall'      => __( 'Small', 'bookly-responsive-appointment-booking-tool' ),
                    'fontMedium'     => __( 'Medium', 'bookly-responsive-appointment-booking-tool' ),
                    'fontLarge'      => __( 'Large', 'bookly-responsive-appointment-booking-tool' ),
                    'fixedWidth'     => __( 'Fixed page width', 'bookly-responsive-appointment-booking-tool' ),
                    'fixedWidthHint' => __( 'Limit content width on wide screens', 'bookly-responsive-appointment-booking-tool' ),
                    'fullscreen'     => __( 'Fullscreen mode', 'bookly-responsive-appointment-booking-tool' ),
                    'fullscreenHint' => __( 'Hide WordPress menus', 'bookly-responsive-appointment-booking-tool' ),
                    'exitFullscreen' => __( 'Exit fullscreen', 'bookly-responsive-appointment-booking-tool' ),
                    'backToWp'       => __( 'Go to WordPress dashboard', 'bookly-responsive-appointment-booking-tool' ),
                    'menu'           => __( 'Menu', 'bookly-responsive-appointment-booking-tool' ),
                    'close'          => __( 'Close', 'bookly-responsive-appointment-booking-tool' ),
                ),
            ),
            'search' => array(
                // Cache-bust by the artifact's mtime so a regenerated index is picked up without a
                // hard reload in dev; in a release the file changes per build anyway.
                'url'       => plugins_url( $search_rel, Lib\Plugin::getMainFile() ) . '?v=' . $search_ver,
                'adminUrl'  => admin_url( 'admin.php' ),
                'available' => array_values( array_unique( $search_available ) ),
                'l10n'      => array(
                    'button'      => __( 'Search', 'bookly-responsive-appointment-booking-tool' ),
                    'placeholder' => __( 'Search settings, pages, payment methods', 'bookly-responsive-appointment-booking-tool' ) . '…',
                    'empty'       => __( 'No results found', 'bookly-responsive-appointment-booking-tool' ),
                    'required'    => __( 'required', 'bookly-responsive-appointment-booking-tool' ),
                    'recent'      => __( 'Recent', 'bookly-responsive-appointment-booking-tool' ),
                    'clear'       => __( 'Clear', 'bookly-responsive-appointment-booking-tool' ),
                ),
            ),
            'supportLinks' => array(
                array(
                    'icon'   => 'help',
                    'label'  => __( 'Documentation', 'bookly-responsive-appointment-booking-tool' ),
                    'href'   => 'https://hub.bookly.pro/go/' . $page_slug,
                ),
                array(
                    'icon'    => 'contact',
                    'label'   => __( 'Contact us', 'bookly-responsive-appointment-booking-tool' ),
                    'dialog'  => 'contact',
                    'dot'     => (bool) $support_notices['contact_us'],
                    'dismiss' => 'bookly_contact_us_btn_clicked',
                ),
                array(
                    'icon'   => 'feature',
                    'label'  => __( 'Feature requests', 'bookly-responsive-appointment-booking-tool' ),
                    'dialog' => get_user_meta( get_current_user_id(), 'bookly_dismiss_feature_requests_description', true ) ? null : 'feature',
                    'href'   => Lib\Utils\Common::prepareUrlReferrers( Urls::FEATURES_REQUEST_PAGE, 'notification_bar' ),
                ),
                array(
                    'icon'    => 'feedback',
                    'label'   => __( 'Feedback', 'bookly-responsive-appointment-booking-tool' ),
                    'href'    => Lib\Utils\Common::prepareUrlReferrers( Urls::REVIEWS_PAGE, 'feedback' ),
                    'dot'     => (bool) $support_notices['feedback'],
                    'dismiss' => 'bookly_dismiss_feedback_notice',
                ),
            ),
            // Data for the Svelte support dialogs (SupportDialogs.svelte) — the
            // replacement for the legacy bootstrap modals of Support\Buttons::renderModals().
            'supportDialogs' => array(
                'user' => array(
                    'name'  => trim( $current_user->user_firstname . ' ' . $current_user->user_lastname ),
                    'email' => $current_user->user_email,
                ),
                'featureRequestsUrl' => Lib\Utils\Common::prepareUrlReferrers( Urls::FEATURES_REQUEST_PAGE, 'notification_bar' ),
                'demoUrl' => $demo_url,
                'l10n' => array(
                    'contactTitle'   => __( 'Leave us a message', 'bookly-responsive-appointment-booking-tool' ),
                    'contactName'    => __( 'Your name', 'bookly-responsive-appointment-booking-tool' ),
                    'contactEmail'   => __( 'Email address', 'bookly-responsive-appointment-booking-tool' ),
                    'contactMsg'     => __( 'How can we help you?', 'bookly-responsive-appointment-booking-tool' ),
                    'send'           => __( 'Send', 'bookly-responsive-appointment-booking-tool' ),
                    'cancel'         => __( 'Cancel', 'bookly-responsive-appointment-booking-tool' ),
                    'featureTitle'   => __( 'Feature requests', 'bookly-responsive-appointment-booking-tool' ),
                    'featureP1'      => __( 'In the Feature Requests section of our Community, you can make suggestions about what you\'d like to see in our future releases.', 'bookly-responsive-appointment-booking-tool' ),
                    'featureP2'      => __( 'Before you post, please check if the same suggestion has already been made. If so, vote for ideas you like and add a comment with the details about your situation.', 'bookly-responsive-appointment-booking-tool' ),
                    'featureP3'      => __( 'It\'s much easier for us to address a suggestion if we clearly understand the context of the issue, the problem, and why it matters to you. When commenting or posting, please consider these questions so we can get a better idea of the problem you\'re facing:', 'bookly-responsive-appointment-booking-tool' ),
                    'featureLi1'     => __( 'What is the issue you\'re struggling with?', 'bookly-responsive-appointment-booking-tool' ),
                    'featureLi2'     => __( 'Where in your workflow do you encounter this issue?', 'bookly-responsive-appointment-booking-tool' ),
                    'featureLi3'     => __( 'Is this something that impacts just you, your whole team, or your customers?', 'bookly-responsive-appointment-booking-tool' ),
                    'featureProceed' => __( 'Proceed to Feature requests', 'bookly-responsive-appointment-booking-tool' ),
                    'demoTitle'      => __( 'Visit demo', 'bookly-responsive-appointment-booking-tool' ),
                    'demoP1'         => __( 'The demo is a version of Bookly Pro with all installed add-ons so that you can try all the features and capabilities of the system and then choose the most suitable configuration according to your business needs.', 'bookly-responsive-appointment-booking-tool' ),
                    'demoProceed'    => __( 'Proceed to demo', 'bookly-responsive-appointment-booking-tool' ),
                    'dontShowAgain'  => __( 'don\'t show this notification again', 'bookly-responsive-appointment-booking-tool' ),
                ),
            ),
        );

        wp_add_inline_script(
            'bookly-bookly-header.js',
            sprintf( 'window.BooklyHeader && BooklyHeader.show("bookly-page-header", %s);', wp_json_encode( $options ) )
        );

        echo '<div id="bookly-page-header"></div>';
        // Empty sidebar placeholder for the fullscreen loading window: fills the reserved
        // left column with a clean blank panel until the Svelte <aside> mounts over it
        // (CSS gates it to fullscreen >= md). Avoids the bare body-coloured gap.
        echo '<div class="bookly-fs-sidebar-placeholder"></div>';

        // Every page of the Bookly Cloud section opens with the account block.
        if ( strncmp( $page_slug, 'bookly-cloud-', 13 ) === 0 ) {
            CloudAccount\Panel::render();
        }
    }
}
