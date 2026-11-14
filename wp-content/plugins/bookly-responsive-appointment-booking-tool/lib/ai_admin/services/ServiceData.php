<?php
namespace Bookly\Lib\AiAdmin\Services;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\AllowedValues;
use Bookly\Lib\AiAdmin\Rules;

/**
 * Services as the setup assistant reads and writes them: the shape of get_service / list_services
 * (assets/ai-setup/read_tools.yaml) is the shape the service actions take, so both sides are built
 * here from the database.
 */
abstract class ServiceData
{
    /** Week days in the shape of the read tools, keyed by day_index of the database (1 = Sunday). */
    const WEEK = 'sun,mon,tue,wed,thu,fri,sat';

    /**
     * Fields of set_service: types whose dialog shows the field (null = every type) and the add-on
     * its control needs.
     *
     * @return array field => array( 'types' => string[]|null, 'not' => string|null, 'addon' => string|null )
     */
    public static function fields()
    {
        $simple = array( 'simple' );

        return array(
            'title' => array( 'types' => null, 'addon' => null ),
            'category_id' => array( 'not' => 'package', 'addon' => null ),
            'color' => array( 'types' => $simple, 'addon' => null ),
            'visibility' => array( 'types' => null, 'addon' => 'pro' ),
            'info' => array( 'types' => null, 'addon' => null ),
            'price' => array( 'types' => null, 'addon' => null ),
            'duration' => array( 'types' => $simple, 'addon' => null ),
            'units_min' => array( 'types' => $simple, 'addon' => 'custom-duration' ),
            'units_max' => array( 'types' => $simple, 'addon' => 'custom-duration' ),
            'start_time_info' => array( 'types' => $simple, 'addon' => null ),
            'end_time_info' => array( 'types' => $simple, 'addon' => null ),
            'slot_length' => array( 'types' => array( 'simple', 'collaborative' ), 'addon' => null ),
            'padding_left' => array( 'types' => $simple, 'addon' => 'pro' ),
            'padding_right' => array( 'types' => $simple, 'addon' => 'pro' ),
            'time_requirements' => array( 'types' => $simple, 'addon' => 'tasks' ),
            'capacity_min' => array( 'types' => $simple, 'addon' => 'group-booking' ),
            'capacity_max' => array( 'types' => $simple, 'addon' => 'group-booking' ),
            'one_booking_per_slot' => array( 'types' => $simple, 'addon' => 'group-booking' ),
            'online_meetings' => array( 'types' => $simple, 'addon' => 'pro' ),
            'limit_period' => array( 'not' => 'package', 'addon' => 'pro' ),
            'appointments_limit' => array( 'not' => 'package', 'addon' => 'pro' ),
            'final_step_url' => array( 'not' => 'package', 'addon' => 'pro' ),
            'min_time_prior_booking' => array( 'not' => 'package', 'addon' => 'pro' ),
            'min_time_prior_cancel' => array( 'not' => 'package', 'addon' => 'pro' ),
            'staff_preference' => array( 'types' => $simple, 'addon' => 'pro' ),
            'staff_preference_settings' => array( 'types' => $simple, 'addon' => 'pro' ),
            'gateways' => array( 'types' => null, 'addon' => 'pro' ),
            'taxes' => array( 'not' => 'package', 'addon' => 'taxes' ),
            'package_size' => array( 'types' => array( 'package' ), 'addon' => 'packages' ),
            'package_unassigned' => array( 'types' => array( 'package' ), 'addon' => 'packages' ),
            'package_life_time_type' => array( 'types' => array( 'package' ), 'addon' => 'packages' ),
            'package_life_time' => array( 'types' => array( 'package' ), 'addon' => 'packages' ),
            'same_staff_for_subservices' => array( 'types' => array( 'compound' ), 'addon' => 'compound-services' ),
            'collaborative_equal_duration' => array( 'types' => array( 'collaborative' ), 'addon' => 'collaborative-services' ),
        );
    }

