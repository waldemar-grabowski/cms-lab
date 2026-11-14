<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\Format;
use Bookly\Lib\AiAdmin\Knowledge;
use Bookly\Lib\AiAdmin\Services\ServiceAction;
use Bookly\Lib\AiAdmin\State;

/**
 * set_customer — create a customer or change the given fields of one, as New / Edit customer
 * does (assets/ai-setup/actions/set_customer.yaml). Tags and own fields take only the change.
 * The customer is saved with the whole set of its values (CustomerData::write()): the dialog's
 * save clears whatever the request does not carry.
 */
class SetCustomer extends ServiceAction implements ActionInterface
{
    /** @var string[] */
    protected static $arguments = array(
        'customer_id', 'full_name', 'first_name', 'last_name', 'phone', 'email', 'birthday',
        'country', 'state', 'postcode', 'city', 'street', 'street_number', 'additional_address',
        'notes', 'group_id', 'tags', 'info_fields',
    );

    /** Fields of the card in the order of the dialog. */
    protected static $order = array(
        'full_name', 'first_name', 'last_name', 'phone', 'email', 'tags', 'birthday',
        'country', 'state', 'postcode', 'city', 'street', 'street_number', 'additional_address',
        'info_fields', 'group_id', 'notes',
    );

    protected function name()
    {
        return 'set_customer';
    }

    /**
     * @inheritDoc
     */
    public function validate( array $args, State $state )
    {
        if ( $error = $this->forbidden() ) {
            return array( $error );
        }
        foreach ( array_keys( $args ) as $key ) {
            if ( ! in_array( $key, self::$arguments, true ) ) {
                return array( 'arguments: unknown argument ' . $key . ' — the action takes ' . implode( ', ', self::$arguments ) );
            }
        }
        $new = ! isset( $args['customer_id'] );
        if ( ! $new && ! CustomerData::exists( $args['customer_id'], $state ) ) {
            return array( 'customer_not_found: ' . wp_json_encode( $args['customer_id'] ) . ' — ids are in find_customers' );
        }
        $ref = $new ? self::newRef( $state ) : CustomerData::id( $args['customer_id'] );
        $current = $new ? CustomerData::blank() : CustomerData::values( $ref, $state );
        list( $after, $changed, $errors ) = self::resolve( $args, $current, $new, $ref, $state );
        if ( ! $errors && ! $new && ! $changed ) {
            $errors[] = 'nothing_to_change: every given field already has this value';
        }
        if ( ! $errors ) {
            if ( $new ) {
                $state->created( 'customer' );
            }
            CustomerData::record( $state, $ref, $after );
        }

        return $errors;
    }

