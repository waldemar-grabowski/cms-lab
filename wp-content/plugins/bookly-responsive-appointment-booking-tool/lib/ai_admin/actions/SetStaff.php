<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\AllowedValues;
use Bookly\Lib\AiAdmin\Constraints;
use Bookly\Lib\AiAdmin\Format;
use Bookly\Lib\AiAdmin\Knowledge;
use Bookly\Lib\AiAdmin\StaffData;
use Bookly\Lib\AiAdmin\State;

/**
 * set_staff — change the given profile fields of one staff member: Details (core + Pro) and
 * Advanced (Pro: working time limit, time zone) of Edit staff member
 * (assets/ai-setup/actions/set_staff.yaml).
 */
class SetStaff implements ActionInterface
{
    /** Fields shown only with Pro. */
    protected static $pro_fields = array( 'category_id', 'gateways', 'time_zone', 'working_time_limit' );

    /**
     * @return string[]
     */
    protected static function fields()
    {
        return array( 'full_name', 'email', 'phone', 'info', 'color', 'visibility', 'category_id', 'gateways', 'time_zone', 'working_time_limit' );
    }

    /**
     * @inheritDoc
     */
    public function validate( array $args, State $state )
    {
        $staff_id = isset( $args['staff_id'] ) ? $args['staff_id'] : null;
        if ( ! StaffData::exists( $staff_id, $state ) ) {
            return array( 'staff_not_found: ' . wp_json_encode( $staff_id ) );
        }
        $fields = isset( $args['fields'] ) && is_array( $args['fields'] ) ? $args['fields'] : array();
        if ( ! $fields ) {
            return array( 'fields: at least one field to change' );
        }
        $errors  = array();
        $current = StaffData::profile( $staff_id, $state );
        $changed = false;
        foreach ( $fields as $field => $value ) {
            if ( ! in_array( $field, self::fields(), true ) ) {
                $errors[] = 'fields: unknown field ' . $field;
                continue;
            }
            $ref = 'field:staff.' . $field;
            if ( $error = StaffData::forbidden( $ref ) ) {
                $errors[] = $error;
                continue;
            }
            $rule   = Access::rule( $ref );
            $before = $current[ $field ];
            if ( ! Lib\Config::proActive() && (
                    in_array( $field, self::$pro_fields, true )
                    || ( $field === 'visibility' && $value === 'archive' && $before !== 'archive' ) ) ) {
                $errors[] = 'pro_required: ' . $field . ( $field === 'visibility' ? ' = archive' : '' ) . ' needs Pro';
                continue;
            }
            switch ( $field ) {
                case 'full_name':
                case 'email':
                    $text = is_string( $value ) ? trim( $value ) : '';
                    foreach ( Constraints::failed( $rule, $text, $before, $state->reader() ) as $failed ) {
                        $errors[] = $failed;
                    }
                    if ( $text === '' ) {
                        $id = $field === 'full_name' ? 'name_required' : 'email_required';
                        if ( ! preg_grep( '/^' . $id . '/', $errors ) ) {
                            $errors[] = $id . ': ' . $field . ' is empty';
                        }
                    } elseif ( $field === 'email' && ! is_email( $text ) ) {
                        $errors[] = 'email_format: ' . $text . ' is not a valid address';
                    } elseif ( strlen( $text ) > 255 ) {
                        $errors[] = 'fields: ' . $field . ' is at most 255 characters';
                    }
                    $value = $text;
                    break;
                case 'phone':
                    if ( $error = CreateStaff::phoneError( $value, $state ) ) {
                        $errors[] = $error;
                    }
                    $value = is_string( $value ) ? trim( $value ) : $value;
                    break;
                case 'info':
                    if ( ! is_string( $value ) ) {
                        $errors[] = 'fields: info is a string';
                    }
                    break;
                case 'color':
                    if ( ! is_string( $value ) || ! preg_match( '/^#[0-9a-fA-F]{6}$/', $value ) ) {
                        $errors[] = 'color_hex: ' . wp_json_encode( $value ) . ' — "#RRGGBB"';
                    }
                    break;
                case 'visibility':
                    if ( ! AllowedValues::contains( $value, AllowedValues::of( $rule, $before ) ) ) {
                        $errors[] = 'fields: visibility is public, private or archive';
                    }
                    break;
                case 'category_id':
                    if ( $value !== null && $state->isNew( $value ) !== 'staff_category'
                        && ( ! is_numeric( $value ) || ! AllowedValues::contains( (int) $value, AllowedValues::of( $rule, null ) ) ) ) {
                        $errors[] = 'category_not_found: ' . wp_json_encode( $value );
                    }
                    break;
                case 'gateways':
                    if ( $value !== 'default' ) {
                        $allowed = AllowedValues::of( $rule, null );
                        if ( ! is_array( $value ) || ! $value ) {
                            $errors[] = 'gateway_unknown: gateways is "default" or a non-empty list of payment method ids';
                        } else {
                            foreach ( $value as $gateway ) {
                                if ( $gateway === null || ! AllowedValues::contains( $gateway, $allowed ) ) {
                                    $errors[] = 'gateway_unknown: ' . wp_json_encode( $gateway );
                                }
                            }
                            $value = array_values( array_unique( $value ) );
                        }
                    }
                    break;
                case 'time_zone':
                    if ( $value !== null && ! in_array( (string) $value, StaffData::timeZones(), true ) ) {
                        $errors[] = 'time_zone_unknown: ' . wp_json_encode( $value );
                    }
                    break;
                case 'working_time_limit':
                    if ( $value !== null && ! AllowedValues::contains( $value, AllowedValues::of( $rule, $before ) ) ) {
                        $errors[] = 'working_time_limit_not_allowed: ' . wp_json_encode( $value ) . ' — null or whole hours 3600…82800 seconds';
                    }
                    break;
            }
            if ( wp_json_encode( $value ) !== wp_json_encode( $before ) ) {
                $changed = true;
            }
            $state->set( StaffData::ref( $field, $staff_id ), $value );
        }
        if ( ! $errors && ! $changed ) {
            $errors[] = 'nothing_to_change: every given field already has this value';
        }

        return $errors;
    }

