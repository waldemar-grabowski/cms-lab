<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\AllowedValues;
use Bookly\Lib\AiAdmin\Constraints;
use Bookly\Lib\AiAdmin\Format;
use Bookly\Lib\AiAdmin\Knowledge;
use Bookly\Lib\AiAdmin\Rules;
use Bookly\Lib\AiAdmin\Services\ServiceAction;
use Bookly\Lib\AiAdmin\Services\ServiceData;
use Bookly\Lib\AiAdmin\StaffData;
use Bookly\Lib\AiAdmin\State;

/**
 * set_service — change the given fields of one service; fields not given stay
 * (assets/ai-setup/actions/set_service.yaml). Writes only the changed columns of the service with
 * the conversions of the edit dialog, so a partial request clears nothing else.
 */
class SetService extends ServiceAction implements ActionInterface
{
    /** Fields a package copies from its simple service on Save. */
    const PACKAGE_COPY = 'category_id,duration,capacity_min,capacity_max,padding_left,padding_right';

    protected function name()
    {
        return 'set_service';
    }

    /**
     * @inheritDoc
     */
    public function validate( array $args, State $state )
    {
        if ( $error = $this->forbidden() ) {
            return array( $error );
        }
        $id = isset( $args['service_id'] ) ? self::id( $args['service_id'] ) : 0;
        if ( ! self::exists( $id, $state ) ) {
            return array( 'service_not_found: ' . wp_json_encode( isset( $args['service_id'] ) ? $args['service_id'] : null ) );
        }
        $fields = isset( $args['fields'] ) && is_array( $args['fields'] ) ? $args['fields'] : array();
        if ( ! $fields ) {
            return array( 'fields: give at least one field to change' );
        }
        $type = self::current( $state, $id, 'type' );
        $meta = ServiceData::fields();
        $errors = array();
        foreach ( $fields as $field => $value ) {
            if ( ! isset( $meta[ $field ] ) ) {
                $errors[] = 'unknown_field: ' . $field;
                continue;
            }
            $rule = Access::rule( 'field:service.' . $field );
            if ( ! $rule || empty( $rule['edit'] ) || ! Access::allowed( $rule['edit'] ) ) {
                $errors[] = 'forbidden: ' . $field . ' cannot be changed by the assistant';
            } elseif ( ! ServiceData::fieldForType( $field, $type ) ) {
                $errors[] = 'field_not_for_type: the dialog of a ' . $type . ' service has no ' . $field;
            } elseif ( $meta[ $field ]['addon'] && ! Access::addonActive( $meta[ $field ]['addon'] ) ) {
                $errors[] = 'addon_inactive: ' . $field . ' needs the ' . $meta[ $field ]['addon'] . ' add-on';
            }
        }
        if ( $errors ) {
            return $errors;
        }
        if ( $type === Lib\Entities\Service::TYPE_PACKAGE && Lib\Config::packagesActive() && ! self::current( $state, $id, 'steps' ) ) {
            return array( 'package_without_service: choose the package\'s service first (set_service_sub_services)' );
        }

        $before = array();
        foreach ( array_keys( $meta ) as $field ) {
            $before[ $field ] = self::current( $state, $id, $field );
        }
        $after = $this->after( $before, $fields );
        $errors = $this->check( $id, $type, $fields, $before, $after, $state );
        if ( ! $errors ) {
            $errors = $this->checkStaffRows( $args, $type, $before, $after );
        }
        if ( ! $errors ) {
            $this->record( $id, $type, $before, $after, $state );
        }

        return $errors;
    }