    /**
     * Values of the customer after the item, the fields it changes and the failed rules.
     *
     * @param array      $args
     * @param array      $current
     * @param bool       $new
     * @param int|string $ref
     * @param State      $state
     * @return array array( values, changed fields, errors )
     */
    protected static function resolve( array $args, array $current, $new, $ref, State $state )
    {
        $errors = array();
        $after = $current;
        $mode = CustomerData::nameMode();
        $pro = Lib\Config::proActive();

        // Name.
        $parts = $mode === 'full_name' ? array( 'full_name' => 128 ) : array( 'first_name' => 64, 'last_name' => 64 );
        foreach ( array( 'full_name', 'first_name', 'last_name' ) as $field ) {
            if ( array_key_exists( $field, $args ) && ! isset( $parts[ $field ] ) ) {
                $errors[] = 'name_mode: customers are entered with ' . ( $mode === 'full_name' ? 'one full name — give full_name' : 'first and last name — give first_name and last_name' ) . ', not ' . $field;
            }
        }
        foreach ( $parts as $field => $max ) {
            if ( ! array_key_exists( $field, $args ) ) {
                $error = 'name_required: a new customer needs ' . ( $mode === 'full_name' ? 'full_name' : 'first_name and last_name' );
                if ( $new && ! in_array( $error, $errors, true ) ) {
                    $errors[] = $error;
                }
                continue;
            }
            $value = is_string( $args[ $field ] ) ? trim( $args[ $field ] ) : '';
            if ( $value === '' ) {
                $errors[] = 'name_required: ' . $field . ' is empty';
            } elseif ( mb_strlen( $value ) > $max ) {
                $errors[] = 'name_too_long: ' . $field . ' is at most ' . $max . ' characters';
            }
            $after[ $field ] = $value;
        }
        if ( $mode === 'first_last_name' && mb_strlen( $after['first_name'] ) + 1 + mb_strlen( $after['last_name'] ) > 128 ) {
            $errors[] = 'name_too_long: "first last" is saved as the full name and must fit 128 characters';
        }
        if ( $mode === 'full_name' && $after['full_name'] !== $current['full_name'] ) {
            // Customer::save() cuts the first and last name from the full name.
            $split = explode( ' ', $after['full_name'], 2 );
            $after['first_name'] = $split[0];
            $after['last_name'] = isset( $split[1] ) ? trim( $split[1] ) : '';
        } elseif ( $mode === 'first_last_name' ) {
            $after['full_name'] = trim( rtrim( $after['first_name'] ) . ' ' . ltrim( $after['last_name'] ) );
        }

        // Contacts.
        if ( array_key_exists( 'phone', $args ) ) {
            $phone = is_string( $args['phone'] ) ? trim( $args['phone'] ) : null;
            if ( $phone === null ) {
                $errors[] = 'phone_format: phone is a string';
            } elseif ( strlen( $phone ) > 32 ) {
                $errors[] = 'phone_format: the phone is at most 32 characters';
            } elseif ( $phone !== '' && CustomerData::phoneFormat() === 'international' && ! preg_match( '/^\+[0-9]+$/', $phone ) ) {
                $errors[] = 'phone_format: ' . $phone . ' — the phone field takes "+" and digits with the country code ("+12025550101")';
            } else {
                $after['phone'] = $phone;
            }
        }
        if ( array_key_exists( 'email', $args ) ) {
            $email = is_string( $args['email'] ) ? trim( $args['email'] ) : null;
            if ( $email === null || strlen( $email ) > 128 || ( $email !== '' && ! is_email( $email ) ) ) {
                $errors[] = 'email_format: ' . wp_json_encode( $args['email'] ) . ' is not a valid email address (at most 128 characters)';
            } else {
                $after['email'] = $email;
            }
        }
        if ( ! get_option( 'bookly_cst_allow_duplicates' ) ) {
            foreach ( array( 'email', 'phone' ) as $field ) {
                if ( array_key_exists( $field, $args ) && $after[ $field ] !== '' && $after[ $field ] !== $current[ $field ] ) {
                    $other = CustomerData::duplicate( $field, $after[ $field ], $ref, $state );
                    if ( $other !== null ) {
                        $errors[] = 'contact_duplicate: the ' . $field . ' ' . $after[ $field ] . ' belongs to the customer ' . $other . ' — with Allow duplicate customers disabled the booking form matches customers by it';
                    }
                }
            }
        }

        // Pro fields.
        if ( array_key_exists( 'birthday', $args ) ) {
            $birthday = $args['birthday'];
            if ( ! $pro ) {
                $errors[] = 'birthday_invalid: date of birth needs Bookly Pro';
            } elseif ( $birthday !== null ) {
                $today = date_create( current_time( 'Y-m-d' ) );
                $oldest = date_create( current_time( 'Y-m-d' ) )->modify( '-100 years' );
                if ( ! is_string( $birthday ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $birthday, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
                    $errors[] = 'birthday_invalid: ' . wp_json_encode( $birthday ) . ' — a real date YYYY-MM-DD';
                } elseif ( $birthday > $today->format( 'Y-m-d' ) || $birthday < $oldest->format( 'Y-m-d' ) ) {
                    $errors[] = 'birthday_invalid: ' . $birthday . ' — not in the future and not more than 100 years back';
                }
            }
            $after['birthday'] = $birthday;
        }
        $limits = CustomerData::addressLimits();
        $shown = CustomerData::addressParts();
        foreach ( $limits as $part => $max ) {
            if ( ! array_key_exists( $part, $args ) ) {
                continue;
            }
            $value = $args[ $part ];
            if ( ! $pro ) {
                $errors[] = 'address_part_hidden: ' . $part . ' needs Bookly Pro';
            } elseif ( ! in_array( $part, $shown, true ) ) {
                $errors[] = 'address_part_hidden: ' . $part . ' is not shown in the customer dialog (Settings → Customers → Customer\'s address fields); shown: ' . ( $shown ? implode( ', ', $shown ) : 'none' );
            } elseif ( ! is_string( $value ) || mb_strlen( trim( $value ) ) > $max ) {
                $errors[] = 'address_part_hidden: ' . $part . ' is text of at most ' . $max . ' characters';
            } else {
                $after[ $part ] = trim( $value );
            }
        }
        if ( array_key_exists( 'notes', $args ) ) {
            if ( ! is_string( $args['notes'] ) ) {
                $errors[] = 'arguments: notes is a string';
            } else {
                $after['notes'] = $args['notes'];
            }
        }

        // Group.
        if ( array_key_exists( 'group_id', $args ) ) {
            $group_id = $args['group_id'];
            if ( ! SetCustomerGroup::active() ) {
                $errors[] = 'group_not_found: customer groups need the Customer Groups add-on';
            } elseif ( $group_id !== null && ! SetCustomerGroup::groupExists( $group_id, $state ) ) {
                $errors[] = 'group_not_found: ' . wp_json_encode( $group_id ) . ' — ids are in list_customer_groups.groups; null for no group';
            } else {
                $after['group_id'] = $group_id === null ? null : SetCustomerGroup::groupId( $group_id );
            }
        }

        // Tags.
        if ( array_key_exists( 'tags', $args ) ) {
            list( $tags, $tag_errors ) = self::tags( $args['tags'], $current['tags'], $state );
            $errors = array_merge( $errors, $tag_errors );
            $after['tags'] = $tags;
        }

        // Own fields.
        if ( array_key_exists( 'info_fields', $args ) ) {
            list( $info, $info_errors ) = self::infoFields( $args['info_fields'], $current['info_fields'] );
            $errors = array_merge( $errors, $info_errors );
            $after['info_fields'] = $info;
        }

        $changed = array();
        foreach ( self::$order as $field ) {
            if ( array_key_exists( $field, $args ) && wp_json_encode( $after[ $field ] ) !== wp_json_encode( $current[ $field ] ) ) {
                $changed[] = $field;
            }
        }

        return array( $after, $changed, $errors );
    }

    /**
     * Tags of the customer after the change.
     *
     * @param mixed $change array( add?, remove? )
     * @param array $tags   the customer's tags now
     * @param State $state
     * @return array array( tags, errors )
     */
    protected static function tags( $change, array $tags, State $state )
    {
        if ( ! Lib\Config::proActive() ) {
            return array( $tags, array( 'tags_pro: tags need Bookly Pro' ) );
        }
        if ( ! is_array( $change ) || ! $change || array_diff( array_keys( $change ), array( 'add', 'remove' ) ) ) {
            return array( $tags, array( 'tags_pro: tags is {add: […], remove: […]} with at least one of them' ) );
        }
        $errors = array();
        $add = isset( $change['add'] ) ? $change['add'] : array();
        $remove = isset( $change['remove'] ) ? $change['remove'] : array();
        if ( ! is_array( $add ) || ! is_array( $remove ) ) {
            return array( $tags, array( 'tags_pro: add and remove are lists of tags' ) );
        }
        $catalog = self::tagCatalog( $state );
        $has = array();
        foreach ( $tags as $tag ) {
            $has[ mb_strtolower( $tag ) ] = $tag;
        }
        $adding = array();
        foreach ( $add as $tag ) {
            $tag = is_string( $tag ) ? trim( $tag ) : '';
            if ( $tag === '' || mb_strlen( $tag ) > 255 ) {
                $errors[] = 'tags_pro: a tag is a non-empty text of at most 255 characters';
                continue;
            }
            $key = mb_strtolower( $tag );
            if ( isset( $has[ $key ] ) ) {
                $errors[] = 'tag_already_present: the customer already has the tag "' . $has[ $key ] . '"';
            } elseif ( isset( $adding[ $key ] ) ) {
                $errors[] = 'tag_already_present: "' . $tag . '" is in tags.add twice';
            } else {
                $adding[ $key ] = isset( $catalog[ $key ] ) ? $catalog[ $key ] : $tag;
            }
        }
        $removing = array();
        foreach ( $remove as $tag ) {
            $key = is_string( $tag ) ? mb_strtolower( trim( $tag ) ) : '';
            if ( ! isset( $has[ $key ] ) ) {
                $errors[] = 'tag_not_present: the customer has no tag ' . wp_json_encode( $tag ) . ( $tags ? ' (tags: ' . implode( ', ', $tags ) . ')' : ' (no tags)' );
            } elseif ( isset( $adding[ $key ] ) ) {
                $errors[] = 'tag_not_present: "' . $tag . '" is both in tags.add and tags.remove';
            } else {
                $removing[ $key ] = true;
            }
        }
        $after = array();
        foreach ( $tags as $tag ) {
            if ( ! isset( $removing[ mb_strtolower( $tag ) ] ) ) {
                $after[] = $tag;
            }
        }

        return array( array_merge( $after, array_values( $adding ) ), $errors );
    }

    /**
     * Customer tags of the site and the ones added earlier in the proposal: lower case => spelling.
     *
     * @param State $state
     * @return array
     */
    protected static function tagCatalog( State $state )
    {
        $catalog = array();
        foreach ( CustomerData::tagRows() as $row ) {
            $catalog[ mb_strtolower( $row['tag'] ) ] = $row['tag'];
        }
        foreach ( $state->has( 'list:customers' ) ? $state->get( 'list:customers' ) : array() as $id ) {
            $values = $state->get( 'customer:' . $id );
            foreach ( $values['tags'] as $tag ) {
                if ( ! isset( $catalog[ mb_strtolower( $tag ) ] ) ) {
                    $catalog[ mb_strtolower( $tag ) ] = $tag;
                }
            }
        }

        return $catalog;
    }

    /**
     * Own-field values after the change.
     *
     * @param mixed $change array( add?, set?, remove? )
     * @param array $values list of array( id, value ) now
     * @return array array( values, errors )
     */
    protected static function infoFields( $change, array $values )
    {
        $defs = CustomerData::infoDefs();
        if ( ! $defs ) {
            return array( $values, array( 'info_field_unknown: ' . ( Lib\Config::customerInformationActive() ? 'the site has no Customer Information fields' : 'own customer fields need the Customer Information add-on' ) ) );
        }
        if ( ! is_array( $change ) || ! $change || array_diff( array_keys( $change ), array( 'add', 'set', 'remove' ) ) ) {
            return array( $values, array( 'info_field_wrong_side: info_fields is {add: […], set: […], remove: […]} with at least one of them' ) );
        }
        $now = array();
        foreach ( $values as $field ) {
            $now[ $field['id'] ] = $field['value'];
        }
        $errors = array();
        $seen = array();
        $new = array();
        foreach ( array( 'add', 'set', 'remove' ) as $side ) {
            if ( ! isset( $change[ $side ] ) ) {
                continue;
            }
            if ( ! is_array( $change[ $side ] ) ) {
                $errors[] = 'info_field_wrong_side: info_fields.' . $side . ' is a list';
                continue;
            }
            foreach ( $change[ $side ] as $item ) {
                if ( $side === 'remove' ) {
                    $id = $item;
                    $value = null;
                } elseif ( is_array( $item ) && array_key_exists( 'id', $item ) && array_key_exists( 'value', $item ) && count( $item ) === 2 ) {
                    $id = $item['id'];
                    $value = $item['value'];
                } else {
                    $errors[] = 'info_field_wrong_side: an item of info_fields.' . $side . ' is {id, value}';
                    continue;
                }
                if ( ( ! is_int( $id ) && ! ( is_string( $id ) && ctype_digit( $id ) ) ) || ! isset( $defs[ (int) $id ] ) || $defs[ (int) $id ]->type === 'file' ) {
                    $errors[] = 'info_field_unknown: ' . wp_json_encode( $id ) . ' — ids are in get_customer.info_field_defs (File fields are not set here)';
                    continue;
                }
                $id = (int) $id;
                $def = $defs[ $id ];
                $label = wp_strip_all_tags( (string) $def->label );
                if ( isset( $seen[ $id ] ) ) {
                    $errors[] = 'info_field_wrong_side: field ' . $id . ' "' . $label . '" appears more than once';
                    continue;
                }
                $seen[ $id ] = true;
                $empty = CustomerData::infoEmpty( isset( $now[ $id ] ) ? $now[ $id ] : '' );
                if ( $side === 'add' && ! $empty ) {
                    $errors[] = 'info_field_wrong_side: field ' . $id . ' "' . $label . '" has a value — use set to replace it';
                    continue;
                }
                if ( $side !== 'add' && $empty ) {
                    $errors[] = 'info_field_wrong_side: field ' . $id . ' "' . $label . '" has no value — use add';
                    continue;
                }
                if ( $side === 'remove' ) {
                    if ( $def->type === 'radio-buttons' ) {
                        $errors[] = 'info_field_radio_clear: field ' . $id . ' "' . $label . '" is radio buttons — its choice can only be changed to another item';
                        continue;
                    }
                    $new[ $id ] = $def->type === 'checkboxes' ? array() : '';
                    continue;
                }
                $error = self::infoValueError( $def, $value );
                if ( $error !== null ) {
                    $errors[] = 'info_field_value: field ' . $id . ' "' . $label . '" — ' . $error;
                    continue;
                }
                $new[ $id ] = $def->type === 'checkboxes' ? array_values( $value ) : ( is_string( $value ) ? trim( $value ) : $value );
            }
        }
        $after = array();
        $done = array();
        foreach ( $values as $field ) {
            $after[] = array( 'id' => $field['id'], 'value' => array_key_exists( $field['id'], $new ) ? $new[ $field['id'] ] : $field['value'] );
            $done[ $field['id'] ] = true;
        }
        foreach ( $new as $id => $value ) {
            if ( ! isset( $done[ $id ] ) ) {
                $after[] = array( 'id' => $id, 'value' => $value );
            }
        }

        return array( $after, $errors );
    }

    /**
     * Check a value against the type of an own field (info_field_value), or null when it fits.
     *
     * @param \stdClass $def
     * @param mixed     $value
     * @return string|null
     */
    protected static function infoValueError( $def, $value )
    {
        $items = isset( $def->items ) ? array_values( (array) $def->items ) : array();
        switch ( $def->type ) {
            case 'number':
                return is_numeric( $value ) ? null : 'a number';
            case 'date':
                return is_string( $value ) && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] )
                    ? null
                    : 'a date YYYY-MM-DD';
            case 'time':
                $step = CustomerData::timeStep( $def );
                if ( ! is_string( $value ) || ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $value, $m ) || ( (int) $m[1] * 60 + (int) $m[2] ) % $step !== 0 ) {
                    return 'a time HH:MM on the field\'s ' . $step . '-minute step';
                }