    /**
     * Whether the dialog shows the field for the service type.
     *
     * @param string $field
     * @param string $type
     * @return bool
     */
    public static function fieldForType( $field, $type )
    {
        $fields = self::fields();
        $meta = $fields[ $field ];
        if ( isset( $meta['not'] ) ) {
            return $type !== $meta['not'];
        }

        return $meta['types'] === null || in_array( $type, $meta['types'], true );
    }

    /**
     * Add-on of a service type (null for simple).
     *
     * @param string $type
     * @return string|null
     */
    public static function typeAddon( $type )
    {
        $addons = array( 'compound' => 'compound-services', 'collaborative' => 'collaborative-services', 'package' => 'packages' );

        return isset( $addons[ $type ] ) ? $addons[ $type ] : null;
    }

    /**
     * Service row of the database, or null.
     *
     * @param int $service_id
     * @return array|null
     */
    public static function row( $service_id )
    {
        if ( ! is_numeric( $service_id ) || (int) $service_id <= 0 ) {
            return null;
        }
        $row = Lib\Entities\Service::query()->where( 'id', (int) $service_id )->fetchRow();

        return $row ?: null;
    }

    /**
     * Fields of set_service from a service row, in the units of the actions.
     *
     * @param array $row
     * @return array
     */
    public static function shape( array $row )
    {
        $settings = json_decode( (string) $row['staff_preference_settings'], true );
        $gateways = $row['gateways'] === null ? 'default' : json_decode( $row['gateways'], true );
        $slot_length = $row['slot_length'];
        if ( is_numeric( $slot_length ) ) {
            $slot_length = (int) $slot_length;
        }

        return array(
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'type' => $row['type'],
            'category_id' => $row['category_id'] === null ? null : (int) $row['category_id'],
            'color' => (string) $row['color'],
            'visibility' => $row['visibility'],
            'info' => (string) $row['info'],
            'price' => (float) $row['price'],
            'duration' => (int) $row['duration'],
            'units_min' => (int) $row['units_min'],
            'units_max' => (int) $row['units_max'],
            'start_time_info' => (string) $row['start_time_info'],
            'end_time_info' => (string) $row['end_time_info'],
            'slot_length' => $slot_length,
            'padding_left' => (int) $row['padding_left'],
            'padding_right' => (int) $row['padding_right'],
            'time_requirements' => $row['time_requirements'],
            'capacity_min' => (int) $row['capacity_min'],
            'capacity_max' => (int) $row['capacity_max'],
            'one_booking_per_slot' => (bool) $row['one_booking_per_slot'],
            'online_meetings' => $row['online_meetings'] === null ? 'off' : $row['online_meetings'],
            'limit_period' => $row['limit_period'] === null ? 'off' : $row['limit_period'],
            'appointments_limit' => $row['appointments_limit'] === null ? null : (int) $row['appointments_limit'],
            'final_step_url' => (string) $row['final_step_url'],
            'min_time_prior_booking' => $row['min_time_prior_booking'] === null ? null : (int) $row['min_time_prior_booking'],
            'min_time_prior_cancel' => $row['min_time_prior_cancel'] === null ? null : (int) $row['min_time_prior_cancel'],
            'staff_preference' => $row['staff_preference'],
            'staff_preference_settings' => array(
                'period_before' => isset( $settings['period']['before'] ) ? (int) $settings['period']['before'] : 0,
                'period_after' => isset( $settings['period']['after'] ) ? (int) $settings['period']['after'] : 0,
                'random' => ! empty( $settings['random'] ),
            ),
            'gateways' => is_array( $gateways ) ? $gateways : 'default',
            'taxes' => Lib\Config::taxesActive() ? (array) Lib\Proxy\Taxes::getServiceTaxIds( $row['id'] ) : array(),
            'package_size' => (int) $row['package_size'],
            'package_unassigned' => (bool) $row['package_unassigned'],
            'package_life_time_type' => $row['package_life_time_type'],
            'package_life_time' => $row['package_life_time'] === null ? null : (int) $row['package_life_time'],
            'same_staff_for_subservices' => (bool) $row['same_staff_for_subservices'],
            'collaborative_equal_duration' => (bool) $row['collaborative_equal_duration'],
        );
    }

