<?php
namespace Bookly\Backend\Components\TinyMce;

use Bookly\Lib;

class Tools extends Lib\Base\Component
{
    public static function init()
    {
        global $PHP_SELF;
        if ( // check if we are in admin area and current page is adding/editing the post
            is_admin() && ( strpos( $PHP_SELF, 'post-new.php' ) !== false || strpos( $PHP_SELF, 'post.php' ) !== false || strpos( $PHP_SELF, 'admin-ajax.php' ) )
        ) {
            add_action( 'admin_footer', array( '\Bookly\Backend\Components\TinyMce\Tools', 'renderPopup' ), 10, 0 );
            add_filter( 'media_buttons', array( '\Bookly\Backend\Components\TinyMce\Tools', 'addButton' ), 50, 1 );
            add_action( 'elementor/editor/footer', array( '\Bookly\Backend\Components\TinyMce\Tools', 'renderPopup' ), 10, 0 );
        }
    }

    public static function addButton( $editor_id )
    {
        // don't show on dashboard (QuickPress)
        $current_screen = get_current_screen();
        if ( $current_screen && 'dashboard' == $current_screen->base ) {
            return;
        }

        // don't display button for users who don't have access
        if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'edit_pages' ) ) {
            return;
        }

        // do a version check for the new 3.5 UI
        $version = get_bloginfo( 'version' );

        if ( $version < 3.5 ) {
            // show button for v 3.4 and below
            echo '<a href="#TB_inline?width=640&inlineId=bookly-tinymce-popup&height=650" id="add-bookly-form" title="' . esc_attr__( 'Add Bookly booking form', 'bookly-responsive-appointment-booking-tool' ) . '">' . __( 'Add Bookly booking form', 'bookly-responsive-appointment-booking-tool' ) . '</a>';
        } else {
            // display button matching new UI
            $img = '<span class="bookly-media-icon"></span> ';
            echo '<a href="#TB_inline?width=640&inlineId=bookly-tinymce-popup&height=650" id="add-bookly-form" class="thickbox button bookly-media-button" title="' . esc_attr__( 'Add Bookly booking form', 'bookly-responsive-appointment-booking-tool' ) . '">' . $img . __( 'Add Bookly booking form', 'bookly-responsive-appointment-booking-tool' ) . '</a>';
        }

        // One button per non-classic type — core (TYPE_AI_ASSISTANT) and
        // whatever Pro contributes (Lib\Entities\Form::getTypes() merges
        // both, empty Pro part when Pro is inactive). Not routed through
        // Proxy\Shared — this must work without Pro providing that proxy.
        foreach ( Lib\Entities\Form::getTypes() as $type ) {
            if ( $type === Lib\Entities\Form::TYPE_BOOKLY_FORM ) {
                continue;
            }
            $title = sprintf( __( 'Add Bookly %s', 'bookly-responsive-appointment-booking-tool' ), Lib\Entities\Form::getTitle( $type ) );
            if ( $version < 3.5 ) {
                echo '<a href="#TB_inline?width=400&inlineId=bookly-' . $type . '-popup&height=300" id="add-' . $type . '-form" title="' . esc_attr( $title ) . '">' . $title . '</a>';
            } else {
                echo '<a href="#TB_inline?width=400&inlineId=bookly-' . $type . '-popup&height=300" class="thickbox button bookly-media-button" title="' . esc_attr( $title ) . '">' . $img . $title . '</a>';
            }
        }

        Proxy\Shared::renderMediaButtons( $version );
    }

    public static function enqueueAssets()
    {
        self::enqueueScripts( array(
            'module' => array( 'js/bookly-form-settings.js' => array( 'jquery', 'bookly-backend-globals' ), ),
        ) );

        self::enqueueData( array(
            'casest',
            'custom_location_settings',
        ) );

        wp_localize_script( 'bookly-bookly-form-settings.js', 'BooklyFormShortCodeL10n', array(
            'title' => __( 'Insert Appointment Booking Form', 'bookly-responsive-appointment-booking-tool' ),
        ) );
    }

    public static function renderPopup()
    {
        self::enqueueAssets();
        self::renderTemplate( 'bookly_popup' );

        foreach ( Lib\Entities\Form::getTypes() as $type ) {
            if ( $type === Lib\Entities\Form::TYPE_BOOKLY_FORM ) {
                continue;
            }
            $forms = Lib\Entities\Form::query()->select( 'name, token' )->where( 'type', $type )->fetchArray();
            if ( ! $forms ) {
                $forms = array( array( 'name' => __( 'Default', 'bookly-responsive-appointment-booking-tool' ), 'token' => '' ) );
            }
            self::renderTemplate( 'modern_form', compact( 'type', 'forms' ) );
        }

        Proxy\Shared::renderPopup();
    }
}
