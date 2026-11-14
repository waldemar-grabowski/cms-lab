<?php
namespace Bookly\Backend\Modules\AiAdmin\Tools;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\StaffData;

/**
 * list_staff() — all staff members in the order of "Staff members order" (position), archived
 * included: id, full name, visibility, staff category name.
 */
class ListStaff extends Tool
{
    const NAME = 'list_staff';

    public function getName()
    {
        return self::NAME;
    }

    protected function description()
    {
        return 'All staff members in the booking form order, archived included: id, full name, visibility, category.';
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
        $categories = array();
        foreach ( StaffData::categories() as $category ) {
            $categories[ $category['id'] ] = $category['name'];
        }
        $list = array();
        foreach ( Lib\Entities\Staff::query()->select( 'id, full_name, visibility, category_id' )->sortBy( 'position, id' )->fetchArray() as $row ) {
            $list[] = array(
                'id'         => (int) $row['id'],
                'full_name'  => $row['full_name'],
                'visibility' => $row['visibility'],
                'category'   => $row['category_id'] !== null && isset( $categories[ $row['category_id'] ] ) ? $categories[ $row['category_id'] ] : null,
            );
        }

        return self::json( $list );
    }
}