    /**
     * Fields of a new service as the New service dialog creates it.
     *
     * @param string $title
     * @param string $type
     * @return array
     */
    public static function newShape( $title, $type )
    {
        return array(
            'id' => null,
            'title' => $title,
            'type' => $type,
            'category_id' => null,
            'color' => '',
            'visibility' => Lib\Entities\Service::VISIBILITY_PUBLIC,
            'info' => '',
            'price' => 0.0,
            'duration' => Lib\Config::getTimeSlotLength(),
            'units_min' => 1,
            'units_max' => 1,
            'start_time_info' => '',
            'end_time_info' => '',
            'slot_length' => Lib\Entities\Service::SLOT_LENGTH_DEFAULT,
            'padding_left' => 0,
            'padding_right' => 0,
            'time_requirements' => Lib\Entities\Service::START_TIME_REQUIRED,
            'capacity_min' => 1,
            'capacity_max' => 1,
            'one_booking_per_slot' => false,
            'online_meetings' => 'off',
            'limit_period' => 'off',
            'appointments_limit' => null,
            'final_step_url' => '',
            'min_time_prior_booking' => null,
            'min_time_prior_cancel' => null,
            'staff_preference' => Lib\Entities\Service::PREFERRED_MOST_EXPENSIVE,
            'staff_preference_settings' => array( 'period_before' => 0, 'period_after' => 0, 'random' => false ),
            'gateways' => 'default',
            'taxes' => array(),
            'package_size' => 0,
            'package_unassigned' => false,
            'package_life_time_type' => 'first_booking',
            'package_life_time' => $type === Lib\Entities\Service::TYPE_PACKAGE ? 30 : null,
            'same_staff_for_subservices' => false,
            'collaborative_equal_duration' => false,
        );
    }

