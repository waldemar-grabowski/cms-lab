<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\Format;
use Bookly\Lib\AiAdmin\Knowledge;
use Bookly\Lib\AiAdmin\Rules;
use Bookly\Lib\AiAdmin\Services\ServiceAction;
use Bookly\Lib\AiAdmin\State;
use Bookly\Lib\Entities\CustomerAppointment;

/**
 * set_customer_group — create a customer group, change the given fields of one, or change the
 * settings for customers without a group (Customer Groups add-on,
 * assets/ai-setup/actions/set_customer_group.yaml). The group is written field by field, not with
 * the dialog's save, which writes the whole form.
 */
class SetCustomerGroup extends ServiceAction implements ActionInterface
{
    const GENERAL = 'general';

    /** @var string[] */
    protected static $arguments = array( 'group_id', 'general_settings', 'name', 'description', 'appointment_status', 'discount', 'skip_payment', 'gateways' );

    /** Fields a group and the general settings share. */
    protected static $settings = array( 'appointment_status', 'discount', 'skip_payment', 'gateways' );

    protected function name()
    {
        return 'set_customer_group';
    }

    /**
     * Whether the add-on (with Pro) is active.
     *
     * @return bool
     */
    public static function active()
    {
        return Lib\Config::proActive() && Lib\Config::customerGroupsActive();
    }

    /**
     * Gateways as stored (JSON string of a group, array of the general settings, null) →
     * "default" or the list of ids.
     *
     * @param mixed $stored
     * @return string|array
     */
    public static function gateways( $stored )
    {
        if ( $stored === null || $stored === '' ) {
            return 'default';
        }
        $list = is_array( $stored ) ? $stored : json_decode( $stored, true );

        return is_array( $list ) ? array_values( $list ) : 'default';
    }

    /**
     * Groups after the earlier items: id|"$N" => array( id, name, description, appointment_status,
     * discount, skip_payment, gateways, customers ), gateways "default" or a list.
     *
     * @param State|null $state
     * @return array
     */
    public static function groups( $state = null )
    {
        if ( $state && $state->has( 'list:customer_groups' ) ) {
            return $state->get( 'list:customer_groups' );
        }
        $groups = array();
        if ( self::active() ) {
            foreach ( (array) Lib\Proxy\CustomerGroups::getGroupRows() as $id => $row ) {
                $row['description'] = (string) $row['description'];
                $row['appointment_status'] = (string) $row['appointment_status'];
                $row['discount'] = (string) $row['discount'];
                $row['gateways'] = self::gateways( $row['gateways'] );
                $groups[ $id ] = $row;
            }
        }

        return $groups;
    }

    /**
     * Settings for customers without a group after the earlier items.
     *
     * @param State|null $state
     * @return array array( appointment_status, discount, skip_payment, gateways, customers )
     */
    public static function general( $state = null )
    {
        if ( $state && $state->has( 'customer_groups:general' ) ) {
            return $state->get( 'customer_groups:general' );
        }
        $option = get_option( 'bookly_customer_groups_general_settings', array() );
        $option = is_array( $option ) ? $option : array();

        return array(
            'appointment_status' => isset( $option['status'] ) ? (string) $option['status'] : '',
            'discount' => isset( $option['discount'] ) && $option['discount'] !== '' ? (string) $option['discount'] : '0',
            'skip_payment' => isset( $option['skip_payment'] ) ? (int) $option['skip_payment'] : 0,
            'gateways' => self::gateways( isset( $option['gateways'] ) ? $option['gateways'] : null ),
            'customers' => Lib\Entities\Customer::query()->where( 'group_id', null )->count(),
        );
    }

    /**
     * Whether $id is an existing group or "$N" of one created earlier.
     *
     * @param mixed $id
     * @param State $state
     * @return bool
     */
    public static function groupExists( $id, State $state )
    {
        if ( $state->isNew( $id ) === 'customer_group' ) {
            return true;
        }
        $groups = self::groups( $state );

        return ( is_int( $id ) || ( is_string( $id ) && ctype_digit( $id ) ) ) && isset( $groups[ (int) $id ] );
    }

    /**
     * @param mixed $id
     * @return int|string
     */
    public static function groupId( $id )
    {
        return is_string( $id ) && $id !== '' && $id[0] === '$' ? $id : (int) $id;
    }