    /**
     * Values after the change: given fields, staff_preference_settings merged, the limit cleared
     * with its period, units_min = 1 when Custom duration is turned off.
     *
     * @param array $before
     * @param array $fields
     * @return array
     */
    protected function after( array $before, array $fields )
    {
        $integers = array( 'category_id', 'duration', 'units_min', 'units_max', 'padding_left', 'padding_right', 'capacity_min', 'capacity_max',
            'appointments_limit', 'min_time_prior_booking', 'min_time_prior_cancel', 'package_size', 'package_life_time', 'slot_length' );
        $after = $before;
        foreach ( $fields as $field => $value ) {
            if ( $field === 'staff_preference_settings' && is_array( $value ) ) {
                $after[ $field ] = array_merge( $before[ $field ], $value );
            } elseif ( $field === 'price' && is_numeric( $value ) ) {
                $after[ $field ] = (float) $value;
            } elseif ( in_array( $field, $integers, true ) && is_numeric( $value ) && (int) $value == $value ) {
                $after[ $field ] = (int) $value;
            } elseif ( $field === 'taxes' && is_array( $value ) ) {
                // Only the change: current taxes plus add minus remove ("$N" — a tax created earlier).
                $add = isset( $value['add'] ) && is_array( $value['add'] ) ? self::taxIds( $value['add'] ) : array();
                $remove = isset( $value['remove'] ) && is_array( $value['remove'] ) ? self::taxIds( $value['remove'] ) : array();
                $after[ $field ] = array();
                foreach ( array_merge( self::taxIds( $before[ $field ] ), $add ) as $tax_id ) {
                    if ( ! in_array( $tax_id, $remove, true ) && ! in_array( $tax_id, $after[ $field ], true ) ) {
                        $after[ $field ][] = $tax_id;
                    }
                }
                sort( $after[ $field ] );
            } else {
                $after[ $field ] = $value;
            }
        }
        if ( $after['limit_period'] === 'off' ) {
            $after['appointments_limit'] = null;
        }
        if ( array_key_exists( 'units_max', $fields ) && (int) $fields['units_max'] === 1 && ! array_key_exists( 'units_min', $fields ) ) {
            $after['units_min'] = 1;
        }

        return $after;
    }