    /**
     * @inheritDoc
     */
    public function preview( array $args, State $state )
    {
        $staff_id = $args['staff_id'];
        $current  = StaffData::profile( $staff_id, $state );
        $rows     = array();
        $warnings = array();
        foreach ( $args['fields'] as $field => $value ) {
            if ( in_array( $field, array( 'full_name', 'email', 'phone' ), true ) ) {
                $value = trim( $value );
            }
            $before = $current[ $field ];
            if ( wp_json_encode( $value ) === wp_json_encode( $before ) ) {
                continue;
            }
            $rule   = Access::rule( 'field:staff.' . $field );
            $rows[] = array(
                'label'  => StaffData::rowLabel( $staff_id, $state, Format::label( $rule, $field ) ),
                'before' => self::text( $field, $before, $rule, $state ),
                'after'  => self::text( $field, $value, $rule, $state ),
                'link'   => Format::link( $rule ),
            );
            foreach ( Format::warnings( $rule, $before, $value, $state ) as $text ) {
                $warnings[] = $text;
            }
            if ( $field === 'visibility' ) {
                $warnings = array_merge( $warnings, self::visibilityWarnings( $staff_id, $state, $value ) );
            }
            if ( $field === 'full_name' ) {
                $same = StaffData::sameNameWarning( $value, is_numeric( $staff_id ) ? (int) $staff_id : null );
                if ( $same !== null ) {
                    $warnings[] = $same;
                }
            }
            $state->set( StaffData::ref( $field, $staff_id ), $value );
        }

        return array( 'rows' => $rows, 'warnings' => array_values( array_unique( $warnings ) ) );
    }

    /**
     * @inheritDoc
     */
    public function apply( array $args )
    {
        $staff = Lib\Entities\Staff::find( (int) $args['staff_id'], false );
        if ( ! $staff ) {
            return array();
        }
        $db       = $staff->getFields();
        $snapshot = array( 'staff_id' => (int) $staff->getId(), 'fields' => array() );
        $set      = array();
        foreach ( $args['fields'] as $field => $value ) {
            switch ( $field ) {
                case 'full_name':
                case 'email':
                case 'phone':
                    $value = trim( $value );
                    break;
                case 'gateways':
                    $value = $value === 'default' ? null : json_encode( array_values( $value ) );
                    break;
                case 'category_id':
                case 'working_time_limit':
                    $value = $value === null ? null : (int) $value;
                    break;
            }
            $snapshot['fields'][ $field ] = $db[ $field ];
            $set[ $field ]                = $value;
        }
        $staff->setFields( $set )->save();

        return $snapshot;
    }