    /**
     * Statuses the dialog offers: "" (Default) and the appointment statuses of the site.
     *
     * @return string[]
     */
    public static function statuses()
    {
        return array_merge( array( '' ), CustomerAppointment::getStatuses() );
    }

    /**
     * Target of the item: self::GENERAL, a group id, or the "$N" the item creates.
     *
     * @param array $args
     * @param State $state
     * @return int|string
     */
    protected static function target( array $args, State $state )
    {
        if ( ! empty( $args['general_settings'] ) ) {
            return self::GENERAL;
        }

        return isset( $args['group_id'] ) ? self::groupId( $args['group_id'] ) : self::newRef( $state );
    }

    /**
     * @inheritDoc
     */
    public function validate( array $args, State $state )
    {
        if ( $error = $this->forbidden() ) {
            return array( $error );
        }
        if ( ! self::active() ) {
            return array( 'addon_inactive: customer groups need Bookly Pro and the Customer Groups add-on' );
        }
        foreach ( array_keys( $args ) as $key ) {
            if ( ! in_array( $key, self::$arguments, true ) ) {
                return array( 'arguments: unknown argument ' . $key . ' — the action takes ' . implode( ', ', self::$arguments ) );
            }
        }
        $errors = array();
        $general = array_key_exists( 'general_settings', $args );
        if ( $general ) {
            if ( $args['general_settings'] !== true ) {
                return array( 'target_conflict: general_settings is only true; omit it to change a group' );
            }
            foreach ( array( 'group_id', 'name', 'description' ) as $key ) {
                if ( array_key_exists( $key, $args ) ) {
                    $errors[] = 'target_conflict: ' . $key . ' is not given with general_settings — the settings for customers without a group have no ' . ( $key === 'group_id' ? 'group' : $key );
                }
            }
            if ( $errors ) {
                return $errors;
            }
        }
        $new = ! $general && ! isset( $args['group_id'] );
        if ( ! $general && ! $new && ! self::groupExists( $args['group_id'], $state ) ) {
            return array( 'group_not_found: ' . wp_json_encode( $args['group_id'] ) . ' — ids are in list_customer_groups.groups' );
        }
        $target = self::target( $args, $state );
        $groups = self::groups( $state );
        $current = $general ? self::general( $state ) : ( $new ? self::blank() : $groups[ $target ] );

        if ( array_key_exists( 'name', $args ) || $new ) {
            $name = isset( $args['name'] ) && is_string( $args['name'] ) ? trim( $args['name'] ) : '';
            if ( $name === '' ) {
                $errors[] = 'name_required: ' . ( $new ? 'a new group needs a name' : 'the name must not be empty' );
            } elseif ( mb_strlen( $name ) > 255 ) {
                $errors[] = 'name_too_long: the name is at most 255 characters';
            } else {
                foreach ( $groups as $id => $group ) {
                    if ( (string) $id !== (string) $target && mb_strtolower( trim( $group['name'] ) ) === mb_strtolower( $name ) ) {
                        $errors[] = 'name_duplicate: the group "' . $group['name'] . '" ('
                            . ( is_int( $id ) ? 'id ' . $id : 'created by item ' . substr( $id, 1 ) . ' of this proposal' )
                            . ') already has this name';
                        break;
                    }
                }
            }
        }
        if ( array_key_exists( 'description', $args ) && ! is_string( $args['description'] ) ) {
            $errors[] = 'arguments: description is a string';
        }
        if ( array_key_exists( 'appointment_status', $args ) && ! in_array( $args['appointment_status'], self::statuses(), true ) ) {
            $errors[] = 'status_not_allowed: ' . wp_json_encode( $args['appointment_status'] ) . ' — "" (Default) or one of ' . implode( ', ', CustomerAppointment::getStatuses() );
        }
        if ( array_key_exists( 'discount', $args ) && ( $error = self::discountError( $args['discount'] ) ) ) {
            $errors[] = $error;
        }
        if ( array_key_exists( 'skip_payment', $args ) && ! in_array( $args['skip_payment'], array( 0, 1 ), true ) ) {
            $errors[] = 'skip_payment_value: skip_payment is 0 or 1';
        }
        if ( array_key_exists( 'gateways', $args ) ) {
            $value = $args['gateways'];
            if ( $value !== 'default' ) {
                $allowed = array_keys( Lib\Utils\Common::getGateways() );
                if ( ! is_array( $value ) || ! $value ) {
                    $errors[] = 'gateways_unknown: gateways is "default" or a non-empty list of ids from list_customer_groups.allowed.gateways';
                } else {
                    if ( count( array_unique( array_map( 'strval', $value ) ) ) !== count( $value ) ) {
                        $errors[] = 'gateways_unknown: a payment method is listed twice';
                    }
                    foreach ( $value as $gateway ) {
                        if ( ! is_string( $gateway ) || ! in_array( $gateway, $allowed, true ) ) {
                            $errors[] = 'gateways_unknown: ' . wp_json_encode( $gateway ) . ' — enabled payment methods: ' . implode( ', ', $allowed );
                        }
                    }
                }
            }
        }
        if ( ! $errors && ! $new ) {
            $after = self::after( $args, $current );
            if ( wp_json_encode( $after ) === wp_json_encode( $current ) ) {
                $errors[] = 'nothing_to_change: every given field already has this value';
            }
        }
        if ( ! $errors ) {
            $this->record( $args, $state );
        }

        return $errors;
    }

