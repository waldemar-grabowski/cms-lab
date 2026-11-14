<?php
namespace Bookly\Backend\Components\PageHeader;

use Bookly\Lib;

class Ajax extends Lib\Base\Ajax
{
    /**
     * @inheritDoc
     */
    protected static function permissions()
    {
        // Per-user preference — any logged-in user who can see a Bookly page.
        return array( '_default' => 'user' );
    }

    /**
     * Save the current user's "Page appearance" settings (fullscreen, fixed width).
     * Re-applied server-side on every Bookly page render (see Backend::registerHooks).
     */
    public static function saveAppearanceSettings()
    {
        update_user_meta( get_current_user_id(), 'bookly_appearance', array(
            'fullscreen'  => (int) self::parameter( 'fullscreen', 0 ) ? 1 : 0,
            'fixed_width' => (int) self::parameter( 'fixed_width', 0 ) ? 1 : 0,
        ) );

        wp_send_json_success();
    }
}
