<?php
namespace Bookly\Backend\Modules\AiAdmin\Tools;

use Bookly\Lib;

/**
 * list_categories() — service categories in the order of the booking form: id, name, info,
 * position and the number of services; the shape set_category takes.
 */
class ListCategories extends Tool
{
    const NAME = 'list_categories';

    public function getName()
    {
        return self::NAME;
    }

    protected function description()
    {
        return 'Service categories in their order: id, name, info, position, number of services.';
    }

    protected function parameters()
    {
        return array( 'type' => 'object', 'properties' => array() );
    }

    public function execute( array $arguments )
    {
        $counts = array();
        foreach ( Lib\Entities\Service::query()->select( 'category_id, COUNT(*) AS cnt' )->whereNot( 'category_id', null )->groupBy( 'category_id' )->fetchArray() as $row ) {
            $counts[ $row['category_id'] ] = (int) $row['cnt'];
        }
        $result = array();
        foreach ( Lib\Entities\Category::query()->sortBy( 'position, id' )->fetchArray() as $position => $row ) {
            $result[] = array(
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'info' => (string) $row['info'],
                'position' => $position,
                'services' => isset( $counts[ $row['id'] ] ) ? $counts[ $row['id'] ] : 0,
            );
        }

        return self::json( $result );
    }
}