    /**
     * Discount check (discount_format), or null when the value is valid.
     *
     * @param mixed $discount
     * @return string|null
     */
    protected static function discountError( $discount )
    {
        if ( ! is_string( $discount ) || strlen( $discount ) > 100 || ! preg_match( '/^[0-9]+(\.[0-9]+)?%?$/', $discount ) ) {
            return 'discount_format: ' . wp_json_encode( $discount ) . ' — a number with a dot ("10", "7.5") or a number with "%" ("10%"); "0" for no discount';
        }
        if ( substr( $discount, -1 ) === '%' && (float) $discount > 100 ) {
            return 'discount_format: ' . $discount . ' — a percentage is at most 100%';
        }

        return null;
    }

    /**
     * Values of a group the dialog creates before the given fields.
     *
     * @return array
     */
    protected static function blank()
    {
        return array(
            'id' => null,
            'name' => '',
            'description' => '',
            'appointment_status' => '',
            'discount' => '0',
            'skip_payment' => 0,
            'gateways' => 'default',
            'customers' => 0,
        );
    }

    /**
     * Values after the item.
     *
     * @param array $args
     * @param array $current
     * @return array
     */
    protected static function after( array $args, array $current )
    {
        foreach ( array( 'name', 'description' ) as $key ) {
            if ( array_key_exists( $key, $args ) ) {
                $current[ $key ] = trim( $args[ $key ] );
            }
        }
        foreach ( self::$settings as $key ) {
            if ( array_key_exists( $key, $args ) ) {
                $value = $args[ $key ];
                if ( $key === 'skip_payment' ) {
                    $value = (int) $value;
                } elseif ( $key === 'gateways' && is_array( $value ) ) {
                    $value = array_values( $value );
                }
                $current[ $key ] = $value;
            }
        }

        return $current;
    }

    /**
     * @param array $args
     * @param State $state
     */
    protected function record( array $args, State $state )
    {
        $target = self::target( $args, $state );
        if ( $target === self::GENERAL ) {
            $state->set( 'customer_groups:general', self::after( $args, self::general( $state ) ) );

            return;
        }
        $groups = self::groups( $state );
        if ( ! isset( $args['group_id'] ) ) {
            $state->created( 'customer_group' );
            $groups[ $target ] = array_merge( self::blank(), array( 'id' => $target ) );
        }
        $groups[ $target ] = self::after( $args, $groups[ $target ] );
        $state->set( 'list:customer_groups', $groups );
    }

    /**
     * Value of a field as the card shows it.
     *
     * @param string $field
     * @param mixed  $value
     * @return string
     */
    protected static function text( $field, $value )
    {
        switch ( $field ) {
            case 'appointment_status':
                return $value === '' ? Knowledge::label( 'Default' ) : CustomerAppointment::statusToString( $value );
            case 'gateways':
                if ( $value === 'default' ) {
                    return Knowledge::label( 'Default' );
                }
                $titles = Lib\Utils\Common::getGateways();
                $names = array();
                foreach ( (array) $value as $gateway ) {
                    $names[] = isset( $titles[ $gateway ] ) ? $titles[ $gateway ] : $gateway;
                }

                return $names ? implode( ', ', $names ) : '—';
            case 'skip_payment':
                return $value ? __( 'Enabled', 'bookly-responsive-appointment-booking-tool' ) : __( 'Disabled', 'bookly-responsive-appointment-booking-tool' );
            case 'description':
                return $value === '' ? '—' : wp_trim_words( wp_strip_all_tags( $value ), 20 );
        }

        return $value === '' ? '—' : (string) $value;
    }

