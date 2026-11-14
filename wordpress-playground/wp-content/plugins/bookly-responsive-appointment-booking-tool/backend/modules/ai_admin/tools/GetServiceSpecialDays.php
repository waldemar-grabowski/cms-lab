<?php
namespace Bookly\Backend\Modules\AiAdmin\Tools;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Services\ServiceData;

/**
 * get_service_special_days({service_id}) — special days of one service from today on in the shape
 * set_service_special_day takes ({date, start, end, breaks} or {date, off: true}); a date with
 * several rows is marked duplicates: true.
 */
class GetServiceSpecialDays extends Tool
{
    const NAME = 'get_service_special_days';

    public function getName()
    {
        return self::NAME;
    }

    public function available()
    {
        return Lib\Config::specialDaysActive();
    }

    protected function description()
    {
        return 'Special days of one service from today on, in the shape set_service_special_day takes.';
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
        $result = array(
            'special_days' => array_values( ServiceData::specialDays( (int) $row['id'] ) ),
            'time_step_minutes' => (int) get_option( 'bookly_gen_time_slot_length', 15 ),
        );
        if ( ! Lib\Config::serviceScheduleActive() ) {
            $result['unavailable'] = 'service-schedule';
        }

        return self::json( $result );
    }
}
