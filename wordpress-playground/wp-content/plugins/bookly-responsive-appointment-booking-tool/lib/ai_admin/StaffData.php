<?php
namespace Bookly\Lib\AiAdmin;

use Bookly\Lib;

/**
 * Staff data of the setup assistant in the shape the staff actions take and get_staff returns
 * (assets/ai-setup/read_tools.yaml → get_staff): times "HH:MM", a weekday is
 * array( 'start', 'end', 'breaks' => array( array( 'start', 'end' ) ) ) or "off".
 *
 * Every reader takes the State of the proposal being checked (or null outside of one): values an
 * earlier item of the same proposal set win over the database, and a staff member created by an
 * earlier item ("$N") reads as New staff member would be saved.
 */
abstract class StaffData
{
    /**
     * Weekday keys by day_index (1 = Sunday, as bookly_staff_schedule_items stores it).
     *
     * @return array
     */
    public static function days()
    {
        return array( 1 => 'sun', 2 => 'mon', 3 => 'tue', 4 => 'wed', 5 => 'thu', 6 => 'fri', 7 => 'sat' );
    }

    /**
     * Weekday keys in the order of the site's start_of_week.
     *
     * @return string[]
     */
    public static function week()
    {
        $days  = array_values( self::days() );
        $start = (int) get_option( 'start_of_week' );

        return array_merge( array_slice( $days, $start ), array_slice( $days, 0, $start ) );
    }

    /**
     * Translated weekday name of a key.
     *
     * @param string $key
     * @return string
     */
    public static function dayName( $key )
    {
        global $wp_locale;
        $index = array_search( $key, array_values( self::days() ), true );

        return $wp_locale ? $wp_locale->get_weekday( $index ) : $key;
    }