    /**
     * @inheritDoc
     */
    public function preview( array $args, State $state )
    {
        $target = self::target( $args, $state );
        $general = $target === self::GENERAL;
        $new = ! $general && ! isset( $args['group_id'] );
        $groups = self::groups( $state );
        $current = $general ? self::general( $state ) : ( $new ? self::blank() : $groups[ $target ] );
        $after = self::after( $args, $current );
        $object = Format::object(
            'customer_group:' . $target,
            $general ? __( 'Customers without a group', 'bookly-responsive-appointment-booking-tool' ) : $after['name'],
            __( 'New customer group', 'bookly-responsive-appointment-booking-tool' ),
            $new
        );
        $rows = array();
        $warnings = array();
        $changed = array();
        foreach ( array_merge( array( 'name', 'description' ), self::$settings ) as $field ) {
            if ( ! array_key_exists( $field, $args ) ) {
                continue;
            }
            if ( ! $new && wp_json_encode( $after[ $field ] ) === wp_json_encode( $current[ $field ] ) ) {
                continue;
            }
            $changed[] = $field;
            $rule = Access::rule( 'field:customer_group.' . $field );
            $rows[] = array(
                'label' => Format::rowLabel( $object, Format::label( $rule, $field ), array( 'name' => $field === 'name' && $new ) ),
                'before' => $new ? '—' : self::text( $field, $current[ $field ] ),
                'after' => self::text( $field, $after[ $field ] ),
                'link' => Format::link( $rule ),
            );
            foreach ( Format::warnings( $rule, $new ? null : $current[ $field ], $after[ $field ], $state ) as $text ) {
                $warnings[] = $text;
            }
        }
        $warnings = array_merge( $warnings, self::effects( $changed, $after, $general ) );
        $actions = Rules::actions();
        if ( array_key_exists( 'discount', $args ) && isset( $actions[ $this->name() ] ) ) {
            $warnings = array_merge( $warnings, Format::warnings( $actions[ $this->name() ], $new ? null : $current['discount'], $after['discount'], $state ) );
        }
        if ( $general ) {
            $warnings[] = sprintf( _n( '%d customer has no group.', '%d customers have no group.', $current['customers'], 'bookly-responsive-appointment-booking-tool' ), $current['customers'] );
        } elseif ( ! $new ) {
            $warnings[] = sprintf( _n( '%d customer is in this group.', '%d customers are in this group.', $current['customers'], 'bookly-responsive-appointment-booking-tool' ), $current['customers'] );
        } else {
            $warnings[] = __( 'The new group has no customers: customers are put into it in Customers → Edit customer → Group.', 'bookly-responsive-appointment-booking-tool' );
        }
        if ( $after['skip_payment'] && ( $after['discount'] !== '0' || $after['gateways'] !== 'default' ) ) {
            $warnings[] = __( 'Skip payment step is on: the discount and the payment methods are stored, but the dialog hides them while the box is ticked.', 'bookly-responsive-appointment-booking-tool' );
        }
        $this->record( $args, $state );

        return array( 'rows' => $rows, 'warnings' => array_values( array_unique( $warnings ) ) );
    }

    /**
     * What follows from the changed fields, one line each.
     *
     * @param string[] $changed
     * @param array    $after
     * @param bool     $general
     * @return string[]
     */
    protected static function effects( array $changed, array $after, $general )
    {
        $lines = array();
        if ( in_array( 'appointment_status', $changed, true ) ) {
            $lines[] = $after['appointment_status'] === ''
                ? __( 'New appointments of these customers get the default appointment status of the site.', 'bookly-responsive-appointment-booking-tool' )
                : sprintf( __( 'New appointments of these customers get the status "%s".', 'bookly-responsive-appointment-booking-tool' ), CustomerAppointment::statusToString( $after['appointment_status'] ) );
        }
        if ( in_array( 'discount', $changed, true ) && $after['discount'] !== '0' ) {
            $lines[] = __( 'The discount is taken off the cart total after coupons; the total never goes below 0.', 'bookly-responsive-appointment-booking-tool' );
        }
        if ( in_array( 'skip_payment', $changed, true ) && ! $after['skip_payment'] ) {
            $lines[] = __( 'These customers are asked to pay again in the booking form.', 'bookly-responsive-appointment-booking-tool' );
        }
        if ( in_array( 'gateways', $changed, true ) ) {
            $lines[] = __( 'A payment method is offered only if the group, the service and the staff member all allow it.', 'bookly-responsive-appointment-booking-tool' );
        }
        if ( $general && $changed ) {
            $lines[] = __( 'These settings apply to customers without a group and to guests the booking form does not match to a customer.', 'bookly-responsive-appointment-booking-tool' );
        }

        return $lines;
    }