    /**
     * Checks of the given values (validation of set_service.yaml).
     *
     * @param int|string $id
     * @param string     $type
     * @param array      $fields
     * @param array      $before
     * @param array      $after
     * @param State      $state
     * @return string[]
     */
    protected function check( $id, $type, array $fields, array $before, array $after, State $state )
    {
        $errors = array();
        $reader = self::reader( $state, $id, $after );
        foreach ( $fields as $field => $value ) {
            $rule = Access::rule( 'field:service.' . $field );
            switch ( $field ) {
                case 'title':
                    if ( ! is_string( $value ) || trim( $value ) === '' ) {
                        $errors[] = 'title_empty: the title is empty';
                    } elseif ( mb_strlen( $value ) > 255 ) {
                        $errors[] = 'text_too_long: title is at most 255 characters';
                    }
                    break;
                case 'category_id':
                    // Existing categories and the ones created by earlier items ("$N").
                    if ( $value !== null && ! ( ( is_int( $value ) || is_string( $value ) )
                            && array_key_exists( $value, SetCategory::categories( $state ) ) ) ) {
                        $errors[] = 'category_not_found: ' . wp_json_encode( $value );
                    }
                    break;
                case 'color':
                    if ( ! is_string( $value ) || ! preg_match( '/^#[0-9a-fA-F]{6}$/', $value ) ) {
                        $errors[] = 'color_format: color is "#RRGGBB"';
                    }
                    break;
                case 'visibility':
                    if ( ! in_array( $value, array( 'public', 'private' ), true ) ) {
                        $errors[] = 'visibility_group: visibility is public or private; customer groups are set in the customers area';
                    }
                    break;
                case 'info':
                case 'start_time_info':
                case 'end_time_info':
                    if ( ! is_string( $value ) ) {
                        $errors[] = 'value_not_allowed: ' . $field . ' is a text';
                    } elseif ( $field !== 'info' && mb_strlen( $value ) > 32 ) {
                        $errors[] = 'text_too_long: ' . $field . ' is at most 32 characters';
                    } elseif ( $field !== 'info' && $after['duration'] < DAY_IN_SECONDS ) {
                        $errors[] = 'time_info_hidden: ' . $field . ' is shown only for a duration of 1 day or longer';
                    }
                    break;
                case 'price':
                    if ( ! is_numeric( $value ) || $value < 0 ) {
                        $errors[] = 'price_negative: the price is a number, zero or more';
                    }
                    break;
                case 'duration':
                    if ( ! in_array( (int) $value, ServiceData::allowedDurations( $before['duration'] ), true ) || (int) $value != $value ) {
                        $errors[] = 'duration_not_in_list: ' . wp_json_encode( $value ) . ' — see get_service.allowed.duration';
                    }
                    break;
                case 'units_min':
                case 'units_max':
                    if ( ! is_numeric( $value ) || (int) $value != $value || $value < 1 ) {
                        $errors[] = 'units_range: ' . $field . ' is a whole number, 1 or more';
                    }
                    break;
                case 'slot_length':
                    if ( Lib\Config::customDurationActive() && $after['duration'] >= DAY_IN_SECONDS ) {
                        $errors[] = 'slot_length_hidden: the time slot length is not shown for a duration of 1 day or longer';
                    } elseif ( ! AllowedValues::contains( $value, AllowedValues::of( $rule, $before['slot_length'] ) ) ) {
                        $errors[] = 'slot_length_not_in_list: ' . wp_json_encode( $value ) . ' — see get_service.allowed.slot_length';
                    } else {
                        foreach ( Constraints::failed( $rule, $value, $before['slot_length'], $reader ) as $failed ) {
                            $errors[] = 'slot_length_not_in_list: ' . $failed;
                        }
                    }
                    break;
                case 'padding_left':
                case 'padding_right':
                    if ( ! ServiceData::paddingAllowed( $value, $before[ $field ] ) ) {
                        $errors[] = 'padding_off_grid: ' . $field . ' = ' . wp_json_encode( $value ) . ' — see get_service.allowed.padding';
                    }
                    break;
                case 'min_time_prior_booking':
                case 'min_time_prior_cancel':
                    if ( ! in_array( $value, ServiceData::allowedMinTimePrior(), true ) ) {
                        $errors[] = 'min_time_not_in_list: ' . $field . ' = ' . wp_json_encode( $value ) . ' — see get_service.allowed.min_time_prior';
                    }
                    break;
                case 'capacity_min':
                case 'capacity_max':
                    if ( ! is_numeric( $value ) || (int) $value != $value || $value < 1 ) {
                        $errors[] = 'capacity_range: ' . $field . ' is a whole number, 1 or more';
                    }
                    break;
                case 'appointments_limit':
                    if ( $value !== null && ( ! is_numeric( $value ) || (int) $value != $value || $value < 1 ) ) {
                        $errors[] = 'limit_without_period: appointments_limit is null or a whole number, 1 or more';
                    } elseif ( $value !== null && $after['limit_period'] === 'off' ) {
                        $errors[] = 'limit_without_period: set limit_period (not "off") together with appointments_limit';
                    }
                    break;
                case 'final_step_url':
                    if ( ! is_string( $value ) || ( $value !== '' && ! preg_match( '~^https?://~i', $value ) ) ) {
                        $errors[] = 'final_step_url_format: "" or an http(s) address';
                    } elseif ( mb_strlen( $value ) > 512 ) {
                        $errors[] = 'text_too_long: final_step_url is at most 512 characters';
                    }
                    break;
                case 'gateways':
                    if ( $value !== 'default' ) {
                        $known = array_keys( Lib\Utils\Common::getGateways() );
                        if ( ! is_array( $value ) || ! $value || count( array_unique( $value ) ) !== count( $value ) ) {
                            $errors[] = 'gateways_unknown: "default" or a non-empty list of payment method ids';
                        } elseif ( array_diff( $value, $known ) ) {
                            $errors[] = 'gateways_unknown: ' . implode( ', ', array_diff( $value, $known ) ) . ' — see get_service.allowed.gateways';
                        }
                    }
                    break;
                case 'taxes':
                    // Existing taxes and the ones created by earlier items ("$N").
                    $known = self::taxIds( array_keys( SetTax::taxes( $state ) ) );
                    $add = is_array( $value ) && isset( $value['add'] ) ? $value['add'] : array();
                    $remove = is_array( $value ) && isset( $value['remove'] ) ? $value['remove'] : array();
                    if ( ! is_array( $value ) || array_diff( array_keys( $value ), array( 'add', 'remove' ) )
                        || ! is_array( $add ) || ! is_array( $remove ) || ( ! $add && ! $remove ) ) {
                        $errors[] = 'tax_not_found: taxes is {add: [tax ids], remove: [tax ids]}, not both empty';
                    } else {
                        $applied = self::taxIds( $before['taxes'] );
                        foreach ( $add as $tax_id ) {
                            if ( ! in_array( self::taxId( $tax_id ), $known, true ) ) {
                                $errors[] = 'tax_not_found: ' . wp_json_encode( $tax_id );
                            } elseif ( in_array( self::taxId( $tax_id ), $applied, true ) ) {
                                $errors[] = 'tax_already_applied: tax ' . $tax_id . ' is already applied to the service';
                            }
                        }
                        foreach ( $remove as $tax_id ) {
                            if ( ! in_array( self::taxId( $tax_id ), $applied, true ) ) {
                                $errors[] = 'tax_not_applied: tax ' . wp_json_encode( $tax_id ) . ' is not applied to the service (get_service.service.taxes)';
                            } elseif ( in_array( self::taxId( $tax_id ), self::taxIds( $add ), true ) ) {
                                $errors[] = 'tax_not_applied: tax ' . $tax_id . ' is both in add and in remove';
                            }
                        }
                    }
                    break;
                case 'staff_preference_settings':
                    if ( ! is_array( $value ) || ! $value ) {
                        $errors[] = 'value_not_allowed: staff_preference_settings is an object with period_before, period_after, random';
                        break;
                    }
                    foreach ( $value as $key => $item ) {
                        if ( $key === 'random' ? ! is_bool( $item ) : ( ! in_array( $key, array( 'period_before', 'period_after' ), true ) || ! is_numeric( $item ) || (int) $item != $item || $item < 0 ) ) {
                            $errors[] = 'value_not_allowed: staff_preference_settings.' . $key . ' = ' . wp_json_encode( $item );
                        }
                    }
                    break;
                case 'one_booking_per_slot':
                case 'package_unassigned':
                case 'same_staff_for_subservices':
                case 'collaborative_equal_duration':
                    if ( ! is_bool( $value ) ) {
                        $errors[] = 'value_not_allowed: ' . $field . ' is true or false';
                    }
                    break;
                case 'package_size':
                case 'package_life_time':
                    if ( ! is_numeric( $value ) || (int) $value != $value ) {
                        $errors[] = 'value_not_allowed: ' . $field . ' is a whole number';
                    } else {
                        foreach ( Constraints::failed( $rule, $value, $before[ $field ], $reader ) as $failed ) {
                            $errors[] = 'value_not_allowed: ' . $field . ' — ' . $failed;
                        }
                    }
                    break;
                default:
                    // Lists of the dialog: time_requirements, online_meetings, limit_period, staff_preference, package_life_time_type.
                    if ( ! AllowedValues::contains( $value, AllowedValues::of( $rule, $before[ $field ] ) ) || ! is_string( $value ) ) {
                        $errors[] = 'value_not_allowed: ' . $field . ' = ' . wp_json_encode( $value );
                    }
            }
        }
        if ( $errors ) {
            return $errors;
        }
        if ( ( isset( $fields['units_min'] ) || isset( $fields['units_max'] ) )
            && ( $after['units_min'] > $after['units_max'] || ( $after['units_max'] == 1 && $after['units_min'] != 1 ) ) ) {
            $errors[] = 'units_range: 1 <= units_min <= units_max; units_max = 1 needs units_min = 1';
        }
        if ( isset( $fields['capacity_min'] ) || isset( $fields['capacity_max'] ) ) {
            foreach ( array( 'capacity_min', 'capacity_max' ) as $field ) {
                foreach ( Constraints::failed( Access::rule( 'field:service.' . $field ), $after[ $field ], $before[ $field ], $reader ) as $failed ) {
                    $errors[] = 'capacity_range: ' . $failed;
                }
            }
        }
        if ( isset( $fields['price'] ) ) {
            foreach ( Constraints::failed( Access::rule( 'field:service.price' ), $after['price'], $before['price'], $reader ) as $failed ) {
                $errors[] = 'price_negative: ' . $failed;
            }
        }
        return array_values( array_unique( $errors ) );
    }

