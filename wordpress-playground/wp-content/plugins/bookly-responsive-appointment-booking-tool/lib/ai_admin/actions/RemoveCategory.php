<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Services\ServiceAction;
use Bookly\Lib\AiAdmin\State;

/**
 * remove_category — delete one service category; its services become Uncategorized
 * (assets/ai-setup/actions/remove_category.yaml).
 */
class RemoveCategory extends ServiceAction implements ActionInterface
{
    protected function name()
    {
        return 'remove_category';
    }

    /**
     * @inheritDoc
     */
    public function validate( array $args, State $state )
    {
        if ( $error = $this->forbidden() ) {
            return array( $error );
        }
        $categories = SetCategory::categories( $state );
        $id = isset( $args['category_id'] ) && is_int( $args['category_id'] ) ? $args['category_id'] : null;
        if ( $id === null || ! array_key_exists( $id, $categories ) ) {
            return array( 'category_not_found: ' . wp_json_encode( isset( $args['category_id'] ) ? $args['category_id'] : null ) );
        }
        unset( $categories[ $id ] );
        $state->set( 'list:categories', $categories );

        return array();
    }

    /**
     * @inheritDoc
     */
    public function preview( array $args, State $state )
    {
        $categories = SetCategory::categories( $state );
        $id = $args['category_id'];
        $category = $categories[ $id ];
        $titles = array();
        foreach ( Lib\Entities\Service::query()->select( 'id, title' )->where( 'category_id', $id )->sortBy( 'position, id' )->fetchArray() as $row ) {
            $titles[] = self::title( $state, (int) $row['id'] );
        }
        $rows = array(
            array(
                'label' => __( 'Category', 'bookly-responsive-appointment-booking-tool' ),
                'before' => $category['name'] . ( $category['info'] !== '' ? ' (' . $category['info'] . ')' : '' ),
                'after' => '—',
                'link' => add_query_arg( array( 'page' => 'bookly-services' ), admin_url( 'admin.php' ) ),
            ),
        );
        if ( $titles ) {
            $rows[] = array(
                'label' => __( 'Uncategorized', 'bookly-responsive-appointment-booking-tool' ),
                'before' => '—',
                'after' => implode( ', ', $titles ),
            );
        }
        unset( $categories[ $id ] );
        $state->set( 'list:categories', $categories );

        return array( 'rows' => $rows, 'warnings' => array() );
    }

    /**
     * @inheritDoc
     */
    public function apply( array $args )
    {
        $category = Lib\Entities\Category::find( $args['category_id'], false );
        $snapshot = array(
            'row' => Lib\Entities\Category::query()->where( 'id', $category->getId() )->fetchRow(),
            'services' => array_map( 'intval', Lib\Entities\Service::query()->where( 'category_id', $category->getId() )->fetchCol( 'id' ) ),
        );
        $category->delete();

        return $snapshot;
    }

    /**
     * @inheritDoc
     */
    public function undo( array $snapshot )
    {
        if ( Lib\Entities\Category::query()->where( 'id', $snapshot['row']['id'] )->count() ) {
            return;
        }
        self::insertRow( '\Bookly\Lib\Entities\Category', $snapshot['row'] );
        if ( $snapshot['services'] ) {
            // Services that got another category since keep it.
            Lib\Entities\Service::query()
                ->update()
                ->set( 'category_id', $snapshot['row']['id'] )
                ->whereIn( 'id', $snapshot['services'] )
                ->where( 'category_id', null )
                ->execute();
        }
    }
}
