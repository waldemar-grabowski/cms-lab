<?php
namespace Bookly\Backend\Modules\AiAdmin\Tools;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Services\ServiceData;

/**
 * list_services() — all services in the order of the booking form: id, title, type, category,
 * visibility, price, duration in seconds, tags. Services of a type whose add-on is inactive come
 * last with unavailable: <add-on> (the Order dialog does not list them).
 */
class ListServices extends Tool
{
    const NAME = 'list_services';

    public function getName()
    {
        return self::NAME;
    }

    protected function description()
    {
        return 'All services in the order of the booking form: id, title, type, category_id, visibility, price, duration (seconds), tags.';
    }

    protected function parameters()
    {
        return array( 'type' => 'object', 'properties' => array() );
    }

    public function execute( array $arguments )
    {
        $orderable = ServiceData::orderableIds();
        $rows = Lib\Entities\Service::query()->sortBy( 'position, id' )->indexBy( 'id' )->fetchArray();
        $result = array();
        foreach ( array_merge( $orderable, array_diff( array_map( 'intval', array_keys( $rows ) ), $orderable ) ) as $id ) {
            $row = $rows[ $id ];
            $item = array(
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'type' => $row['type'],
                'category_id' => $row['category_id'] === null ? null : (int) $row['category_id'],
                'visibility' => $row['visibility'],
                'price' => (float) $row['price'],
                'duration' => (int) $row['duration'],
                'tags' => ServiceData::tags( $row ),
            );
            if ( ! in_array( (int) $id, $orderable, true ) ) {
                $item['unavailable'] = ServiceData::typeAddon( $row['type'] );
            }
            $result[] = $item;
        }

        return self::json( $result );
    }
}