    /**
     * Warning when other staff members already have this name (case ignored), or null.
     *
     * @param string   $full_name
     * @param int|null $except staff member the name is for
     * @return string|null
     */
    public static function sameNameWarning( $full_name, $except = null )
    {
        $lower = function ( $text ) {
            return function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $text ) ) : strtolower( trim( $text ) );
        };
        $same  = array();
        foreach ( Lib\Entities\Staff::query()->select( 'id, full_name' )->fetchArray() as $staff ) {
            if ( (int) $staff['id'] !== (int) $except && $lower( $staff['full_name'] ) === $lower( $full_name ) ) {
                $same[] = '#' . $staff['id'];
            }
        }

        return $same
            ? sprintf( __( 'A staff member with the same name already exists (%s).', 'bookly-responsive-appointment-booking-tool' ), implode( ', ', $same ) )
            : null;
    }

    /**
     * Translated short weekday name of a key ("Mon").
     *
     * @param string $key
     * @return string
     */
    public static function dayAbbrev( $key )
    {
        global $wp_locale;

        return $wp_locale ? $wp_locale->get_weekday_abbrev( self::dayName( $key ) ) : ucfirst( $key );
    }

    /**
     * "09:00:00" → "09:00"; empty → null.
     *
     * @param string|null $time
     * @return string|null
     */
    public static function hhmm( $time )
    {
        if ( $time === null || $time === '' ) {
            return null;
        }

        return Lib\Utils\DateTime::buildTimeString( Lib\Utils\DateTime::timeToSeconds( $time ), false );
    }

    /**
     * "09:00" → "09:00:00" as the schedule tables store it.
     *
     * @param string|null $time
     * @return string|null
     */
    public static function dbTime( $time )
    {
        if ( $time === null || $time === '' ) {
            return null;
        }

        return Lib\Utils\DateTime::buildTimeString( Lib\Utils\DateTime::timeToSeconds( $time ) );
    }

    /**
     * Seconds from midnight of "HH:MM[:SS]", or null.
     *
     * @param mixed $time
     * @return int|null
     */
    public static function seconds( $time )
    {
        return AllowedValues::seconds( $time );
    }

    /**
     * Time grid step in minutes (option bookly_gen_time_slot_length).
     *
     * @return int
     */
    public static function step()
    {
        return (int) ( Lib\Config::getTimeSlotLength() / MINUTE_IN_SECONDS );
    }

    /**
     * Whether a staff member exists — in the database or created by an earlier item ("$N").
     *
     * @param mixed      $staff_id
     * @param State|null $state
     * @return bool
     */
    public static function exists( $staff_id, $state = null )
    {
        if ( $state && $state->isNew( $staff_id ) === 'staff' ) {
            return true;
        }
        if ( ! is_numeric( $staff_id ) || (int) $staff_id <= 0 ) {
            return false;
        }

        return Lib\Entities\Staff::query()->where( 'id', (int) $staff_id )->count() > 0;
    }

    /**
     * Whether $staff_id is "$N" of a staff member created earlier in the proposal.
     *
     * @param mixed      $staff_id
     * @param State|null $state
     * @return bool
     */
    public static function isNew( $staff_id, $state )
    {
        return $state && $state->isNew( $staff_id ) === 'staff';
    }

    /**
     * Profile fields in the shape of get_staff.staff.
     *
     * @param mixed      $staff_id
     * @param State|null $state
     * @return array
     */
    public static function profile( $staff_id, $state = null )
    {
        $fields = array( 'full_name', 'email', 'phone', 'info', 'color', 'visibility', 'category_id', 'gateways', 'time_zone', 'working_time_limit' );
        if ( self::isNew( $staff_id, $state ) ) {
            $row = array(
                'full_name' => '', 'email' => '', 'phone' => '', 'info' => '', 'color' => null, 'visibility' => 'public',
                'category_id' => null, 'gateways' => 'default', 'time_zone' => null, 'working_time_limit' => null,
            );
        } else {
            $staff = Lib\Entities\Staff::find( (int) $staff_id, false );
            $row   = array();
            if ( $staff ) {
                $db = $staff->getFields();
                foreach ( $fields as $field ) {
                    $row[ $field ] = $db[ $field ];
                }
                $row['category_id']        = $db['category_id'] === null ? null : (int) $db['category_id'];
                $row['working_time_limit'] = $db['working_time_limit'] === null ? null : (int) $db['working_time_limit'];
                $row['time_zone']          = $db['time_zone'] === '' ? null : $db['time_zone'];
                $row['gateways']           = self::gatewaysValue( $db['gateways'] );
                $row['info']               = (string) $db['info'];
                $row['phone']              = (string) $db['phone'];
            }
        }
        if ( $state ) {
            foreach ( $fields as $field ) {
                $ref = self::ref( $field, $staff_id );
                if ( $state->has( $ref ) ) {
                    $row[ $field ] = $state->get( $ref );
                }
            }
        }

        return $row;
    }

    /**
     * State ref of a staff field.
     *
     * @param string $field
     * @param mixed  $staff_id
     * @return string
     */
    public static function ref( $field, $staff_id )
    {
        return 'field:staff.' . $field . '#' . $staff_id;
    }

    /**
     * Name of a staff member for the card.
     *
     * @param mixed      $staff_id
     * @param State|null $state
     * @return string
     */
    public static function name( $staff_id, $state = null )
    {
        $profile = self::profile( $staff_id, $state );

        return isset( $profile['full_name'] ) ? (string) $profile['full_name'] : '#' . $staff_id;
    }

    /**
     * NULL → "default", JSON list → list of ids.
     *
     * @param string|null $gateways
     * @return string|array
     */
    public static function gatewaysValue( $gateways )
    {
        if ( $gateways === null || $gateways === '' ) {
            return 'default';
        }
        $list = json_decode( $gateways, true );

        return is_array( $list ) ? array_values( $list ) : 'default';
    }

    /**
     * Values of the Timezone select of the Advanced tab (wp_timezone_choice()): city ids, UTC and
     * "UTC±N" offsets.
     *
     * @return string[]
     */
    public static function timeZones()
    {
        $values = array();
        foreach ( Lib\Utils\DateTime::getTimeZoneOptions() as $group ) {
            $values = array_merge( $values, array_map( 'strval', array_keys( $group ) ) );
        }

        return $values;
    }

    /**
     * Ids of all staff members in the order of "Staff members order" (position), archived
     * included, with "$N" of staff members created earlier in the proposal at the end.
     *
     * @param State|null $state
     * @return array
     */
    public static function order( $state = null )
    {
        if ( $state && $state->has( 'staff:order' ) ) {
            return $state->get( 'staff:order' );
        }

        return array_map( 'intval', Lib\Entities\Staff::query()->sortBy( 'position, id' )->fetchCol( 'id' ) );
    }

    /**
     * Staff categories (Pro) in their order: array( 'id', 'name', 'info' ), id "$N" for a category
     * created earlier in the proposal.
     *
     * @param State|null $state
     * @return array
     */
    public static function categories( $state = null )
    {
        if ( $state && $state->has( 'staff_category:list' ) ) {
            return $state->get( 'staff_category:list' );
        }
        $list = array();
        if ( Lib\Config::proActive() ) {
            $rows = \Bookly\Backend\Modules\Staff\Proxy\Pro::getCategoriesList();
            foreach ( is_array( $rows ) ? $rows : array() as $row ) {
                $list[] = array( 'id' => (int) $row['id'], 'name' => (string) $row['name'], 'info' => (string) $row['info'] );
            }
        }

        return $list;
    }

    /**
     * Name of a staff category, or "Uncategorized" for null.
     *
     * @param mixed      $category_id
     * @param State|null $state
     * @return string
     */
    public static function categoryName( $category_id, $state = null )
    {
        if ( $category_id === null || $category_id === '' ) {
            return Knowledge::label( 'Uncategorized' );
        }
        foreach ( self::categories( $state ) as $category ) {
            if ( (string) $category['id'] === (string) $category_id ) {
                return $category['name'];
            }
        }

        return '#' . $category_id;
    }

    /**
     * "$N" reference of the object the item being checked creates.
     *
     * @param State $state
     * @return string
     */
    public static function newRef( State $state )
    {
        return '$' . $state->item();
    }

    /**
     * Card warnings of an action itself (Rules::actions()[name]['warnings']).
     *
     * @param string $action
     * @param State  $state
     * @return string[]
     */
    public static function actionWarnings( $action, State $state )
    {
        $actions = Rules::actions();

        return isset( $actions[ $action ] ) ? Format::warnings( $actions[ $action ], null, null, $state ) : array();
    }

    /**
     * Card label "<staff name> › <field label>".
     *
     * @param mixed  $staff_id
     * @param State  $state
     * @param string $ref      Rules::entries() key
     * @param string $fallback
     * @return string
     */
    public static function label( $staff_id, State $state, $ref, $fallback )
    {
        return self::rowLabel( $staff_id, $state, Format::label( Access::rule( $ref ), $fallback ) );
    }

    /**
     * Card label "<staff name> › <field>" (see Format::rowLabel()).
     *
     * @param mixed  $staff_id
     * @param State  $state
     * @param string $field
     * @param array  $options see Format::rowLabel()
     * @param bool   $new     the current item creates the staff member
     * @return string
     */
    public static function rowLabel( $staff_id, State $state, $field, array $options = array(), $new = false )
    {
        return Format::rowLabel( Format::object(
            'staff:' . $staff_id,
            self::name( $staff_id, $state ),
            __( 'New staff member', 'bookly-responsive-appointment-booking-tool' ),
            $new || $state->isNew( $staff_id ) === 'staff'
        ), $field, $options );
    }

    /**
     * Error "forbidden: …" when the current user may not change the entry, or null.
     *
     * @param string $ref
     * @return string|null
     */
    public static function forbidden( $ref )
    {
        $rule = Access::rule( $ref );
        if ( ! $rule || empty( $rule['edit'] ) || ! Access::allowed( $rule['edit'] ) ) {
            return 'forbidden: ' . substr( $ref, 6 ) . ' cannot be changed by the assistant';
        }

        return null;
    }

    /* ------------------------------------------------------------------ schedule */

    /**
     * Rows of the staff member's weekly schedule (location_id NULL) by day_index, as stored:
     * array( 'id', 'start', 'end', 'breaks' => array( array( 'id', 'start', 'end' ) ) ). A missing
     * row has id null and the company's hours, as Staff::getScheduleItems() would create it.
     *
     * @param int $staff_id
     * @return array
     */
    public static function scheduleRows( $staff_id )
    {
        $rows = array();
        foreach ( Lib\Entities\StaffScheduleItem::query()->where( 'staff_id', $staff_id )->where( 'location_id', null )->fetchArray() as $item ) {
            if ( isset( $rows[ $item['day_index'] ] ) ) {
                continue;
            }
            $rows[ $item['day_index'] ] = array( 'id' => (int) $item['id'], 'start' => $item['start_time'], 'end' => $item['end_time'], 'breaks' => array() );
        }
        $ids = array();
        foreach ( $rows as $day_index => $row ) {
            $ids[ $row['id'] ] = $day_index;
        }
        if ( $ids ) {
            $breaks = Lib\Entities\ScheduleItemBreak::query()
                ->whereIn( 'staff_schedule_item_id', array_keys( $ids ) )
                ->sortBy( 'start_time, end_time' )
                ->fetchArray();
            foreach ( $breaks as $break ) {
                $rows[ $ids[ $break['staff_schedule_item_id'] ] ]['breaks'][] = array(
                    'id' => (int) $break['id'], 'start' => $break['start_time'], 'end' => $break['end_time'],
                );
            }
        }
        $bh = Lib\Config::getBusinessHours();
        foreach ( self::days() as $day_index => $key ) {
            if ( ! isset( $rows[ $day_index ] ) ) {
                $rows[ $day_index ] = array( 'id' => null, 'start' => $bh[ $day_index - 1 ]['start'], 'end' => $bh[ $day_index - 1 ]['end'], 'breaks' => array() );
            }
        }
        ksort( $rows );

        return $rows;
    }

    /**
     * Weekly schedule in the shape of get_staff.schedule (keys sun…sat).
     *
     * @param mixed      $staff_id
     * @param State|null $state
     * @return array
     */
    public static function schedule( $staff_id, $state = null )
    {
        if ( $state && $state->has( 'staff:schedule#' . $staff_id ) ) {
            return $state->get( 'staff:schedule#' . $staff_id );
        }
        $schedule = array();
        if ( self::isNew( $staff_id, $state ) ) {
            // New staff member: the company's hours, as Staff::save() copies them.
            $names = array( 1 => 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday' );
            foreach ( self::days() as $day_index => $key ) {
                $start = $state->get( 'option:bookly_bh_' . $names[ $day_index ] . '_start' );
                $end   = $state->get( 'option:bookly_bh_' . $names[ $day_index ] . '_end' );
                $schedule[ $key ] = $start ? array( 'start' => self::hhmm( $start ), 'end' => self::hhmm( $end ), 'breaks' => array() ) : 'off';
            }

            return $schedule;
        }
        $days = self::days();
        foreach ( self::scheduleRows( (int) $staff_id ) as $day_index => $row ) {
            $schedule[ $days[ $day_index ] ] = self::dayShape( $row['start'], $row['end'], $row['breaks'] );
        }

        return $schedule;
    }

    /**
     * Day in the model shape from stored values.
     *
     * @param string|null $start
     * @param string|null $end
     * @param array       $breaks array( array( 'start', 'end' ) ) or rows with start_time / end_time
     * @return array|string
     */
    public static function dayShape( $start, $end, array $breaks )
    {
        if ( $start === null || $start === '' ) {
            return 'off';
        }
        $list = array();
        foreach ( $breaks as $break ) {
            $b_start = isset( $break['start_time'] ) ? $break['start_time'] : $break['start'];
            $b_end   = isset( $break['end_time'] ) ? $break['end_time'] : $break['end'];
            $list[]  = array( 'start' => self::hhmm( $b_start ), 'end' => self::hhmm( $b_end ) );
        }
        usort( $list, function ( $a, $b ) {
            return AllowedValues::seconds( $a['start'] ) - AllowedValues::seconds( $b['start'] );
        } );

        return array( 'start' => self::hhmm( $start ), 'end' => self::hhmm( $end ), 'breaks' => $list );
    }

    /**
     * Day as the card shows it: "09:00–18:00 (breaks 13:00–14:00)" or "OFF".
     *
     * @param array|string $day
     * @return string
     */
    public static function dayText( $day )
    {
        if ( ! is_array( $day ) ) {
            return __( 'OFF', 'bookly-responsive-appointment-booking-tool' );
        }
        $text = self::interval( $day['start'], $day['end'] );
        if ( ! empty( $day['breaks'] ) ) {
            $breaks = array();
            foreach ( $day['breaks'] as $break ) {
                $breaks[] = self::interval( $break['start'], $break['end'] );
            }
            $text .= ' (' . __( 'Breaks', 'bookly-responsive-appointment-booking-tool' ) . ': ' . implode( ', ', $breaks ) . ')';
        }

        return $text;
    }

    /**
     * @param string $start
     * @param string $end
     * @return string
     */
    public static function interval( $start, $end )
    {
        return Lib\Utils\DateTime::formatTime( self::seconds( $start ) ) . ' – ' . Lib\Utils\DateTime::formatTime( self::seconds( $end ) );
    }

    /**
     * Problems of a working day's hours and breaks as validation ids: time_out_of_range,
     * time_off_grid, start_not_before_end, day_longer_than_24h, break_start_not_before_end,
     * break_outside_hours, breaks_overlap. Field rules come from Rules::entries() of $entity
     * (staff_schedule_item or staff_special_day); a value equal to the stored one at the same
     * place is allowed off the grid.
     *
     * @param string       $entity
     * @param array        $day     array( 'start', 'end', 'breaks' )
     * @param array|string $current the day as stored now (to allow its values off the grid)
     * @param string       $where   "mon", "2026-11-15" — for the messages
     * @return string[]
     */
    public static function dayErrors( $entity, array $day, $current, $where )
    {
        $errors   = array();
        $entries  = Rules::entries();
        $r_start  = $entries[ 'field:' . $entity . '.start_time' ];
        $r_end    = $entries[ 'field:' . $entity . '.end_time' ];
        $r_bstart = $entries[ 'field:' . $entity . '.breaks.start_time' ];
        $r_bend   = $entries[ 'field:' . $entity . '.breaks.end_time' ];
        $step     = self::step() * MINUTE_IN_SECONDS;
        $start    = self::seconds( $day['start'] );
        $end      = self::seconds( $day['end'] );
        $cur      = is_array( $current ) ? $current : array( 'start' => null, 'end' => null, 'breaks' => array() );
        if ( $start === null || $end === null ) {
            return array( 'time_out_of_range: ' . $where . ' — start and end are "HH:MM"' );
        }
        if ( $start > DAY_IN_SECONDS - $step || $end > 2 * DAY_IN_SECONDS ) {
            $errors[] = 'time_out_of_range: ' . $where . ' — start up to ' . Lib\Utils\DateTime::buildTimeString( DAY_IN_SECONDS - $step, false ) . ', end up to 48:00';
        } else {
            $cur_start = $cur['start'] !== null ? self::dbTime( $cur['start'] ) : null;
            $cur_end   = $cur['end'] !== null ? self::dbTime( $cur['end'] ) : null;
            if ( ! AllowedValues::contains( self::dbTime( $day['start'] ), AllowedValues::of( $r_start, $cur_start ) )
                || ! AllowedValues::contains( self::dbTime( $day['end'] ), AllowedValues::of( $r_end, $cur_end ) ) ) {
                $errors[] = 'time_off_grid: ' . $where . ' — times are multiples of ' . self::step() . ' minutes';
            }
        }
        $values = array(
            'field:' . $entity . '.start_time' => $day['start'],
            'field:' . $entity . '.end_time'   => $day['end'],
        );
        $reader = function ( $ref ) use ( &$values ) {
            return isset( $values[ $ref ] ) ? $values[ $ref ] : null;
        };
        $map = array(
            'end_after_start' => 'start_not_before_end',
            'end_within_day'  => 'day_longer_than_24h',
        );
        foreach ( Constraints::failed( $r_end, $day['end'], $cur['end'], $reader ) as $failed ) {
            $id       = strstr( $failed, ':', true );
            $errors[] = ( isset( $map[ $id ] ) ? $map[ $id ] : $id ) . ': ' . $where . ' — ' . trim( substr( $failed, strlen( $id ) + 1 ) );
        }
        $breaks = isset( $day['breaks'] ) && is_array( $day['breaks'] ) ? array_values( $day['breaks'] ) : array();
        foreach ( $breaks as $i => $break ) {
            $b_start = isset( $break['start'] ) ? self::seconds( $break['start'] ) : null;
            $b_end   = isset( $break['end'] ) ? self::seconds( $break['end'] ) : null;
            $label   = $where . ' break ' . ( isset( $break['start'] ) ? $break['start'] : '?' ) . '–' . ( isset( $break['end'] ) ? $break['end'] : '?' );
            if ( $b_start === null || $b_end === null ) {
                $errors[] = 'time_out_of_range: ' . $label . ' — break start and end are "HH:MM"';
                continue;
            }
            if ( $b_start > 2 * DAY_IN_SECONDS - $step || $b_end > 2 * DAY_IN_SECONDS ) {
                $errors[] = 'time_out_of_range: ' . $label;
                continue;
            }
            $stored = false;
            foreach ( isset( $cur['breaks'] ) ? $cur['breaks'] : array() as $old ) {
                if ( self::seconds( $old['start'] ) === $b_start && self::seconds( $old['end'] ) === $b_end ) {
                    $stored = true;
                }
            }
            if ( ! $stored && ( ! AllowedValues::contains( self::dbTime( $break['start'] ), AllowedValues::of( $r_bstart ) )
                || ! AllowedValues::contains( self::dbTime( $break['end'] ), AllowedValues::of( $r_bend ) ) ) ) {
                $errors[] = 'time_off_grid: ' . $label;
            }
            if ( $b_start >= $b_end ) {
                $errors[] = 'break_start_not_before_end: ' . $label;
                continue;
            }
            $values['field:' . $entity . '.breaks.start_time'] = $break['start'];
            $values['field:' . $entity . '.breaks.end_time']   = $break['end'];
            $outside = Constraints::failed( $r_bstart, $break['start'], null, $reader );
            foreach ( Constraints::failed( $r_bend, $break['end'], null, $reader ) as $failed ) {
                if ( strpos( $failed, 'break_end_after_start' ) !== 0 ) {
                    $outside[] = $failed;
                }
            }
            if ( $outside ) {
                $errors[] = 'break_outside_hours: ' . $label . ' — inside ' . $day['start'] . '–' . $day['end'];
            }
            foreach ( $breaks as $j => $other ) {
                if ( $j > $i && isset( $other['start'], $other['end'] )
                    && self::seconds( $other['start'] ) < $b_end && self::seconds( $other['end'] ) > $b_start ) {
                    $errors[] = 'breaks_overlap: ' . $label . ' and ' . $other['start'] . '–' . $other['end'];
                }
            }
        }

        return $errors;
    }

    /* ------------------------------------------------------------------ services */

    /**
     * State ref of the staff member's services of a location (null — general settings).
     *
     * @param mixed    $staff_id
     * @param int|null $location_id
     * @return string
     */
    public static function servicesRef( $staff_id, $location_id = null )
    {
        return 'staff:services#' . $staff_id . ( $location_id ? '@' . $location_id : '' );
    }

    /**
     * Services of the staff member in the shape of get_staff.services: general settings
     * (location_id NULL) or those of a location with its own services.
     *
     * @param mixed      $staff_id
     * @param State|null $state
     * @param int|null   $location_id
     * @return array
     */
    public static function services( $staff_id, $state = null, $location_id = null )
    {
        $ref = self::servicesRef( $staff_id, $location_id );
        if ( $state && $state->has( $ref ) ) {
            return $state->get( $ref );
        }
        if ( self::isNew( $staff_id, $state ) ) {
            return array();
        }
        $list = array();
        $rows = Lib\Entities\StaffService::query( 'ss' )
            ->select( 'ss.service_id, ss.price, ss.capacity_min, ss.capacity_max' )
            ->leftJoin( 'Service', 's', 's.id = ss.service_id' )
            ->where( 'ss.staff_id', (int) $staff_id )
            ->where( 'ss.location_id', $location_id ? (int) $location_id : null )
            ->sortBy( 's.position, ss.id' )
            ->fetchArray();
        $seen = array();
        foreach ( $rows as $row ) {
            if ( isset( $seen[ $row['service_id'] ] ) ) {
                continue;
            }
            $seen[ $row['service_id'] ] = true;
            $list[] = array(
                'service_id'   => (int) $row['service_id'],
                'price'        => (float) $row['price'],
                'capacity_min' => (int) $row['capacity_min'],
                'capacity_max' => (int) $row['capacity_max'],
            );
        }

        return $list;
    }

    /**
     * Services the Services tab offers (simple, and packages with the Packages add-on), in the
     * shape of get_staff.allowed.services.
     *
     * @return array
     */
    public static function allowedServices()
    {
        $types = array( Lib\Entities\Service::TYPE_SIMPLE );
        if ( Lib\Config::packagesActive() ) {
            $types[] = Lib\Entities\Service::TYPE_PACKAGE;
        }
        $sub = array();
        foreach ( Lib\Entities\SubService::query()->sortBy( 'position' )->fetchArray() as $row ) {
            if ( ! isset( $sub[ $row['service_id'] ] ) && $row['sub_service_id'] ) {
                $sub[ $row['service_id'] ] = (int) $row['sub_service_id'];
            }
        }
        $list = array();
        foreach ( Lib\Entities\Service::query()->whereIn( 'type', $types )->sortBy( 'position' )->fetchArray() as $service ) {
            $item = array(
                'id'    => (int) $service['id'],
                'title' => $service['title'],
                'type'  => $service['type'],
                'price' => (float) $service['price'],
            );
            if ( $service['type'] === Lib\Entities\Service::TYPE_PACKAGE ) {
                $item['package_of'] = isset( $sub[ $service['id'] ] ) ? $sub[ $service['id'] ] : null;
            } else {
                $item['capacity_min'] = (int) $service['capacity_min'];
                $item['capacity_max'] = (int) $service['capacity_max'];
            }
            $list[] = $item;
        }

        return $list;
    }

    /* ------------------------------------------------------------------ special days */

    /**
     * Special days (location_id NULL) from today on, keyed by date: array( 'date', 'start', 'end',
     * 'breaks', 'duplicates'? ). Empty without the Special Days add-on.
     *
     * @param mixed      $staff_id
     * @param State|null $state
     * @return array
     */
    public static function specialDays( $staff_id, $state = null )
    {
        $days = array();
        if ( ! self::isNew( $staff_id, $state ) && Lib\Config::specialDaysActive() ) {
            $today = date_create( current_time( 'mysql' ) )->format( 'Y-m-d' );
            $rows  = \Bookly\Backend\Components\Dialogs\Staff\Edit\Proxy\SpecialDays::getStaffSpecialDays( (int) $staff_id, null );
            foreach ( is_array( $rows ) ? $rows : array() as $row ) {
                $date = substr( $row['date'], 0, 10 );
                if ( $date < $today ) {
                    continue;
                }
                if ( isset( $days[ $date ] ) ) {
                    $days[ $date ]['duplicates'] = true;
                    continue;
                }
                $shape = self::dayShape( $row['start_time'], $row['end_time'], $row['breaks'] );
                $days[ $date ] = is_array( $shape )
                    ? array_merge( array( 'date' => $date ), $shape )
                    : array( 'date' => $date, 'off' => true );
                $days[ $date ]['id'] = (int) $row['id'];
            }
        }
        if ( $state && $state->has( 'staff:special_days#' . $staff_id ) ) {
            foreach ( $state->get( 'staff:special_days#' . $staff_id ) as $date => $day ) {
                $days[ $date ] = $day;
            }
        }
        ksort( $days );

        return $days;
    }

    /**
     * Whether the date is a day off of the staff member (own or the company's copy).
     *
     * @param int    $staff_id
     * @param string $date Y-m-d
     * @return bool
     */
    public static function isDayOff( $staff_id, $date )
    {
        static $cache = array();
        if ( ! isset( $cache[ $staff_id ] ) ) {
            $cache[ $staff_id ] = Lib\Entities\Holiday::query()->where( 'staff_id', $staff_id )->fetchArray();
        }
        list( $y, $m, $d ) = array_map( 'intval', explode( '-', $date ) );
        $off = false;
        foreach ( $cache[ $staff_id ] as $holiday ) {
            list( $hy, $hm, $hd ) = array_map( 'intval', explode( '-', substr( $holiday['date'], 0, 10 ) ) );
            if ( $hm !== $m || $hd !== $d ) {
                continue;
            }
            if ( $holiday['repeat_event'] == Lib\Entities\Holiday::TYPE_EXCEPTION ) {
                if ( $hy === $y ) {
                    return false;
                }
            } elseif ( $holiday['repeat_event'] == Lib\Entities\Holiday::TYPE_YEARLY || $hy === $y ) {
                $off = true;
            }
        }

        return $off;
    }

    /* ------------------------------------------------------------------ appointments */

    /**
     * Upcoming appointments of a staff member in busy statuses (pending, approved and busy custom
     * statuses), as Archive… checks them: id, start, end (site time zone), service.
     *
     * @param int         $staff_id
     * @param string|null $till Y-m-d, inclusive
     * @return array
     */
    public static function futureAppointments( $staff_id, $till = null )
    {
        $query = Lib\Entities\Appointment::query( 'a' )
            ->select( 'a.id, a.start_date, a.end_date, a.extras_duration, a.custom_service_name, s.title AS service' )
            ->innerJoin( 'CustomerAppointment', 'ca', 'ca.appointment_id = a.id' )
            ->leftJoin( 'Service', 's', 's.id = a.service_id' )
            ->where( 'a.staff_id', $staff_id )
            ->whereGt( 'a.start_date', current_time( 'mysql' ) )
            ->whereIn( 'ca.status', Lib\Proxy\CustomStatuses::prepareBusyStatuses( array(
                Lib\Entities\CustomerAppointment::STATUS_PENDING,
                Lib\Entities\CustomerAppointment::STATUS_APPROVED,
            ) ) )
            ->groupBy( 'a.id' )
            ->sortBy( 'a.start_date' );
        if ( $till !== null ) {
            $query->whereLte( 'a.start_date', $till . ' 23:59:59' );
        }
        $list = array();
        foreach ( $query->fetchArray() as $row ) {
            $list[] = array(
                'id'      => (int) $row['id'],
                'start'   => $row['start_date'],
                'end'     => date( 'Y-m-d H:i:s', strtotime( $row['end_date'] ) + (int) $row['extras_duration'] ),
                'service' => $row['service'] !== null ? $row['service'] : (string) $row['custom_service_name'],
            );
        }

        return $list;
    }

    /**
     * Appointment start / end in the staff member's time zone (the schedule's time zone).
     *
     * @param array       $appointment futureAppointments() item
     * @param string|null $time_zone   staff time zone ("Europe/Berlin", "UTC+3") or null
     * @return array array( date Y-m-d, start seconds, end seconds ) — from midnight of that date
     */
    public static function localInterval( array $appointment, $time_zone )
    {
        $start = $appointment['start'];
        $end   = $appointment['end'];
        if ( $time_zone ) {
            $tz = preg_match( '/^UTC[+-]/', $time_zone )
                ? Lib\Utils\DateTime::formatOffset( preg_replace( '/UTC\+?/', '', $time_zone ) * HOUR_IN_SECONDS )
                : $time_zone;
            $start = Lib\Utils\DateTime::convertTimeZone( $start, Lib\Config::getWPTimeZone(), $tz );
            $end   = Lib\Utils\DateTime::convertTimeZone( $end, Lib\Config::getWPTimeZone(), $tz );
        }
        $date     = substr( $start, 0, 10 );
        $midnight = strtotime( $date . ' 00:00:00' );

        return array( $date, strtotime( $start ) - $midnight, strtotime( $end ) - $midnight );
    }

    /**
     * Whether an interval (seconds from midnight of its date) fits a day's hours and misses its
     * breaks. $previous — the day before, whose night shift (end over 24:00) may cover it.
     *
     * @param int          $start
     * @param int          $end
     * @param array|string $day
     * @param array|string $previous
     * @return bool
     */
    public static function fits( $start, $end, $day, $previous = 'off' )
    {
        $candidates = array();
        if ( is_array( $day ) ) {
            $candidates[] = array( $day, 0 );
        }
        if ( is_array( $previous ) ) {
            $candidates[] = array( $previous, DAY_IN_SECONDS );
        }
        foreach ( $candidates as $candidate ) {
            list( $d, $shift ) = $candidate;
            $s = $start + $shift;
            $e = $end + $shift;
            if ( $s < self::seconds( $d['start'] ) || $e > self::seconds( $d['end'] ) ) {
                continue;
            }
            $free = true;
            foreach ( $d['breaks'] as $break ) {
                if ( self::seconds( $break['start'] ) < $e && self::seconds( $break['end'] ) > $s ) {
                    $free = false;
                }
            }
            if ( $free ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Upcoming appointments that fit the weekly schedule $before but not $after, on dates without a
     * special day or a day off (those dates do not follow the weekly hours).
     *
     * @param int   $staff_id
     * @param array $before schedule keyed sun…sat
     * @param array $after
     * @return array futureAppointments() items
     */
    public static function weeklyConflicts( $staff_id, array $before, array $after )
    {
        $staff     = Lib\Entities\Staff::find( $staff_id, false );
        $time_zone = $staff ? $staff->getTimeZone( false ) : null;
        $special   = self::specialDays( $staff_id );
        $days      = array_values( self::days() );
        $conflicts = array();
        foreach ( self::futureAppointments( $staff_id ) as $appointment ) {
            list( $date, $start, $end ) = self::localInterval( $appointment, $time_zone );
            if ( isset( $special[ $date ] ) || self::isDayOff( $staff_id, $date ) ) {
                continue;
            }
            $w    = (int) date( 'w', strtotime( $date ) );
            $key  = $days[ $w ];
            $prev = $days[ ( $w + 6 ) % 7 ];
            if ( self::fits( $start, $end, $before[ $key ], $before[ $prev ] ) && ! self::fits( $start, $end, $after[ $key ], $after[ $prev ] ) ) {
                $conflicts[] = $appointment;
            }
        }

        return $conflicts;
    }

    /**
     * Card text of a conflicts list.
     *
     * @param array $conflicts
     * @return string
     */
    public static function conflictsText( array $conflicts )
    {
        $items = array();
        foreach ( array_slice( $conflicts, 0, 5 ) as $appointment ) {
            $items[] = Lib\Utils\DateTime::formatDateTime( $appointment['start'] ) . ' — ' . $appointment['service'];
        }
        $text = sprintf( _n( '%d upcoming appointment no longer fits the hours and stays in the calendar: %s', '%d upcoming appointments no longer fit the hours and stay in the calendar: %s', count( $conflicts ), 'bookly-responsive-appointment-booking-tool' ), count( $conflicts ), implode( '; ', $items ) );
        if ( count( $conflicts ) > 5 ) {
            $text .= ' …';
        }

        return $text;
    }

    /**
     * Locations of the staff member with their own settings (Locations add-on): ids of those with
     * a custom schedule / services / special days.
     *
     * @param int    $staff_id
     * @param string $what schedule | services | special_days
     * @return int[]
     */
    public static function ownLocations( $staff_id, $what )
    {
        $ids = array();
        if ( ! Lib\Config::locationsActive() || ! is_numeric( $staff_id ) ) {
            return $ids;
        }
        $locations = Lib\Proxy\Locations::findByStaffId( (int) $staff_id );
        foreach ( is_array( $locations ) ? $locations : array() as $location ) {
            $id = (int) $location->getId();
            switch ( $what ) {
                case 'schedule':
                    $own = Lib\Proxy\Locations::prepareStaffScheduleLocationId( $id, (int) $staff_id );
                    break;
                case 'services':
                    $own = Lib\Proxy\Locations::prepareStaffLocationId( $id, (int) $staff_id );
                    break;
                default:
                    $own = Lib\Proxy\Locations::prepareStaffSpecialDaysLocationId( $id, (int) $staff_id );
            }
            if ( $own ) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Card warning "Not affected — <locations> (own …)".
     *
     * @param int[] $location_ids
     * @return string[]
     */
    public static function notAffected( array $location_ids )
    {
        if ( ! $location_ids ) {
            return array();
        }
        $names = array();
        foreach ( $location_ids as $id ) {
            $location = Lib\Proxy\Locations::findById( $id );
            $names[]  = $location ? $location->getName() : '#' . $id;
        }

        return array( sprintf( __( 'Not affected — %s (own settings for the location).', 'bookly-responsive-appointment-booking-tool' ), implode( ', ', $names ) ) );
    }
}
