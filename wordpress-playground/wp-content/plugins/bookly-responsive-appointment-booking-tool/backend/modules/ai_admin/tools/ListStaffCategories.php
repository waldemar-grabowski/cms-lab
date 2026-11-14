<?php
namespace Bookly\Backend\Modules\AiAdmin\Tools;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\StaffData;

/**
 * list_staff_categories() — staff categories (Pro) in their order: id, name, info, position,
 * number of staff members. The shape set_staff_category takes.
 */
class ListStaffCategories extends Tool
{
    const NAME = 'list_staff_categories';

    public function getName()
    {
        return self::NAME;
    }

    public function available()
    {
        return Lib\Config::proActive();
    }

    protected function description()
    {
        return 'Staff categories in their order: id, name, info, position, number of staff members.';
    }

    protected function parameters()
    {
        return array( 'type' => 'object', 'properties' => array() );
    }

    public function execute( array $arguments )
    {
        if ( ! Access::allowed( array( 'admin' ) ) ) {
            return self::json( array( 'error' => 'forbidden' ) );
        }
        $counts = array();
        foreach ( Lib\Entities\Staff::query()->select( 'category_id, COUNT(*) AS staff' )->whereNot( 'category_id', null )->groupBy( 'category_id' )->fetchArray() as $row ) {
            $counts[ $row['category_id'] ] = (int) $row['staff'];
        }
        $list = array();
        foreach ( StaffData::categories() as $position => $category ) {
            $list[] = array(
                'id'       => $category['id'],
                'name'     => $category['name'],
                'info'     => $category['info'],
                'position' => $position,
                'staff'    => isset( $counts[ $category['id'] ] ) ? $counts[ $category['id'] ] : 0,
            );
        }

        return self::json( $list );
    }
}
