<?php
namespace Bookly\Backend;

use Bookly\Lib;

abstract class Backend
{
    /**
     * Register hooks.
     */
    public static function registerHooks()
    {
        $bookly_page = isset ( $_REQUEST['page'] ) && strncmp( $_REQUEST['page'], 'bookly-', 7 ) === 0;

        add_action( 'admin_menu', array( __CLASS__, 'addAdminMenu' ) );

        if ( ! Lib\Config::setupMode() ) {
            // Single notices area inside #wpbody-content (in_admin_header would print
            // them under #wpcontent — outside the fullscreen scroller, see appearance CSS).
            add_action( 'all_admin_notices', function() use ( $bookly_page ) {
                Backend::renderNotices( $bookly_page );
            } );
            if ( $bookly_page ) {
                // WordPress relocates admin notices after .wp-header-end when present,
                // otherwise after the first .wrap h1 — which is inside the Svelte page
                // header flex row and gets squeezed together with the title. Print the
                // marker last in the notices area, before the page wrap opens, so all
                // relocated notices stay above the page and outside .bookly-css-root
                // (bootstrap `#bookly-tbs a` / tailwind preflight would restyle them).
                add_action( 'all_admin_notices', function() {
                    echo '<hr class="wp-header-end">';
                }, PHP_INT_MAX );
            }
        }

        // for Site Health
        // Close current session, for fixing loopback request
        add_filter( 'site_status_tests', function( $tests ) {
            session_write_close();

            return $tests;
        }, 10, 1 );

        // Disable emoji in IE11
        if ( $bookly_page && array_key_exists( 'HTTP_USER_AGENT', $_SERVER ) && strpos( $_SERVER['HTTP_USER_AGENT'], 'Trident/7.0' ) !== false ) {
            Lib\Utils\Common::disableEmoji();
        }

        // Elementor hooks
        add_action( 'elementor/elements/categories_registered', function( $elements_manager ) {
            /** @var \Elementor\Elements_Manager $elements_manager */
            $elements_manager->add_category( 'bookly', array( 'title' => 'Bookly' ) );
        } );

        add_action( 'elementor/editor/before_enqueue_scripts', function() {
            wp_register_style(
                'bookly-elementor',
                plugins_url( '/backend/components/elementor/resources/css/elementor.css', __DIR__ ),
                array(),
                Lib\Plugin::getVersion()
            );
        } );

        // Divi
        add_filter( 'et_builder_module_categories', function ( $categories ) {
            $categories['bookly'] = 'Bookly';

            return $categories;
        } );

        // =====================================================================
        // Page appearance settings (per-user, persisted to user meta) for every
        // Bookly admin page. The "Page appearance" control lives in the Svelte
        // PageHeader (Components\PageHeader\Renderer): its switches flip a body
        // class for instant feedback AND POST to Components\PageHeader\Ajax
        // (bookly_save_appearance_settings) to persist. On each render we re-apply
        // from meta server-side, so the choice survives navigation with no flash.
        // Fullscreen mirrors WP core's own block-editor pattern
        // (`body.js.is-fullscreen-mode` -> display:none of the known core chrome),
        // gated on `.js` so the page degrades to a normal screen without JS.
        // =====================================================================
        if ( isset( $_REQUEST['page'] ) && strncmp( $_REQUEST['page'], 'bookly-', 7 ) === 0 ) {
            add_filter( 'admin_body_class', function ( $classes ) {
                // Setup wizard v2 always runs fullscreen — page-scoped, independent
                // of the per-user appearance setting. The marker class keeps the
                // #wpbody-content scroller rules off the wizard (it draws its own scene).
                if ( $_REQUEST['page'] === Modules\Setup\Page::pageSlug() && Modules\Setup\Page::resolveVariant() === 'v2' ) {
                    return $classes . ' bookly-fullscreen-active bookly-setup-wizard';
                }
                $ap = get_user_meta( get_current_user_id(), 'bookly_appearance', true );
                if ( ! is_array( $ap ) ) {
                    return $classes;
                }
                if ( ! empty( $ap['fullscreen'] ) )  { $classes .= ' bookly-fullscreen-active'; }
                if ( ! empty( $ap['fixed_width'] ) ) { $classes .= ' bookly-fixed-width'; }
                return $classes;
            } );
            add_action( 'admin_head', function () {
                // Rules only bite when the matching body class is present (added
                // from meta above, or toggled at runtime by the appearance panel).
                echo '<style id="bookly-appearance-css">'
                    // Admin notices relocated after our .wp-header-end marker (see
                    // registerHooks) become direct children of #wpbody-content, where
                    // WP's default 15px side margins don't line up with .wrap
                    // (margin: 10px 20px 0 2px) — align them with the page grid.
                    // margin-inline flips with RTL by itself.
                    . '#wpbody-content>div.notice,#wpbody-content>div.updated,#wpbody-content>div.error{margin-block:5px 2px;margin-inline:2px 20px;}'
                    // Fullscreen: hide the known WP core chrome (display:none — immune
                    // to z-index/stacking/timing) + overlay our page as a backstop for
                    // anything unknown. `.js`-gated, matching WP's own fullscreen.
                    . 'body.js.bookly-fullscreen-active #wpadminbar,'
                    . 'body.js.bookly-fullscreen-active #adminmenumain,'
                    . 'body.js.bookly-fullscreen-active #wpfooter{display:none!important;}'
                    // Lock the document scroll so only the fixed overlay scrolls
                    // (otherwise html/body scroll behind it = a second scrollbar).
                    . 'html:has(body.js.bookly-fullscreen-active){padding-top:0!important;overflow:hidden!important;}'
                    . 'body.js.bookly-fullscreen-active{overflow:hidden!important;}'
                    . 'body.js.bookly-fullscreen-active #wpcontent{margin-left:0!important;}'
                    // The fullscreen overlay/scroller is #wpbody-content (NOT the page wrap):
                    // both notice areas — Bookly ones (.wrap, all_admin_notices) and relocated
                    // third-party div.notice — are its direct children, so they stay visible
                    // and scroll together with the page.
                    . 'body.js.bookly-fullscreen-active:not(.bookly-setup-wizard) #wpbody-content{position:fixed;inset:0;z-index:100049;overflow:auto;padding:10px 20px;box-sizing:border-box;background:#f0f0f1;}'
                    // background uses !important: bootstrap resets #bookly-tbs to
                    // background-color:transparent (ID specificity) — a class can never
                    // out-specify an ID. Drop the !important once #bookly-tbs is gone.
                    . 'body.js.bookly-fullscreen-active .bookly-main-page-wrap{background:#f0f0f1!important;}'
                    // The scroller carries the side padding — flatten side margins of its
                    // children (page wrap, both notice kinds) so edges line up.
                    . 'body.js.bookly-fullscreen-active #wpbody-content>.wrap{margin-inline:0;}'
                    . 'body.js.bookly-fullscreen-active #wpbody-content>div.notice,body.js.bookly-fullscreen-active #wpbody-content>div.updated,body.js.bookly-fullscreen-active #wpbody-content>div.error{margin-inline:0;}'
                    // Fixed page width: cap and centre the content column, the page wrap and
                    // both notice areas alike (all direct children of #wpbody-content).
                    // box-sizing: div.notice is content-box — its padding/border would
                    // otherwise poke past the 1180px column the .wrap children align to.
                    . 'body.js.bookly-fixed-width #wpbody-content>.wrap,body.js.bookly-fixed-width #wpbody-content>div.notice,body.js.bookly-fixed-width #wpbody-content>div.updated,body.js.bookly-fixed-width #wpbody-content>div.error{max-width:1180px;margin-inline:auto;box-sizing:border-box;}'
                    // The auto margins above replace .wrap's own asymmetric WP margins
                    // (10px 20px 0 2px) and collapse to 0 when the viewport is narrower
                    // than the column — the right gutter vanished. Keep the gutters on
                    // the container instead (2px left complements #wpcontent's 20px
                    // padding-left, 20px right mirrors .wrap's default right margin).
                    . 'body.js.bookly-fixed-width:not(.bookly-fullscreen-active) #wpbody-content{padding-inline:2px 20px;box-sizing:border-box;}'
                    // WP core update nag is inline-block (width by content) — auto margins
                    // can't centre it; as a block it joins the fixed-width column.
                    . 'body.js.bookly-fixed-width #wpbody-content>div.update-nag{display:block;}'
                    // When also fullscreen, the scroller spans the viewport — centre the
                    // column via its padding, children stay full-width within it.
                    . 'body.js.bookly-fullscreen-active.bookly-fixed-width:not(.bookly-setup-wizard) #wpbody-content{padding-inline:max(20px,calc((100% - 1180px) / 2));}'
                    // Fullscreen left sidebar (the Svelte nav, w-60 = 240px) is shown only at >=md
                    // and pinned to the viewport — offset the scroller content right by it (240 + 20
                    // gutter). Below md the sidebar is an off-canvas drawer, so no offset there.
                    . '@media(min-width:768px){body.js.bookly-fullscreen-active:not(.bookly-setup-wizard) #wpbody-content{padding-left:260px;}}'
                    // ...and with fixed width too: centre the 1180px column within the space to the
                    // RIGHT of the sidebar (left = sidebar + gutter, right = matching gutter).
                    . '@media(min-width:768px){body.js.bookly-fullscreen-active.bookly-fixed-width:not(.bookly-setup-wizard) #wpbody-content{padding-left:calc(240px + max(20px,(100% - 240px - 1180px) / 2));padding-right:max(20px,(100% - 240px - 1180px) / 2);}}'
                    // Loading-window placeholder: a blank panel matching the sidebar footprint
                    // (240px, card bg, right border), shown only in fullscreen >= md and sitting
                    // one z-index below the real Svelte <aside> (z-40), which covers it on mount.
                    . '.bookly-fs-sidebar-placeholder{display:none;}'
                    . '@media(min-width:768px){body.js.bookly-fullscreen-active .bookly-fs-sidebar-placeholder{display:block;position:fixed;left:0;top:0;bottom:0;width:240px;z-index:39;background:#fff;border-right:1px solid #e5e7eb;}}'
                    // Sidebar nav scroller: hide the (ugly) scrollbar and fade whichever edges have
                    // more content beyond them. The header JS feeds the edge sizes via --bookly-fade-*
                    // (0px = no fade); a single mask covers top + bottom in any combination.
                    . '.bookly-fs-nav-scroll{scrollbar-width:none;-webkit-mask-image:linear-gradient(to bottom,transparent 0,#000 var(--bookly-fade-top,0px),#000 calc(100% - var(--bookly-fade-bottom,0px)),transparent 100%);mask-image:linear-gradient(to bottom,transparent 0,#000 var(--bookly-fade-top,0px),#000 calc(100% - var(--bookly-fade-bottom,0px)),transparent 100%);}'
                    . '.bookly-fs-nav-scroll::-webkit-scrollbar{width:0;height:0;display:none;}'
                    . '</style>';
            } );
        }
    }

