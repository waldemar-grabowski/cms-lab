<?php
namespace Bookly\Lib\AiAdmin;

use Bookly\Lib;

/**
 * Who may read and change an entry, and whether the plugin that shows its control is active.
 */
abstract class Access
{
    /**
     * @param string[] $roles assistant.read / assistant.edit of the entry (admin, staff_own)
     * @return bool
     */
    public static function allowed( array $roles )
    {
        if ( in_array( 'admin', $roles, true ) && Lib\Utils\Common::isCurrentUserAdmin() ) {
            return true;
        }

        return false;
    }

    /**
     * Whether an add-on is active, by its slug (folder bookly-addon-<slug>).
     *
     * @param string $slug
     * @return bool
     */
    public static function addonActive( $slug )
    {
        $camel = str_replace( ' ', '', ucwords( str_replace( '-', ' ', $slug ) ) );

        return (bool) call_user_func( array( '\Bookly\Lib\Config', lcfirst( $camel ) . 'Active' ) );
    }

    /**
     * Slugs of the active Bookly add-ons, without the ones never offered to the owner
     * (Rules::hiddenAddons()). Every add-on works only with Pro, so without Pro the list is empty.
     *
     * @return array
     */
    public static function installedAddons()
    {
        $slugs = array();
        if ( Lib\Config::proActive() ) {
            foreach ( glob( WP_PLUGIN_DIR . '/bookly-addon-*', GLOB_ONLYDIR ) ?: array() as $dir ) {
                $slug = substr( basename( $dir ), strlen( 'bookly-addon-' ) );
                if ( ! in_array( $slug, Rules::hiddenAddons(), true ) && self::addonActive( $slug ) ) {
                    $slugs[] = $slug;
                }
            }
        }

        return $slugs;
    }

    /**
     * Rule of an entry, or null for an unknown or hidden one.
     *
     * @param string $ref
     * @return array|null
     */
    public static function rule( $ref )
    {
        $entries = Rules::entries();
        if ( ! isset( $entries[ $ref ] ) || ! empty( $entries[ $ref ]['hidden'] ) ) {
            return null;
        }

        return $entries[ $ref ];
    }
}