    /**
     * @inheritDoc
     */
    public function undo( array $snapshot )
    {
        if ( empty( $snapshot['staff_id'] ) ) {
            return;
        }
        $staff = Lib\Entities\Staff::find( $snapshot['staff_id'], false );
        if ( $staff ) {
            $staff->setFields( $snapshot['fields'] )->save();
        }
    }

    /**
     * Value of a field as the card shows it.
     *
     * @param string $field
     * @param mixed  $value
     * @param array  $rule
     * @param State  $state
     * @return string
     */
    protected static function text( $field, $value, $rule, State $state )
    {
        switch ( $field ) {
            case 'category_id':
                return StaffData::categoryName( $value, $state );
            case 'gateways':
                if ( $value === 'default' || $value === null ) {
                    return Knowledge::label( 'Default' );
                }
                $titles   = Lib\Utils\Common::getGateways();
                $gateways = array();
                foreach ( (array) $value as $gateway ) {
                    $gateways[] = isset( $titles[ $gateway ] ) ? $titles[ $gateway ] : $gateway;
                }

                return implode( ', ', $gateways );
            case 'time_zone':
                return $value === null ? Knowledge::label( 'Default' ) : (string) $value;
            case 'working_time_limit':
                return $value === null ? Knowledge::label( 'Unlimited' ) : Format::value( $rule, $value );
            case 'info':
                return $value === '' || $value === null ? '—' : wp_trim_words( wp_strip_all_tags( $value ), 20 );
        }

        return Format::value( $rule, $value );
    }

    /**
     * Card notes of a visibility change: upcoming appointments when archiving, where the staff
     * member is shown, whether they are bookable at all.
     *
     * @param mixed  $staff_id
     * @param State  $state
     * @param string $visibility
     * @return string[]
     */
    protected static function visibilityWarnings( $staff_id, State $state, $visibility )
    {
        $warnings = array();
        switch ( $visibility ) {
            case 'archive':
                $warnings[] = __( 'Archived: hidden from booking forms, the AI chat and the staff lists; no notifications are sent to the staff member.', 'bookly-responsive-appointment-booking-tool' );
                if ( ! StaffData::isNew( $staff_id, $state ) ) {
                    $appointments = StaffData::futureAppointments( (int) $staff_id );
                    if ( $appointments ) {
                        $last       = end( $appointments );
                        $warnings[] = sprintf(
                            _n( '%1$d upcoming appointment (the last on %2$s) stays in the calendar; move it by hand if needed.', '%1$d upcoming appointments (the last on %2$s) stay in the calendar; move them by hand if needed.', count( $appointments ), 'bookly-responsive-appointment-booking-tool' ),
                            count( $appointments ),
                            Lib\Utils\DateTime::formatDate( $last['start'] )
                        );
                    }
                }
                break;
            case 'private':
                $warnings[] = __( 'Private: clients cannot book this staff member in booking forms and the AI chat; the admin calendar still shows them.', 'bookly-responsive-appointment-booking-tool' );
                break;
            case 'public':
                $warnings[] = __( 'Public: clients can book this staff member in booking forms and the AI chat.', 'bookly-responsive-appointment-booking-tool' );
                $working = false;
                foreach ( StaffData::schedule( $staff_id, $state ) as $day ) {
                    if ( is_array( $day ) ) {
                        $working = true;
                    }
                }
                if ( ! StaffData::services( $staff_id, $state ) ) {
                    $warnings[] = __( 'Still not bookable: the staff member performs no services.', 'bookly-responsive-appointment-booking-tool' );
                }
                if ( ! $working ) {
                    $warnings[] = __( 'Still not bookable: every weekday is off.', 'bookly-responsive-appointment-booking-tool' );
                }
                break;
        }

        return $warnings;
    }
}
