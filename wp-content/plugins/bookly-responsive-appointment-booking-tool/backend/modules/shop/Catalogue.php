<?php
namespace Bookly\Backend\Modules\Shop;

use Bookly\Lib;

/**
 * The products catalogue as served by Cloud.
 *
 * One source. Hub still serves a catalogue of its own under the same `plugins` key,
 * but for installations too old to know about Cloud — this one does not read it.
 * Cloud answers without an account (verified on an installation with no token), so
 * there is nobody a second source would help; what it would do is disagree, since Hub
 * carries the marketplace listing and Cloud the clean name and our own artwork, and a
 * product would change its name once a day.
 */
abstract class Catalogue
{
    /**
     * Fill an empty catalogue, now, before something that needs it goes on.
     *
     * Two things cannot wait for the daily routine: the showcase, which would otherwise
     * be an empty page, and applying a purchase code, which reads the bundle's contents
     * out of these rows to know what the code covers.
     *
     * @return void
     */
    public static function warmUp()
    {
        if ( Lib\Entities\Shop::query()->count() ) {
            return;
        }

        Lib\Cloud\API::getInstance()->general->loadInfo();
    }

    /**
     * Store the catalogue.
     *
     * A product missing from the payload is left alone. The catalogue never drops a
     * product it has stopped selling — it sends it with `visible = 0` — so a gap here
     * means a payload that was cut short, and deleting on the strength of that would
     * lose the purchase codes attached to those rows.
     *
     * @param array $products
     * @return int Rows written.
     */
    public static function save( array $products )
    {
        if ( ! $products ) {
            return 0;
        }

        // On an installation seeing the catalogue for the first time nothing in it is
        // news, so it all counts as seen. After that a product it has not seen before
        // is what the menu counter is counting.
        $seen = Lib\Entities\Shop::query()->count() ? 0 : 1;
        $written = 0;

        foreach ( $products as $product ) {
            if ( empty ( $product['plugin_id'] ) || empty ( $product['slug'] ) ) {
                continue;
            }

            $entry = new Lib\Entities\Shop();
            $entry->loadBy( array( 'plugin_id' => $product['plugin_id'] ) );
            $entry
                ->setPluginId( $product['plugin_id'] )
                ->setSlug( $product['slug'] )
                ->setTitle( self::value( $product, 'title' ) )
                ->setVisible( (int) self::value( $product, 'visible', 1 ) )
                ->setHighlighted( (int) self::value( $product, 'highlighted', 0 ) )
                ->setPriority( (int) self::value( $product, 'priority', 0 ) )
                ->setUrl( self::value( $product, 'url' ) )
                ->setDemoUrl( self::value( $product, 'demo_url' ) )
                ->setIcon( self::value( $product, 'icon' ) )
                ->setImage( self::value( $product, 'image' ) )
                ->setPublished( self::value( $product, 'published_at' ) ?: current_time( 'mysql' ) )
                ->setBundlePlugins( empty ( $product['bundle_plugins'] ) ? null : json_encode( $product['bundle_plugins'] ) )
                ->setContent( empty ( $product['content'] ) ? null : json_encode( $product['content'] ) )
                ->setSeen( $entry->isLoaded() ? $entry->getSeen() : $seen );

            if ( ! $entry->isLoaded() ) {
                $entry->setCreatedAt( current_time( 'mysql' ) );
                // Columns the catalogue no longer fills, and that the table still
                // insists on: they are NOT NULL with no default, left over from when
                // Hub fed this table its marketplace listing. Nothing reads them, but
                // an INSERT without them is refused, so a product appearing in the
                // catalogue for the first time would never be stored.
                $entry
                    ->setDescription( '' )
                    ->setLifeTimePrice( 0 )
                    ->setSales( 0 )
                    ->setRating( 0 )
                    ->setReviews( 0 );
            }

            // Counted after the fact: a refused write would otherwise be reported as
            // a stored product, which is how this went unnoticed to begin with.
            if ( $entry->save() !== false ) {
                $written ++;
            }
        }

        return $written;
    }

    /**
     * @param array  $product
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    private static function value( $product, $key, $default = '' )
    {
        return isset ( $product[ $key ] ) ? $product[ $key ] : $default;
    }
}