    /**
     * @inheritDoc
     */
    public function apply( array $args )
    {
        if ( ! empty( $args['general_settings'] ) ) {
            $before = get_option( 'bookly_customer_groups_general_settings', array() );
            $current = self::general();
            $after = self::after( $args, $current );
            update_option( 'bookly_customer_groups_general_settings', array(
                'status' => $after['appointment_status'],
                'skip_payment' => $after['skip_payment'],
                'gateways' => $after['gateways'] === 'default' ? null : $after['gateways'],
                'discount' => $after['discount'],
            ) );

            return array( 'general' => true, 'option' => $before );
        }
        $new = ! isset( $args['group_id'] );
        $groups = self::groups();
        $fields = array();
        if ( ! $new ) {
            $fields['id'] = (int) $args['group_id'];
            if ( ! isset( $groups[ $fields['id'] ] ) ) {
                return array();
            }
        }
        $given = array();
        foreach ( array_merge( array( 'name', 'description' ), self::$settings ) as $field ) {
            if ( array_key_exists( $field, $args ) ) {
                $given[] = $field;
            }
        }
        $before = array();
        if ( ! $new ) {
            $stored = self::stored( $groups[ $fields['id'] ] );
            foreach ( $given as $field ) {
                $before[ $field ] = $stored[ $field ];
            }
        }
        $after = self::stored( self::after( $args, $new ? self::blank() : $groups[ $fields['id'] ] ) );
        foreach ( $given as $field ) {
            $fields[ $field ] = $after[ $field ];
        }
        $id = Lib\Proxy\CustomerGroups::saveGroup( $fields );
        $groups = self::groups();
        $snapshot = array(
            'group_id' => $id,
            'created' => $new,
            'before' => $before,
            'after' => isset( $groups[ $id ] ) ? self::stored( $groups[ $id ] ) : array(),
        );
        if ( $new ) {
            $snapshot['created_id'] = $id;
        }

        return $snapshot;
    }

    /**
     * Fields of a group as saveGroup() takes them (gateways as stored).
     *
     * @param array $group see groups()
     * @return array
     */
    protected static function stored( array $group )
    {
        return array(
            'name' => $group['name'],
            'description' => $group['description'],
            'appointment_status' => $group['appointment_status'],
            'discount' => $group['discount'],
            'skip_payment' => (int) $group['skip_payment'],
            'gateways' => $group['gateways'] === 'default' ? null : json_encode( $group['gateways'] ),
        );
    }

    /**
     * Return the group's values while it is as the action left it; delete a created group while
     * no customer is in it. The general settings get their previous value back.
     *
     * @inheritDoc
     */
    public function undo( array $snapshot )
    {
        if ( ! empty( $snapshot['general'] ) ) {
            update_option( 'bookly_customer_groups_general_settings', $snapshot['option'] );

            return;
        }
        if ( empty( $snapshot['group_id'] ) || ! self::active() ) {
            return;
        }
        $groups = self::groups();
        $id = $snapshot['group_id'];
        if ( ! isset( $groups[ $id ] ) || wp_json_encode( self::stored( $groups[ $id ] ) ) !== wp_json_encode( $snapshot['after'] ) ) {
            return;
        }
        if ( $snapshot['created'] ) {
            if ( $groups[ $id ]['customers'] === 0 ) {
                Lib\Proxy\CustomerGroups::deleteGroup( $id );
            }

            return;
        }
        Lib\Proxy\CustomerGroups::saveGroup( array_merge( array( 'id' => $id ), $snapshot['before'] ) );
    }
}