    /**
     * staff_rows is given exactly when the price (simple, package) or the capacity changes.
     *
     * @param array  $args
     * @param string $type
     * @param array  $before
     * @param array  $after
     * @return string[]
     */
    protected function checkStaffRows( array $args, $type, array $before, array $after )
    {
        $needed = ( in_array( $type, array( 'simple', 'package' ), true ) && (float) $after['price'] != (float) $before['price'] )
            || (int) $after['capacity_min'] !== (int) $before['capacity_min']
            || (int) $after['capacity_max'] !== (int) $before['capacity_max'];
        $given = isset( $args['staff_rows'] );
        if ( $needed && ( ! $given || ! in_array( $args['staff_rows'], array( 'update', 'keep' ), true ) ) ) {
            return array( 'staff_rows_required: the price or capacity changes — give staff_rows "update" or "keep"' );
        }
        if ( ! $needed && $given ) {
            return array( 'staff_rows_required: staff_rows is only for a changed price or capacity' );
        }

        return array();
    }

    /**
     * Record the values after this item in the state (and in packages built on a simple service).
     *
     * @param int|string $id
     * @param string     $type
     * @param array      $before
     * @param array      $after
     * @param State      $state
     */
    protected function record( $id, $type, array $before, array $after, State $state )
    {
        foreach ( $after as $field => $value ) {
            if ( $value !== $before[ $field ] ) {
                self::setCurrent( $state, $id, $field, $value );
            }
        }
        // Later set_tax items list the services a tax is applied to.
        if ( $after['taxes'] !== $before['taxes'] && Lib\Config::taxesActive() ) {
            $taxes = array();
            if ( $state->has( 'list:taxes' ) ) {
                $taxes = $state->get( 'list:taxes' );
            } else {
                foreach ( (array) Lib\Proxy\Taxes::getTaxesList() as $tax ) {
                    $taxes[ $tax['id'] ] = $tax;
                }
            }
            foreach ( $taxes as $tax_id => $tax ) {
                $ids = array_values( array_diff( $tax['service_ids'], array( $id ) ) );
                if ( in_array( self::taxId( $tax_id ), $after['taxes'], true ) ) {
                    $ids[] = $id;
                }
                $taxes[ $tax_id ]['service_ids'] = $ids;
            }
            $state->set( 'list:taxes', $taxes );
        }
        if ( $type === Lib\Entities\Service::TYPE_SIMPLE && is_int( $id ) ) {
            foreach ( self::packagesOf( $id ) as $package_id ) {
                foreach ( explode( ',', self::PACKAGE_COPY ) as $field ) {
                    if ( $after[ $field ] !== $before[ $field ] ) {
                        self::setCurrent( $state, $package_id, $field, $after[ $field ] );
                    }
                }
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function preview( array $args, State $state )
    {
        $id = self::id( $args['service_id'] );
        $type = self::current( $state, $id, 'type' );
        $fields = $args['fields'];
        $before = array();
        foreach ( array_keys( ServiceData::fields() ) as $field ) {
            $before[ $field ] = self::current( $state, $id, $field );
        }
        $after = $this->after( $before, $fields );
        $rows = array();
        $warnings = array();
        foreach ( $after as $field => $value ) {
            if ( ! array_key_exists( $field, $fields ) && $value === $before[ $field ] ) {
                continue;
            }
            $rule = Access::rule( 'field:service.' . $field );
            if ( $field === 'staff_preference_settings' ) {
                foreach ( $value as $key => $item ) {
                    if ( $item !== $before[ $field ][ $key ] ) {
                        $sub = Access::rule( 'field:service.staff_preference_settings.' . $key );
                        $rows[] = array(
                            'label' => self::rowLabel( $state, $id, Format::label( $sub, $key ) . ( $key === 'period_before' ? ' (-)' : ( $key === 'period_after' ? ' (+)' : '' ) ) ),
                            'before' => Format::value( $sub, $before[ $field ][ $key ] ),
                            'after' => Format::value( $sub, $item ),
                            'link' => Format::link( $rule ),
                        );
                    }
                }
                continue;
            }
            $rows[] = array(
                'label' => self::rowLabel( $state, $id, Format::label( $rule, $field ) ),
                'before' => $this->text( $field, $rule, $before[ $field ], $state ),
                'after' => $this->text( $field, $rule, $value, $state ),
                'link' => Format::link( $rule ),
            );
            foreach ( Format::warnings( $rule, $before[ $field ], $value, $state ) as $text ) {
                $warnings[] = $text;
            }
        }
        if ( is_int( $id ) ) {
            $rows = array_merge( $rows, $this->staffRowsPreview( $id, $type, $args, $before, $after, $warnings, $state ) );
            if ( $type === Lib\Entities\Service::TYPE_SIMPLE ) {
                foreach ( self::packagesOf( $id ) as $package_id ) {
                    foreach ( explode( ',', self::PACKAGE_COPY ) as $field ) {
                        if ( $after[ $field ] !== $before[ $field ] ) {
                            $rule = Access::rule( 'field:service.' . $field );
                            $rows[] = array(
                                'label' => self::rowLabel( $state, $package_id, Format::label( $rule, $field ) ),
                                'before' => $this->text( $field, $rule, self::current( $state, $package_id, $field ), $state ),
                                'after' => $this->text( $field, $rule, $after[ $field ], $state ),
                            );
                        }
                    }
                }
            }
        }
        $this->record( $id, $type, $before, $after, $state );

        return array( 'rows' => $rows, 'warnings' => array_values( array_unique( $warnings ) ) );
    }

    /**
     * Rows of staff members whose own price / capacity changes (update) or stays different (keep).
     *
     * @param int    $id
     * @param string $type
     * @param array  $args
     * @param array  $before
     * @param array  $after
     * @param array  $warnings
     * @param State  $state
     * @return array
     */
    protected function staffRowsPreview( $id, $type, array $args, array $before, array $after, array &$warnings, State $state )
    {
        if ( ! isset( $args['staff_rows'] ) ) {
            return array();
        }
        $rows = array();
        $staff = ServiceData::staff();
        $kept = array();
        foreach ( Lib\Entities\StaffService::query()->where( 'service_id', $id )->fetchArray() as $row ) {
            $name = isset( $staff[ $row['staff_id'] ] ) ? $staff[ $row['staff_id'] ]['full_name'] : '#' . $row['staff_id'];
            $new_price = $after['price'];
            $new_capacity = $type === Lib\Entities\Service::TYPE_SIMPLE ? $after['capacity_min'] . '–' . $after['capacity_max'] : $row['capacity_min'] . '–' . $row['capacity_max'];
            $old_capacity = $row['capacity_min'] . '–' . $row['capacity_max'];
            if ( $args['staff_rows'] === 'update' ) {
                if ( (float) $row['price'] != (float) $new_price || $old_capacity !== $new_capacity ) {
                    $rows[] = array(
                        'label' => StaffData::rowLabel( (int) $row['staff_id'], $state, Knowledge::label( 'Services' ) . ( $row['location_id'] ? ' (' . __( 'Location', 'bookly-responsive-appointment-booking-tool' ) . ' #' . $row['location_id'] . ')' : '' ), array( 'item' => self::title( $state, $id ) ) ),
                        'before' => Format::price( $row['price'] ) . ', ' . $old_capacity,
                        'after' => Format::price( $new_price ) . ', ' . $new_capacity,
                    );
                }
            } elseif ( (float) $row['price'] != (float) $after['price'] ) {
                $kept[] = $name . ' ' . Format::price( $row['price'] );
            }
        }
        if ( $kept ) {
            $warnings[] = sprintf( __( 'These staff members keep their own price, bookings use it: %s', 'bookly-responsive-appointment-booking-tool' ), implode( ', ', array_unique( $kept ) ) );
        }

        return $rows;
    }

    /**
     * Tax id as the state keeps it: int for an existing tax, "$N" for one created earlier.
     *
     * @param mixed $id
     * @return int|string
     */
    public static function taxId( $id )
    {
        return is_string( $id ) && $id !== '' && $id[0] === '$' ? $id : (int) $id;
    }

    /**
     * @param mixed $ids
     * @return array
     */
    public static function taxIds( $ids )
    {
        $out = array();
        foreach ( (array) $ids as $id ) {
            $out[] = self::taxId( $id );
        }

        return $out;
    }

    /**
     * Value of a field for the card.
     *
     * @param string $field
     * @param array  $rule
     * @param mixed  $value
     * @param State  $state
     * @return string
     */
    protected function text( $field, $rule, $value, State $state )
    {
        switch ( $field ) {
            case 'price':
                return Format::price( $value );
            case 'category_id':
                if ( $value === null ) {
                    return __( 'Uncategorized', 'bookly-responsive-appointment-booking-tool' );
                }
                if ( $state->isNew( $value ) === 'category' ) {
                    $categories = SetCategory::categories( $state );

                    return $categories[ $value ]['name'];
                }
                $category = Lib\Entities\Category::find( $value );

                return $category ? $category->getName() : '#' . $value;
            case 'gateways':
                if ( $value === 'default' ) {
                    return __( 'Default', 'bookly-responsive-appointment-booking-tool' );
                }
                $gateways = Lib\Utils\Common::getGateways();
                $names = array();
                foreach ( (array) $value as $gateway ) {
                    $names[] = isset( $gateways[ $gateway ] ) ? $gateways[ $gateway ] : $gateway;
                }

                return implode( ', ', $names );
            case 'taxes':
                $titles = array();
                foreach ( SetTax::taxes( $state ) as $tax_id => $tax ) {
                    if ( in_array( self::taxId( $tax_id ), self::taxIds( $value ), true ) ) {
                        $titles[] = $tax['title'] . ' (' . $tax['rate'] . '%)';
                    }
                }

                return $titles ? implode( ', ', $titles ) : '—';
            case 'slot_length':
                if ( is_numeric( $value ) ) {
                    return Lib\Utils\DateTime::secondsToInterval( (int) $value );
                }
                break;
            case 'min_time_prior_booking':
            case 'min_time_prior_cancel':
                if ( $value === null ) {
                    return __( 'Default', 'bookly-responsive-appointment-booking-tool' );
                }
                if ( (int) $value === 0 ) {
                    return __( 'Disabled', 'bookly-responsive-appointment-booking-tool' );
                }

                return Lib\Utils\DateTime::secondsToInterval( (int) $value );
            case 'padding_left':
            case 'padding_right':
            case 'duration':
                return (int) $value === 0 ? __( 'OFF', 'bookly-responsive-appointment-booking-tool' ) : Lib\Utils\DateTime::secondsToInterval( (int) $value );
        }

        return Format::value( $rule, $value );
    }

    /**
     * @inheritDoc
     */
    public function apply( array $args )
    {
        $service = Lib\Entities\Service::find( $args['service_id'], false );
        $fields = $args['fields'];
        $before = ServiceData::shape( $service->getFields() );
        $after = $this->after( $before, $fields );
        $type = $service->getType();
        $snapshot = array( 'service_id' => (int) $service->getId(), 'service' => array(), 'after' => array(), 'packages' => array(), 'staff_services' => array(), 'taxes' => null );

        $columns = $this->columns( $before, $after );
        unset( $columns['taxes'] );
        $snapshot['service'] = array_intersect_key( $service->getFields(), $columns );
        $service->setFields( $columns )->save();
        $snapshot['after'] = array_intersect_key( $service->getFields(), $columns );

        if ( array_key_exists( 'taxes', $fields ) && Lib\Config::taxesActive() ) {
            $snapshot['taxes'] = $before['taxes'];
            Lib\Proxy\Taxes::setServiceTaxIds( $service->getId(), $after['taxes'] );
        }

        // Packages built on a simple service follow its category, duration, capacity and padding.
        if ( $type === Lib\Entities\Service::TYPE_SIMPLE ) {
            $copy = array();
            foreach ( explode( ',', self::PACKAGE_COPY ) as $field ) {
                if ( $after[ $field ] !== $before[ $field ] ) {
                    $copy[ $field ] = $after[ $field ];
                }
            }
            if ( $copy ) {
                foreach ( self::packagesOf( $service->getId() ) as $package_id ) {
                    $package = Lib\Entities\Service::find( $package_id, false );
                    $snapshot['packages'][ $package_id ] = array_intersect_key( $package->getFields(), $copy );
                    $package->setFields( $copy )->save();
                }
            }
        }

        if ( isset( $args['staff_rows'] ) && $args['staff_rows'] === 'update' ) {
            $rows = Lib\Entities\StaffService::query()->where( 'service_id', $service->getId() )->fetchArray();
            $packages = $type === Lib\Entities\Service::TYPE_SIMPLE ? self::packagesOf( $service->getId() ) : array();
            $package_rows = $packages ? Lib\Entities\StaffService::query()->whereIn( 'service_id', $packages )->fetchArray() : array();
            foreach ( $rows as $row ) {
                $snapshot['staff_services'][] = $row;
                $staff_service = new Lib\Entities\StaffService( $row );
                $staff_service->setPrice( $after['price'] );
                if ( $type === Lib\Entities\Service::TYPE_SIMPLE ) {
                    $staff_service->setCapacityMin( $after['capacity_min'] )->setCapacityMax( $after['capacity_max'] );
                }
                $staff_service->save();
            }
            foreach ( $package_rows as $row ) {
                $snapshot['staff_services'][] = $row;
                $staff_service = new Lib\Entities\StaffService( $row );
                $staff_service->setCapacityMin( $after['capacity_min'] )->setCapacityMax( $after['capacity_max'] )->save();
            }
        }

        return $snapshot;
    }

    /**
     * Database columns of the changed fields, converted as the edit dialog saves them.
     *
     * @param array $before
     * @param array $after
     * @return array
     */
    protected function columns( array $before, array $after )
    {
        $columns = array();
        foreach ( $after as $field => $value ) {
            if ( $value === $before[ $field ] ) {
                continue;
            }
            switch ( $field ) {
                case 'gateways':
                    $columns['gateways'] = $value === 'default' ? null : json_encode( array_values( $value ) );
                    break;
                case 'staff_preference_settings':
                    $columns['staff_preference_settings'] = json_encode( array(
                        'period' => array( 'before' => max( 0, (int) $value['period_before'] ), 'after' => max( 0, (int) $value['period_after'] ) ),
                        'random' => (bool) $value['random'],
                    ) );
                    break;
                case 'one_booking_per_slot':
                case 'package_unassigned':
                case 'same_staff_for_subservices':
                case 'collaborative_equal_duration':
                    $columns[ $field ] = $value ? 1 : 0;
                    break;
                case 'slot_length':
                    $columns[ $field ] = (string) $value;
                    break;
                default:
                    $columns[ $field ] = $value;
            }
        }

        return $columns;
    }

    /**
     * @inheritDoc
     */
    public function undo( array $snapshot )
    {
        $service = Lib\Entities\Service::find( $snapshot['service_id'], false );
        if ( ! $service ) {
            return;
        }
        // Restore only when the service still has the values the assistant wrote.
        if ( array_intersect_key( $service->getFields(), $snapshot['after'] ) != $snapshot['after'] ) {
            return;
        }
        $service->setFields( $snapshot['service'] )->save();
        if ( $snapshot['taxes'] !== null ) {
            Lib\Proxy\Taxes::setServiceTaxIds( $service->getId(), $snapshot['taxes'] );
        }
        foreach ( $snapshot['packages'] as $package_id => $fields ) {
            $package = Lib\Entities\Service::find( $package_id, false );
            if ( $package ) {
                $package->setFields( $fields )->save();
            }
        }
        foreach ( $snapshot['staff_services'] as $row ) {
            $staff_service = new Lib\Entities\StaffService();
            if ( $staff_service->load( $row['id'] ) ) {
                $staff_service->setFields( $row )->save();
            }
        }
    }

    /**
     * Packages whose service is the given simple service.
     *
     * @param int $service_id
     * @return int[]
     */
    protected static function packagesOf( $service_id )
    {
        return array_map( 'intval', Lib\Entities\Service::query( 's' )
            ->select( 's.id' )
            ->innerJoin( 'SubService', 'ss', 'ss.service_id = s.id' )
            ->where( 's.type', Lib\Entities\Service::TYPE_PACKAGE )
            ->where( 'ss.sub_service_id', $service_id )
            ->fetchCol( 's.id' ) );
    }
}
