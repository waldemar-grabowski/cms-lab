<?php
namespace Bookly\Backend\Modules\AiAdmin\Tools;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\Services\ServiceData;

/**
 * get_service({service_id}) — one service in the shape the service actions take: the fields of
 * set_service, Providers, the "Specified order", steps, tags, the weekly schedule (service-schedule)
 * and the values the dialog offers on this site.
 */
class GetService extends Tool
{
    const NAME = 'get_service';

    public function getName()
    {
        return self::NAME;
    }

    protected function description()
    {
        return 'One service in the shape the service actions take, with the values allowed on this site.';
    }

    protected function parameters()
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'service_id' => array( 'type' => 'integer', 'description' => 'Id from list_services.' ),
            ),
            'required' => array( 'service_id' ),
        );
    }

    public function execute( array $arguments )
    {
        $row = isset( $arguments['service_id'] ) ? ServiceData::row( $arguments['service_id'] ) : null;
        if ( ! $row ) {
            return self::json( array( 'error' => 'service not found' ) );
        }
        $service = ServiceData::shape( $row );
        $id = $service['id'];
        $type = $service['type'];
        $staff_ids = ServiceData::staffIds( $id );
        $result = array(
            'service' => $service,
            'staff_ids' => $staff_ids,
            'staff_preference_order' => ServiceData::preferenceOrder( $id, $staff_ids ),
            'steps' => ServiceData::steps( $id ),
            'tags' => ServiceData::tags( $row ),
        );
        $archived = ServiceData::staffIds( $id, true );
        if ( $archived ) {
            $result['archived_staff_ids'] = $archived;
        }
        if ( Lib\Config::serviceScheduleActive() && $type !== Lib\Entities\Service::TYPE_PACKAGE ) {
            $result['schedule'] = ServiceData::schedule( $id );
        }
        $result['allowed'] = ServiceData::allowed( $service );

        $not_for_type = array();
        $unavailable = array();
        foreach ( ServiceData::fields() as $field => $meta ) {
            if ( ! ServiceData::fieldForType( $field, $type ) ) {
                $not_for_type[] = $field;
            } elseif ( $meta['addon'] && ! Access::addonActive( $meta['addon'] ) ) {
                $unavailable[ $field ] = $meta['addon'];
            }
        }
        if ( ! in_array( $type, array( 'simple', 'package' ), true ) ) {
            $not_for_type[] = 'staff_ids';
        }
        if ( ! in_array( $type, array( 'compound', 'collaborative', 'package' ), true ) ) {
            $not_for_type[] = 'steps';
        }
        if ( ! Lib\Config::proActive() ) {
            $unavailable['tags'] = 'pro';
            $unavailable['staff_preference_order'] = 'pro';
        }
        if ( $not_for_type ) {
            $result['not_for_type'] = $not_for_type;
        }
        if ( $unavailable ) {
            $result['unavailable'] = $unavailable;
        }

        return self::json( $result );
    }
}