                return null;
            case 'drop-down':
            case 'radio-buttons':
                return is_string( $value ) && in_array( $value, $items, true ) ? null : 'one of: ' . implode( ', ', $items );
            case 'checkboxes':
                if ( ! is_array( $value ) || ! $value ) {
                    return 'a non-empty list of its items (remove clears it)';
                }
                if ( count( array_unique( array_map( 'strval', $value ) ) ) !== count( $value ) ) {
                    return 'an item is listed twice';
                }
                foreach ( $value as $item ) {
                    if ( ! is_string( $item ) || ! in_array( $item, $items, true ) ) {
                        return wp_json_encode( $item ) . ' is not an item; items: ' . implode( ', ', $items );
                    }
                }

                return null;
        }

        // text-field, textarea and other text inputs.
        return is_string( $value ) && trim( $value ) !== '' ? null : 'a non-empty text (remove clears it)';
    }

    /**
     * @inheritDoc
     */
    public function preview( array $args, State $state )
    {
        $new = ! isset( $args['customer_id'] );
        $ref = $new ? self::newRef( $state ) : CustomerData::id( $args['customer_id'] );
        $current = $new ? CustomerData::blank() : CustomerData::values( $ref, $state );
        list( $after, $changed ) = self::resolve( $args, $current, $new, $ref, $state );
        $object = Format::object( 'customer:' . $ref, CustomerData::name( $after ), __( 'New customer', 'bookly-responsive-appointment-booking-tool' ), $new );
        $rows = array();
        $defs = CustomerData::infoDefs();
        foreach ( $changed as $field ) {
            $rule = Access::rule( 'field:customer.' . $field );
            if ( $field === 'info_fields' ) {
                $before = array();
                foreach ( $current['info_fields'] as $f ) {
                    $before[ $f['id'] ] = $f['value'];
                }
                foreach ( $after['info_fields'] as $f ) {
                    $old = isset( $before[ $f['id'] ] ) ? $before[ $f['id'] ] : '';
                    if ( wp_json_encode( $old ) === wp_json_encode( $f['value'] ) || ! isset( $defs[ $f['id'] ] ) ) {
                        continue;
                    }
                    $rows[] = array(
                        'label' => Format::rowLabel( $object, wp_strip_all_tags( (string) $defs[ $f['id'] ]->label ) ),
                        'before' => self::text( 'info', $old ),
                        'after' => self::text( 'info', $f['value'] ),
                        'link' => Format::link( $rule ),
                    );
                }
                continue;
            }
            if ( $field === 'tags' ) {
                $catalog = array();
                foreach ( CustomerData::tagRows() as $row ) {
                    $catalog[ mb_strtolower( $row['tag'] ) ] = true;
                }
                $marked = array();
                foreach ( $after['tags'] as $tag ) {
                    $marked[] = isset( $catalog[ mb_strtolower( $tag ) ] ) ? $tag : sprintf( __( '%s (new tag)', 'bookly-responsive-appointment-booking-tool' ), $tag );
                }
                $rows[] = array(
                    'label' => Format::rowLabel( $object, Format::label( $rule, 'Tags' ) ),
                    'before' => $new ? '—' : self::text( 'tags', $current['tags'] ),
                    'after' => self::text( 'tags', $marked ),
                    'link' => Format::link( $rule ),
                );
                continue;
            }
            $name = $new && in_array( $field, array( 'full_name', 'first_name', 'last_name' ), true );
            $rows[] = array(
                'label' => Format::rowLabel( $object, Format::label( $rule, $field ), array( 'name' => $name ) ),
                'before' => $new ? '—' : self::text( $field, $current[ $field ], $state ),
                'after' => self::text( $field, $after[ $field ], $state ),
                'link' => Format::link( $rule ),
            );
        }
        $warnings = $this->actionWarnings( $state );
        $warnings = array_merge( $warnings, self::effects( $changed, $current, $after, $new, $state ) );
        if ( ! $new && is_int( $ref ) ) {
            $appointments = CustomerData::appointments( $ref );
            if ( $appointments ) {
                $warnings[] = sprintf( _n( 'The customer has %d appointment; it is not changed.', 'The customer has %d appointments; they are not changed.', $appointments, 'bookly-responsive-appointment-booking-tool' ), $appointments );
            }
        }
        if ( $new ) {
            $state->created( 'customer' );
        }
        CustomerData::record( $state, $ref, $after );

        return array( 'rows' => $rows, 'warnings' => array_values( array_unique( $warnings ) ) );
    }

    /**
     * What follows from the change, one line each.
     *
     * @param string[] $changed
     * @param array    $current
     * @param array    $after
     * @param bool     $new
     * @param State    $state
     * @return string[]
     */
    protected static function effects( array $changed, array $current, array $after, $new, State $state )
    {
        $lines = array();
        foreach ( array( 'email', 'phone' ) as $field ) {
            if ( in_array( $field, $changed, true ) && $after[ $field ] !== '' ) {
                $lines[] = get_option( 'bookly_cst_allow_duplicates' )
                    ? ( $field === 'email'
                        ? __( 'Notifications to the customer go to the new email.', 'bookly-responsive-appointment-booking-tool' )
                        : __( 'SMS notifications to the customer go to the new phone.', 'bookly-responsive-appointment-booking-tool' ) )
                    : ( $field === 'email'
                        ? __( 'The booking form recognises the customer by this email, and notifications go to it.', 'bookly-responsive-appointment-booking-tool' )
                        : __( 'The booking form recognises the customer by this phone, and SMS notifications go to it.', 'bookly-responsive-appointment-booking-tool' ) );
            }
        }
        if ( in_array( 'group_id', $changed, true ) ) {
            $lines[] = $after['group_id'] === null
                ? __( 'The settings for customers without a group apply to the customer\'s next bookings.', 'bookly-responsive-appointment-booking-tool' )
                : sprintf( __( 'The settings of the group "%s" (status, discount, payment) apply to the customer\'s next bookings.', 'bookly-responsive-appointment-booking-tool' ), self::text( 'group_id', $after['group_id'], $state ) );
        }
        if ( CustomerData::mailchimpConnected() ) {
            $lines[] = __( 'Saving sends the customer to the Mailchimp audience, even if only a tag or a note changes.', 'bookly-responsive-appointment-booking-tool' );
        }

        return $lines;
    }

    /**
     * Value of a field as the card shows it.
     *
     * @param string     $field
     * @param mixed      $value
     * @param State|null $state
     * @return string
     */
    protected static function text( $field, $value, $state = null )
    {
        switch ( $field ) {
            case 'group_id':
                if ( $value === null ) {
                    return Knowledge::label( 'No group' );
                }
                $groups = SetCustomerGroup::groups( $state );

                return isset( $groups[ $value ] ) ? $groups[ $value ]['name'] : '#' . $value;
            case 'birthday':
                if ( $value === null || $value === '' ) {
                    return '—';
                }

                return substr( $value, 0, 4 ) === '0000' ? substr( $value, 5 ) : Lib\Utils\DateTime::formatDate( $value );
            case 'notes':
                return $value === '' ? '—' : wp_trim_words( wp_strip_all_tags( $value ), 20 );
            case 'tags':
            case 'info':
                if ( is_array( $value ) ) {
                    return $value ? implode( ', ', array_map( 'strval', $value ) ) : '—';
                }
        }

        return $value === '' || $value === null ? '—' : (string) $value;
    }

    /**
     * @inheritDoc
     */
    public function apply( array $args )
    {
        $new = ! isset( $args['customer_id'] );
        $id = $new ? null : (int) $args['customer_id'];
        if ( ! $new && CustomerData::row( $id ) === null ) {
            return array();
        }
        $before = $new ? CustomerData::blank() : CustomerData::values( $id );
        list( $after ) = self::resolve( $args, $before, $new, $new ? '$0' : $id, new State() );
        $catalog = CustomerData::tagRows();
        $customer = CustomerData::write( $id, $after );
        $id = (int) $customer->getId();
        $now = CustomerData::tagRows();
        $snapshot = array(
            'customer_id' => $id,
            'created' => $new,
            'before' => $before,
            'after' => CustomerData::values( $id ),
            'tags_created' => array_values( array_diff_key( $now, $catalog ) ),
            'tags_deleted' => array_values( array_diff_key( $catalog, $now ) ),
        );
        if ( $new ) {
            $snapshot['created_id'] = $id;
        }

        return $snapshot;
    }

    /**
     * Return the customer's values while the customer is as the action left it (saved the same
     * way, so Mailchimp gets the previous data); delete a created customer while it has no
     * appointments and payments. The Mailchimp audience keeps what it got.
     *
     * @inheritDoc
     */
    public function undo( array $snapshot )
    {
        if ( empty( $snapshot['customer_id'] ) ) {
            return;
        }
        $id = $snapshot['customer_id'];
        if ( CustomerData::row( $id ) === null || wp_json_encode( CustomerData::values( $id ) ) !== wp_json_encode( $snapshot['after'] ) ) {
            return;
        }
        if ( $snapshot['created'] ) {
            $used = Lib\Entities\CustomerAppointment::query()->where( 'customer_id', $id )->count()
                || Lib\Entities\Payment::query()->where( 'customer_id', $id )->count();
            if ( $used ) {
                return;
            }
            Lib\Entities\Customer::find( $id )->delete();
        } else {
            // The tags the save dropped from the catalog come back with their colours first, so
            // the save below does not create them anew.
            $catalog = array();
            foreach ( CustomerData::tagRows() as $row ) {
                $catalog[ mb_strtolower( $row['tag'] ) ] = true;
            }
            foreach ( $snapshot['tags_deleted'] as $row ) {
                if ( ! isset( $catalog[ mb_strtolower( $row['tag'] ) ] ) ) {
                    Lib\Proxy\Pro::insertTagRow( $row );
                }
            }
            CustomerData::write( $id, $snapshot['before'] );
        }
        $unused = array();
        foreach ( $snapshot['tags_created'] as $row ) {
            if ( ! CustomerData::tagUsed( $row['tag'] ) ) {
                $unused[] = (int) $row['id'];
            }
        }
        if ( $unused ) {
            Lib\Proxy\Pro::deleteTagRows( $unused );
        }
    }
}