    /**
     * @param bool $bookly_page
     * @return void
     */
    public static function renderNotices( $bookly_page )
    {
        static $handled;

        if ( ! $handled ) {
            if ( $bookly_page ) {
                // Subscribe notice.
                Components\Notices\Subscribe\Notice::render();
                // Lite rebranding notice.
                Components\Notices\Lite\Notice::render();
                // NPS notice.
                Components\Notices\Nps\Notice::render();
                // Collect stats notice.
                Components\Notices\Statistic\Notice::render();
                // Show Powered by Bookly notice.
                Components\Notices\PoweredBy\Notice::render();
                // Show SMS promotion notice.
                Components\Notices\Promotion\Notice::render();
                // Show renew auto-recharge notice.
                Components\Notices\RenewAutoRecharge\Notice::create( 'bookly-js-renew' )->render();
                // Show WPML re save notice.
                Components\Notices\Wpml\Notice::render();
            }
            // Let add-ons render admin notices.
            Lib\Proxy\Shared::renderAdminNotices( $bookly_page );
        }

        $handled = true;
    }

    /**
     * Admin menu.
     */
    public static function addAdminMenu()
    {
        /** @var \WP_User $current_user */
        global $current_user, $submenu;

        $is_staff = Lib\Entities\Staff::query()->where( 'wp_user_id', $current_user->ID )->count() > 0;
        $required_capability = Lib\Utils\Common::getRequiredCapability();
        if ( $current_user->has_cap( $required_capability ) || $current_user->has_cap( 'manage_bookly_appointments' ) || $is_staff ) {
            $dynamic_position = '80.0000001' . mt_rand( 1, 1000 ); // position always is under `Settings`
            $badge_number = 0;
            $calendar_badge = 0;
            if ( Lib\Utils\Common::isCurrentUserSupervisor() ) {
                $badge_number = Modules\Shop\Page::getNotSeenCount();
                if ( get_option( 'bookly_gen_badge_consider_news' ) ) {
                    $badge_number += Modules\News\Page::getNewsCount();
                }
                if ( get_option( 'bookly_cloud_badge_consider_sms' ) ) {
                    $badge_number += Lib\Cloud\SMS::getUndeliveredSmsCount();
                }
                if ( get_option( 'bookly_cal_show_new_appointments_badge' ) ) {
                    $calendar_badge = Modules\Calendar\Page::getAppointmentsCount();
                    $badge_number += $calendar_badge;
                }
            }
            if ( $badge_number ) {
                add_menu_page( 'Bookly', sprintf( 'Bookly <span class="update-plugins count-%d"><span class="update-count">%d</span></span>', $badge_number, $badge_number ), 'read', 'bookly-menu', '',
                    plugins_url( 'resources/images/menu.png', __FILE__ ), $dynamic_position );
            } else {
                add_menu_page( 'Bookly', 'Bookly', 'read', 'bookly-menu', '',
                    plugins_url( 'resources/images/menu.png', __FILE__ ), $dynamic_position );
            }
            if ( Lib\Config::setupMode() ) {
                // Warm up the Cloud info cache (promotions, wizard rollout config) so the
                // Setup page never waits for the network; fires only while the cache is empty.
                if ( ! is_array( get_option( 'bookly_cloud_promotions' ) ) ) {
                    Lib\Cloud\API::getInstance()->general->loadInfo();
                }
                $setup = __( 'Initial setup', 'bookly-responsive-appointment-booking-tool' );
                add_submenu_page( 'bookly-menu', $setup, $setup, $required_capability, Modules\Setup\Page::pageSlug(), function() { Modules\Setup\Page::render(); } );
            } elseif ( Lib\Proxy\Pro::graceExpired() ) {
                Lib\Proxy\Pro::addLicenseBooklyMenuItem();
                if ( isset ( $_GET['page'] ) && $_GET['page'] == 'bookly-diagnostics' ) {
                    Modules\Diagnostics\Page::addBooklyMenuItem();
                }
            } else {
                // Translated submenu pages.
                $dashboard = __( 'Dashboard', 'bookly-responsive-appointment-booking-tool' );
                $appointments = __( 'Appointments', 'bookly-responsive-appointment-booking-tool' );
                $staff_members = __( 'Staff Members', 'bookly-responsive-appointment-booking-tool' );
                $services = __( 'Services', 'bookly-responsive-appointment-booking-tool' );
                $notifications = __( 'Email Notifications', 'bookly-responsive-appointment-booking-tool' );
                $customers = __( 'Customers', 'bookly-responsive-appointment-booking-tool' );
                $payments = __( 'Payments', 'bookly-responsive-appointment-booking-tool' );
                $appearance = __( 'Appearance', 'bookly-responsive-appointment-booking-tool' );
                $settings = __( 'Settings', 'bookly-responsive-appointment-booking-tool' );
                $products = __( 'Products', 'bookly-responsive-appointment-booking-tool' );
                $billing = __( 'Billing', 'bookly-responsive-appointment-booking-tool' );

                // Menu order: everyday operational pages first (bookable products included),
                // then communications (SMS ahead of email), then business catalog, then the
                // configure-once cluster, closing with Appearance / Add-ons / Settings.
                add_submenu_page( 'bookly-menu', $dashboard, $dashboard, $required_capability,
                    Modules\Dashboard\Page::pageSlug(), function() { Modules\Dashboard\Page::render(); } );
                Modules\Calendar\Page::addBooklyMenuItem( $calendar_badge );
                if ( $current_user->has_cap( $required_capability ) || $current_user->has_cap( 'manage_bookly_appointments' ) ) {
                    add_submenu_page( 'bookly-menu', $appointments, $appointments, 'read',
                        Modules\Appointments\Page::pageSlug(), function() { Modules\Appointments\Page::render(); } );
                    Lib\Proxy\Events::addBooklyMenuItem();
                    Lib\Proxy\Packages::addBooklyMenuItem();
                }
                Lib\Proxy\GiftCards::addBooklyMenuItem();
                if ( $current_user->has_cap( $required_capability ) || $current_user->has_cap( 'manage_bookly_appointments' ) ) {
                    add_submenu_page( 'bookly-menu', $customers, $customers, 'read',
                        Modules\Customers\Page::pageSlug(), function() { Modules\Customers\Page::render(); } );
                    add_submenu_page( 'bookly-menu', $payments, $payments, 'read',
                        Modules\Payments\Page::pageSlug(), function() { Modules\Payments\Page::render(); } );
                }
                Modules\CloudSms\Page::addBooklyMenuItem();
                add_submenu_page( 'bookly-menu', $notifications, $notifications, $required_capability,
                    Modules\Notifications\Page::pageSlug(), function() { Modules\Notifications\Page::render(); } );
                if ( $current_user->has_cap( $required_capability ) ) {
                    add_submenu_page( 'bookly-menu', $staff_members, $staff_members, $required_capability,
                        Modules\Staff\Page::pageSlug(), function() { Modules\Staff\Page::render(); } );
                } elseif ( $is_staff ) {
                    if ( get_option( 'bookly_gen_allow_staff_edit_profile' ) == 1 ) {
                        add_submenu_page( 'bookly-menu', __( 'Profile', 'bookly-responsive-appointment-booking-tool' ), __( 'Profile', 'bookly-responsive-appointment-booking-tool' ), 'read',
                            Modules\Staff\Page::pageSlug(), function() { Modules\Staff\Page::render(); } );
                    }
                }
                add_submenu_page( 'bookly-menu', $services, $services, $required_capability,
                    Modules\Services\Page::pageSlug(), function() { Modules\Services\Page::render(); } );
                Lib\Proxy\Locations::addBooklyMenuItem();
                // Configure-once cluster — above the closing Appearance / Add-ons / Settings
                // trio so both menus end with the frequently needed pages (the fullscreen
                // sidebar folds this cluster into "More" at the very bottom instead).
                Lib\Proxy\Taxes::addBooklyMenuItem();
                Lib\Proxy\CustomerInformation::addBooklyMenuItem();
                Lib\Proxy\CustomerGroups::addBooklyMenuItem();
                Lib\Proxy\Discounts::addBooklyMenuItem();
                Lib\Proxy\Coupons::addBooklyMenuItem();
                Lib\Proxy\CustomFields::addBooklyMenuItem();
                Modules\Diagnostics\Page::addBooklyMenuItem();
                Modules\News\Page::addBooklyMenuItem();
                add_submenu_page( 'bookly-menu', $appearance, $appearance, $required_capability,
                    Modules\Appearance\Page::pageSlug(), function() { Modules\Appearance\Page::render(); } );
                Modules\Shop\Page::addBooklyMenuItem();
                add_submenu_page(
                    'bookly-menu', $settings, $settings, $required_capability,
                    Modules\Settings\Page::pageSlug(), function() { Modules\Settings\Page::render(); }
                );

                if ( ! Lib\Config::proActive() ) {
                    $submenu['bookly-menu'][] = array( esc_attr__( 'Get Bookly Pro', 'bookly-responsive-appointment-booking-tool' ) . ' <i class="fas fa-fw fa-certificate" style="color: #f4662f"></i>', 'read', Lib\Utils\Common::prepareUrlReferrers( 'https://www.booking-wp-plugin.com/pricing', 'admin_menu' ), );
                }

                // Bookly Cloud menu
                $cloud = Lib\Cloud\API::getInstance();
                $dynamic_position .= '1'; // position always is under `Bookly`
                $page_title = $menu_title = 'Bookly Cloud';
                if ( $cloud->general->getPromotionForNotice() ) {
                    $menu_title .= ' <span class="update-plugins"><span class="update-count">$</span></span>';
                }
                add_menu_page( $page_title, $menu_title, $required_capability, 'bookly-cloud-menu', '',
                    plugins_url( 'resources/images/menu_cloud.png', __FILE__ ), $dynamic_position );
                add_submenu_page( 'bookly-cloud-menu', $products, $products, $required_capability,
                    Modules\CloudProducts\Page::pageSlug(), function() { Modules\CloudProducts\Page::render(); } );
                if ( $cloud->getToken() ) {
                    foreach ( $cloud->general->getProducts() as $product ) {
                        if ( $cloud->account->productActive( $product['id'] ) ) {
                            switch ( $product['id'] ) {
                                case Lib\Cloud\Account::PRODUCT_SMS_NOTIFICATIONS:
                                    Modules\CloudSms\Page::addBooklyCloudMenuItem( $product );
                                    break;
                                case Lib\Cloud\Account::PRODUCT_ZAPIER:
                                    Modules\CloudZapier\Page::addBooklyCloudMenuItem( $product );
                                    break;
                                case Lib\Cloud\Account::PRODUCT_VOICE:
                                    Modules\CloudVoice\Page::addBooklyCloudMenuItem( $product );
                                    break;
                                case Lib\Cloud\Account::PRODUCT_WHATSAPP:
                                    Modules\CloudWhatsapp\Page::addBooklyCloudMenuItem( $product );
                                    break;
                                case Lib\Cloud\Account::PRODUCT_MOBILE_STAFF_CABINET:
                                    Modules\CloudMobileStaffCabinet\Page::addBooklyCloudMenuItem( $product );
                                    break;
                            }
                        }
                    }
                    add_submenu_page( 'bookly-cloud-menu', $billing, $billing, $required_capability,
                        Modules\CloudBilling\Page::pageSlug(), function() { Modules\CloudBilling\Page::render(); } );
                    // The Cloud account: who is signed in, country, password, notifications, Auto-Recharge
                    $account = __( 'Account', 'bookly-responsive-appointment-booking-tool' );
                    add_submenu_page( 'bookly-cloud-menu', $account, $account, $required_capability,
                        Modules\CloudSettings\Page::pageSlug(), function() { Modules\CloudSettings\Page::render(); } );
                }
            }

            unset( $submenu['bookly-menu'][0], $submenu['bookly-cloud-menu'][0] );
        }
    }
}