    /**
     * Providers of a service: non-archived staff members with a row of the service (any location).
     *
     * @param int  $service_id
     * @param bool $archived true — the archived ones instead
     * @return int[]
     */
    public static function staffIds( $service_id, $archived = false )
    {
        $linked = array_map( 'intval', Lib\Entities\StaffService::query()
            ->where( 'service_id', $service_id )
            ->fetchCol( 'DISTINCT staff_id' ) );
        $ids = array();
        foreach ( self::staff() as $id => $staff ) {
            if ( in_array( $id, $linked, true ) && ( $staff['visibility'] === 'archive' ) === $archived ) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Staff members: id => array( full_name, visibility ), in the order of the staff list.
     *
     * @return array
     */
    public static function staff()
    {
        $staff = array();
        foreach ( Lib\Entities\Staff::query()->select( 'id, full_name, visibility' )->sortBy( 'position, id' )->fetchArray() as $row ) {
            $staff[ (int) $row['id'] ] = $row;
        }

        return $staff;
    }

    /**
     * Order of Providers for "Specified order": saved positions first, others after them in the
     * order of the staff list.
     *
     * @param int   $service_id
     * @param int[] $staff_ids
     * @return int[]
     */
    public static function preferenceOrder( $service_id, array $staff_ids )
    {
        $positions = Lib\Config::proActive() ? (array) Lib\Proxy\Pro::getServiceStaffPreferenceOrders( $service_id ) : array();
        $ordered = array();
        foreach ( array_keys( $positions ) as $staff_id ) {
            if ( in_array( $staff_id, $staff_ids, true ) ) {
                $ordered[] = $staff_id;
            }
        }
        foreach ( $staff_ids as $staff_id ) {
            if ( ! in_array( $staff_id, $ordered, true ) ) {
                $ordered[] = $staff_id;
            }
        }

        return $ordered;
    }

    /**
     * Steps of a compound / collaborative service or the service of a package, in the shape of
     * set_service_sub_services.
     *
     * @param int $service_id
     * @return array
     */
    public static function steps( $service_id )
    {
        $steps = array();
        $rows = Lib\Entities\SubService::query()
            ->select( 'type, sub_service_id, duration' )
            ->where( 'service_id', $service_id )
            ->sortBy( 'position' )
            ->fetchArray();
        foreach ( $rows as $row ) {
            $steps[] = $row['type'] === Lib\Entities\SubService::TYPE_SPARE_TIME
                ? array( 'spare_time' => (int) $row['duration'] )
                : array( 'service_id' => (int) $row['sub_service_id'] );
        }

        return $steps;
    }

    /**
     * @param array $row service row
     * @return string[]
     */
    public static function tags( array $row )
    {
        $tags = $row['tags'] ? json_decode( $row['tags'], true ) : array();

        return is_array( $tags ) ? array_values( $tags ) : array();
    }

    /**
     * Weekly schedule of a service (service-schedule add-on) in the shape of set_service_schedule.
     * Days without a stored row are taken from the business hours, as the Schedule tab shows them;
     * nothing is written.
     *
     * @param int $service_id
     * @return array mon…sun => array( start, end, breaks ) | "off"
     */
    public static function schedule( $service_id )
    {
        $schedule = array();
        $rows = self::scheduleRows( $service_id );
        foreach ( self::weekOrder() as $day_index ) {
            $day = $rows[ $day_index ];
            $schedule[ self::dayName( $day_index ) ] = $day['start_time'] === null
                ? 'off'
                : array(
                    'start' => self::hhmm( $day['start_time'] ),
                    'end' => self::hhmm( $day['end_time'] ),
                    'breaks' => self::breaksHhmm( $day['breaks'] ),
                );
        }

        return $schedule;
    }

    /**
     * All 7 schedule rows of a service: stored ones, missing ones from the business hours.
     *
     * @param int $service_id
     * @return array day_index => array( start_time, end_time, breaks, stored )
     */
    public static function scheduleRows( $service_id )
    {
        $stored = is_numeric( $service_id ) ? (array) Lib\Proxy\ServiceSchedule::getServiceScheduleDays( $service_id ) : array();
        $week = explode( ',', 'sunday,monday,tuesday,wednesday,thursday,friday,saturday' );
        $rows = array();
        for ( $day_index = 1; $day_index <= 7; $day_index++ ) {
            if ( isset( $stored[ $day_index ] ) ) {
                $rows[ $day_index ] = $stored[ $day_index ] + array( 'stored' => true );
            } else {
                $day = $week[ $day_index - 1 ];
                $start = get_option( 'bookly_bh_' . $day . '_start' ) ?: null;
                $rows[ $day_index ] = array(
                    'start_time' => $start,
                    'end_time' => $start === null ? null : ( get_option( 'bookly_bh_' . $day . '_end' ) ?: null ),
                    'breaks' => array(),
                    'stored' => false,
                );
            }
        }

        return $rows;
    }

    /**
     * Special days of a service from today on, in the shape of set_service_special_day.
     *
     * @param int $service_id
     * @return array date => array( date, start, end, breaks ) | array( date, off ) (+ duplicates)
     */
    public static function specialDays( $service_id )
    {
        $today = current_time( 'Y-m-d' );
        $days = array();
        $rows = is_numeric( $service_id ) ? (array) Lib\Proxy\SpecialDays::getServiceSpecialDays( $service_id ) : array();
        foreach ( $rows as $row ) {
            if ( $row['date'] < $today ) {
                continue;
            }
            if ( isset( $days[ $row['date'] ] ) ) {
                $days[ $row['date'] ]['duplicates'] = true;
                continue;
            }
            $days[ $row['date'] ] = $row['start_time'] === null
                ? array( 'date' => $row['date'], 'off' => true )
                : array(
                    'date' => $row['date'],
                    'start' => self::hhmm( $row['start_time'] ),
                    'end' => self::hhmm( $row['end_time'] ),
                    'breaks' => self::breaksHhmm( $row['breaks'] ),
                );
        }

        return $days;
    }

    /**
     * Values the dialog offers on this site for list fields of a service (get_service.allowed).
     *
     * @param array $service shape()
     * @return array
     */
    public static function allowed( array $service )
    {
        $entries = Rules::entries();
        $step = (int) get_option( 'bookly_gen_time_slot_length', 15 );
        $allowed = array(
            'duration' => self::allowedDurations( $service['duration'] ),
            'slot_length' => AllowedValues::of( $entries['field:service.slot_length'], $service['slot_length'] ),
            'padding' => array( 'min' => 0, 'max' => DAY_IN_SECONDS, 'step' => $step * MINUTE_IN_SECONDS ),
            'min_time_prior' => self::allowedMinTimePrior(),
            'spare_time' => array( 'min' => $step * MINUTE_IN_SECONDS, 'max' => 720 * MINUTE_IN_SECONDS, 'step' => $step * MINUTE_IN_SECONDS, 'also' => self::days() ),
            'gateways' => array_keys( Lib\Utils\Common::getGateways() ),
            'time_step_minutes' => $step,
        );
        if ( Lib\Config::proActive() ) {
            $tags = Lib\Proxy\Pro::getTagsData( 'service' );
            $allowed['tag_catalog'] = array();
            foreach ( $tags['list'] as $tag ) {
                $allowed['tag_catalog'][] = array( 'tag' => $tag['tag'], 'color_id' => (int) $tag['color_id'] );
            }
            $allowed['tag_colors'] = array_values( $tags['colors'] );
        }

        return $allowed;
    }

    /**
     * Durations of the Duration select (seconds), with the stored value.
     *
     * @param int|null $current
     * @return int[]
     */
    public static function allowedDurations( $current = null )
    {
        $entries = Rules::entries();
        $list = (array) AllowedValues::of( $entries['field:service.duration'], $current );
        if ( $current && ! in_array( (int) $current, $list ) ) {
            $list[] = (int) $current;
            sort( $list );
        }

        return array_map( 'intval', $list );
    }

    /**
     * Values of the minimum time prior selects: null = Default, 0 = Disabled, then seconds.
     *
     * @return array
     */
    public static function allowedMinTimePrior()
    {
        $entries = Rules::entries();
        $list = array();
        foreach ( (array) AllowedValues::of( $entries['field:service.min_time_prior_booking'] ) as $value ) {
            if ( $value !== null && $value !== 0 && $value !== '0' ) {
                $list[] = (int) $value;
            }
        }

        return array_merge( array( null, 0 ), $list );
    }

    /**
     * Whether a padding value is in the Padding select: OFF, the stored value, or a multiple of the
     * time slot length up to one day.
     *
     * @param mixed $value
     * @param mixed $current
     * @return bool
     */
    public static function paddingAllowed( $value, $current )
    {
        if ( ! is_numeric( $value ) || (int) $value != $value ) {
            return false;
        }
        $step = (int) get_option( 'bookly_gen_time_slot_length', 15 ) * MINUTE_IN_SECONDS;

        return (int) $value === 0 || (int) $value === (int) $current
            || ( $value > 0 && $value <= DAY_IN_SECONDS && $value % $step === 0 );
    }

    /**
     * Whether a spare time is in the Duration select of a compound step.
     *
     * @param mixed      $value
     * @param int[]|null $stored stored spare times of the service
     * @return bool
     */
    public static function spareTimeAllowed( $value, array $stored = array() )
    {
        if ( ! is_numeric( $value ) || (int) $value != $value || $value <= 0 ) {
            return false;
        }
        $value = (int) $value;
        $step = (int) get_option( 'bookly_gen_time_slot_length', 15 ) * MINUTE_IN_SECONDS;

        return in_array( $value, $stored, true ) || in_array( $value, self::days(), true )
            || ( $value <= 720 * MINUTE_IN_SECONDS && $value % $step === 0 );
    }

    /**
     * Ids of services the Order dialog lists: simple ones and those of active add-on types.
     *
     * @return int[]
     */
    public static function orderableIds()
    {
        $types = array( Lib\Entities\Service::TYPE_SIMPLE );
        foreach ( array( 'compound', 'collaborative', 'package' ) as $type ) {
            if ( Access::addonActive( self::typeAddon( $type ) ) ) {
                $types[] = $type;
            }
        }

        return array_map( 'intval', Lib\Entities\Service::query()->whereIn( 'type', $types )->sortBy( 'position, id' )->fetchCol( 'id' ) );
    }

    /**
     * Whether the free plan forbids one more service.
     *
     * @param int $created services created earlier in the same proposal
     * @return bool
     */
    public static function freeLimitReached( $created = 0 )
    {
        return ! Lib\Config::proActive()
            && get_option( 'bookly_updated_from_legacy_version' ) != 'lite'
            && Lib\Entities\Service::query()->count() + $created > 4;
    }

    /**
     * Whether a service can be deleted on undo: no appointments, no sold packages and not a step
     * of another service.
     *
     * @param int $service_id
     * @return bool
     */
    public static function deletable( $service_id )
    {
        return Lib\Entities\Appointment::query()->where( 'service_id', $service_id )->count() == 0
            && Lib\Entities\SubService::query()->where( 'sub_service_id', $service_id )->count() == 0
            && ! ( Lib\Config::packagesActive() && Lib\Proxy\Packages::getServicePackagesCount( $service_id ) );
    }

    /* ------------------------------------------------------------------ time helpers */

    /**
     * Day indexes in the site's week order (start of week from WordPress settings).
     *
     * @return int[]
     */
    public static function weekOrder()
    {
        $start = (int) get_option( 'start_of_week', 0 );
        $order = array();
        for ( $i = 0; $i < 7; $i++ ) {
            $order[] = ( ( $start + $i ) % 7 ) + 1;
        }

        return $order;
    }

    /**
     * @param int $day_index 1 = Sunday
     * @return string
     */
    public static function dayName( $day_index )
    {
        $week = explode( ',', self::WEEK );

        return $week[ $day_index - 1 ];
    }

    /**
     * @param string $name mon…sun
     * @return int|null
     */
    public static function dayIndex( $name )
    {
        $index = array_search( $name, explode( ',', self::WEEK ), true );

        return $index === false ? null : $index + 1;
    }

    /**
     * "HH:MM:SS" → "HH:MM".
     *
     * @param string|null $time
     * @return string|null
     */
    public static function hhmm( $time )
    {
        return $time === null ? null : substr( $time, 0, 5 );
    }

    /**
     * "HH:MM" → "HH:MM:SS".
     *
     * @param string $time
     * @return string
     */
    public static function hhmmss( $time )
    {
        return strlen( $time ) === 5 ? $time . ':00' : $time;
    }

    /**
     * @param array $breaks array( array( start_time, end_time ) )
     * @return array array( array( start, end ) )
     */
    public static function breaksHhmm( array $breaks )
    {
        $result = array();
        foreach ( $breaks as $break ) {
            $result[] = array( 'start' => self::hhmm( $break['start_time'] ), 'end' => self::hhmm( $break['end_time'] ) );
        }

        return $result;
    }

    /**
     * A day of a schedule as text: "09:00–18:00 (breaks 13:00–14:00)" or "OFF".
     *
     * @param array|string $day array( start, end, breaks ) | "off"
     * @return string
     */
    public static function dayText( $day )
    {
        if ( ! is_array( $day ) ) {
            return __( 'OFF', 'bookly-responsive-appointment-booking-tool' );
        }
        $text = self::timeText( $day['start'] ) . '–' . self::timeText( $day['end'] );
        $breaks = array();
        foreach ( isset( $day['breaks'] ) ? $day['breaks'] : array() as $break ) {
            $breaks[] = self::timeText( $break['start'] ) . '–' . self::timeText( $break['end'] );
        }
        if ( $breaks ) {
            $text .= ' (' . __( 'Breaks', 'bookly-responsive-appointment-booking-tool' ) . ': ' . implode( ', ', $breaks ) . ')';
        }

        return $text;
    }

    /**
     * @param string $hhmm
     * @return string
     */
    public static function timeText( $hhmm )
    {
        $seconds = AllowedValues::seconds( $hhmm );

        return $seconds === null ? (string) $hhmm : Lib\Utils\DateTime::formatTime( $seconds );
    }

    /**
     * 1-7 days in seconds.
     *
     * @return int[]
     */
    protected static function days()
    {
        $days = array();
        for ( $day = 1; $day <= 7; $day++ ) {
            $days[] = $day * DAY_IN_SECONDS;
        }

        return $days;
    }
}
