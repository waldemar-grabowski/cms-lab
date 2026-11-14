<?php
namespace Bookly\Backend\Modules\Shop;

use Bookly\Lib;

class Ajax extends Lib\Base\Ajax
{
    /**
     * Get data for shop page.
     */
    public static function getShopData()
    {
        $products = self::withLocalState( self::catalogue() );

        // Mark all plugins as seen — opening the page is what clears the menu counter.
        Lib\Entities\Shop::query()->update()->set( 'seen', 1 )->execute();

        wp_send_json_success( array( 'products' => $products ) );
    }

    /**
     * The catalogue, in the language of whoever is looking at it.
     *
     * One source. What identifies a product lives in its columns because the licensing
     * code selects on it; everything else the catalogue says lives in `content` beside
     * it. Two stores would have to be kept in step, and nothing would notice when they
     * stopped being.
     *
     * Empty `content` shows an empty product rather than falling back to the columns
     * Hub used to fill. Those fallbacks were written for the changeover and never once
     * fired: the catalogue sends `prices` even when a product has none, so the price
     * fallback was unreachable — and reachable it would have been wrong, showing a
     * marketplace price for something the catalogue says is not for sale.
     *
     * @return array
     */
    protected static function catalogue()
    {
        $query = Lib\Entities\Shop::query()
            ->sortBy( 'priority DESC, published' )
            ->order( 'DESC' );

        $rows = $query->fetchArray();
        if ( count( $rows ) == 0 ) {
            Catalogue::warmUp();
            $rows = $query->fetchArray();
        }

        $products = array();
        foreach ( $rows as $row ) {
            $products[] = self::product( $row );
        }

        return $products;
    }

    /**
     * One row as the page expects it.
     *
     * @param array $row
     * @return array
     */
    protected static function product( array $row )
    {
        $content = $row['content'] ? json_decode( $row['content'], true ) : array();
        $shared = isset ( $content['shared'] ) ? $content['shared'] : array();
        $copy = self::localized( isset ( $content['i18n'] ) ? $content['i18n'] : array() );

        return array(
            // Identity — the columns the rest of the plugin selects on.
            'slug' => $row['slug'],
            'plugin_id' => (int) $row['plugin_id'],
            'title' => $row['title'],
            // Added as a parameter rather than glued on: the catalogue decides this
            // address, and it may already carry a query of its own — pasting "?ref=..."
            // after an existing "?" makes a broken link out of a working one.
            'url' => Lib\Utils\Common::prepareUrlReferrers( add_query_arg( 'ref', 'ladela', $row['url'] ), 'shop' ),
            'demo_url' => $row['demo_url'],
            'published_at' => $row['published'],
            'bundle_plugins' => $row['bundle_plugins'] ? json_decode( $row['bundle_plugins'], true ) : array(),
            'visible' => (int) $row['visible'],
            // How prominently to show it: 0 an add-on, 1 a tier, 2 the tier that
            // carries the accent. One scale in the column that already marks the
            // Pro / Business / Ultimate line, rather than a separate flag for a point
            // on it.
            'highlighted' => (int) $row['highlighted'],
            'priority' => (int) $row['priority'],

            // The same for every language.
            // Both are columns rather than part of `content`: they already exist, they
            // already hold a URL, and the catalogue already fills them. A second place
            // to say the same thing is a second place for it to be wrong.
            'icon' => $row['icon'],
            'image' => $row['image'],
            'version' => isset ( $shared['version'] ) ? $shared['version'] : '',
            'updated_at' => isset ( $shared['updated_at'] ) ? $shared['updated_at'] : '',
            'requires' => isset ( $shared['requires'] ) ? $shared['requires'] : '',
            'prices' => isset ( $shared['prices'] ) ? $shared['prices'] : array( 'lifetime' => array(), 'subscription' => array() ),

            // Written by an editor, in one language.
            'short' => isset ( $copy['short'] ) ? $copy['short'] : '',
            'long' => isset ( $copy['long'] ) ? $copy['long'] : '',
            'badge' => isset ( $copy['badge'] ) ? $copy['badge'] : '',
            'tags' => isset ( $copy['tags'] ) ? $copy['tags'] : array(),
            'inherit' => isset ( $copy['inherit'] ) ? $copy['inherit'] : '',
            'footnote' => isset ( $copy['footnote'] ) ? $copy['footnote'] : '',
            'features' => isset ( $copy['features'] ) ? $copy['features'] : array(),
        );
    }

    /**
     * The reader's language, or English.
     *
     * Every language the catalogue has is stored, and the choice is made here — the
     * same way `Cloud\General::localize()` already handles the other things `/1.0/info`
     * carries. That is what makes it safe to decide per request: the locale is a
     * per-user setting, so two administrators of one site read different languages out
     * of the same row instead of overwriting each other's copy of it.
     *
     * English is what the catalogue guarantees, which answers both "this site is
     * English" and "nobody has written this in that language yet".
     *
     * @param array $i18n
     * @return array
     */
    protected static function localized( array $i18n )
    {
        $locale = Lib\Config::getLocale();

        // Three tries, because the catalogue may key its languages either way and a
        // German reader should get German under both. `de_DE` is what WordPress calls
        // the locale and what the rest of the Cloud payload is keyed by; `de` is what
        // you write when one translation serves every country that speaks it. English
        // answers the rest.
        foreach ( array( $locale, substr( $locale, 0, 2 ), 'en' ) as $key ) {
            if ( ! empty ( $i18n[ $key ] ) ) {
                return $i18n[ $key ];
            }
        }

        return array();
    }

    /**
     * Adds what only this site knows: what is installed, what is switched on, and
     * which purchase code covers it.
     *
     * @param array $products
     * @return array
     */
    protected static function withLocalState( array $products )
    {
        $active = array_keys( apply_filters( 'bookly_plugins', array() ) );

        $installed = $active;
        foreach ( glob( Lib\Plugin::getDirectory() . '/../bookly-addon-*', GLOB_ONLYDIR ) as $path ) {
            $installed[] = basename( $path );
        }

        foreach ( $products as &$product ) {
            $slug = $product['slug'];
            $product['installed'] = in_array( $slug, $installed );
            $product['active'] = in_array( $slug, $active );
            $product['license'] = get_option( str_replace( array( '-addon', '-' ), array( '', '_' ), $slug ) . '_purchase_code' );
            $product['owned'] = (bool) $product['license'];
        }
        unset ( $product );

        return self::resolveBundleOwnership( $products );
    }

    /**
     * A bundle and everything inside it share one purchase code.
     *
     * Without this the code shows up on all twenty members and the list of what you
     * own reads as twenty purchases instead of one. The members stay owned — they
     * just stop claiming the code as theirs.
     *
     * @param array $products
     * @return array
     */
    protected static function resolveBundleOwnership( array $products )
    {
        $owned_by_bundle = array();
        foreach ( $products as $product ) {
            if ( $product['bundle_plugins'] && $product['license'] ) {
                foreach ( $product['bundle_plugins'] as $plugin_id ) {
                    $owned_by_bundle[ $plugin_id ] = $product['license'];
                }
            }
        }

        foreach ( $products as &$product ) {
            if ( $product['bundle_plugins'] || ! isset ( $owned_by_bundle[ $product['plugin_id'] ] ) ) {
                continue;
            }
            $product['owned'] = true;
            $product['included_in'] = $owned_by_bundle[ $product['plugin_id'] ];
            if ( $product['license'] === $owned_by_bundle[ $product['plugin_id'] ] ) {
                $product['license'] = false;
            }
        }
        unset ( $product );

        return $products;
    }
}
