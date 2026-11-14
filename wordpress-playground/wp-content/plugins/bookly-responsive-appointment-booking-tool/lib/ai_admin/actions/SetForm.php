<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\Format;
use Bookly\Lib\AiAdmin\Forms\FormAction;
use Bookly\Lib\AiAdmin\Forms\FormData;
use Bookly\Lib\AiAdmin\Rules;
use Bookly\Lib\AiAdmin\Services\ServiceAction;
use Bookly\Lib\AiAdmin\StaffData;
use Bookly\Lib\AiAdmin\State;

/**
 * set_form — change the given settings of one modern booking form (assets/ai-setup/actions/set_form.yaml).
 * The editor's Save is repeated: the full settings of the form (saved values on top of the
 * defaults of its kind) with the given keys applied are written back; name changes with them,
 * slug, type and custom CSS stay. Ids in lists are written as strings, as the editor writes them.
 */
class SetForm extends FormAction implements ActionInterface
{
    /** Texts of a form that no control of the editor changes. */
    const NO_EDITOR_TEXTS = 'time,to_pay,more,cart_item_not_available,verify_code,verify_email,verify_phone,verify_resend,verify_apply';
    /** Fields of the details step with a Width control. */
    const WIDTH_FIELDS = 'text,full_name,first_name,last_name,email,phone,birthday,google_maps,address,notes,terms';
    /** Fields of the details step shown only with their add-on. */
    const ADDON_FIELDS = 'google_maps:google-maps-address,customer_information:customer-information,custom_fields:custom-fields';

    protected function name()
    {
        return 'set_form';
    }

    /**
     * Keys of the action: kind of value, the form kinds that have it (null — every booking kind
     * except the Cancellation form) and the add-on its control needs.
     *
     * @return array key => array( kind, forms, addon )
     */
    protected static function keys()
    {
        $all = array( 'search-form', 'services-form', 'staff-form', 'tags-form', 'cancellation-confirmation' );
        $services_tags = array( 'services-form', 'tags-form' );
        $staff = array( 'staff-form' );
        $keys = array(
            'name' => array( 'name', $all, null ),
            'main_color' => array( 'color', $all, null ),
            'l10n' => array( 'l10n', $all, null ),
            'show_reason' => array( 'bool', array( 'cancellation-confirmation' ), null ),
            'required_reason' => array( 'bool', array( 'cancellation-confirmation' ), null ),
            'skip_categories_step' => array( 'bool', array( 'services-form' ), null ),
            'skip_services_step' => array( 'bool', $services_tags, null ),
            'services_list' => array( 'list', $services_tags, null ),
            'step_service_card_width' => array( 'width', $services_tags, null ),
            'skip_tags_step' => array( 'bool', array( 'tags-form' ), null ),
            'skip_staff_categories_step' => array( 'bool', $staff, null ),
            'skip_staff_step' => array( 'bool', $staff, null ),
            'staff_list' => array( 'list', $staff, null ),
            'staff_card_width' => array( 'width', $staff, null ),
            'categories_list' => array( 'list', array( 'services-form', 'staff-form' ), null ),
            'category_card_width' => array( 'width', array( 'services-form', 'staff-form' ), null ),
            'adaptive_grid' => array( 'bool', null, null ),
            'transparent_bg' => array( 'bool', null, null ),
            'filters_position' => array( array( 'left', 'top' ), null, null ),
            'corner_radius' => array( array( 'none', 'medium', 'large' ), null, null ),
            'shadows' => array( array( 'none', 'medium', 'large' ), null, null ),
            'hide_borders' => array( 'bool', null, null ),
            'initial_view' => array( array( 'form', 'button' ), null, null ),
            'skip_cart_step' => array( 'bool', null, 'cart' ),
            'show_calendar' => array( 'bool', null, null ),
            'available_dates' => array( array( 'schedule', 'with_slots' ), null, null ),
            'show_services_filter' => array( 'bool', null, null ),
            'show_staff_filter' => array( 'bool', null, null ),
            'show_locations_filter' => array( 'bool', null, 'locations' ),
            'default_service' => array( 'object', null, null ),
            'default_staff' => array( 'object', null, null ),
            'default_location' => array( 'object', null, 'locations' ),
            'hidden_services' => array( 'list', null, null ),
            'sell_services' => array( 'bool', null, 'packages|gift-cards' ),
            'show_services_mode' => array( array( 'all', 'no_any', 'only_any' ), null, null ),
            'cards_display_mode' => array( array( 'with_slots', 'all' ), null, null ),
            'service_card_width' => array( 'width', null, null ),
            'service_header_height' => array( 'height', null, null ),
            'show_first_slot_only' => array( 'bool', null, null ),
            'show_blocked_slots' => array( 'bool', null, null ),
            'show_timezone' => array( 'bool', null, null ),
            'show_change_date' => array( 'bool', null, null ),
            'verify_credentials' => array( 'verify', null, null ),
            'details_fields_show' => array( 'details_show', null, null ),
            'details_fields_order' => array( 'details_order', null, null ),
            'details_fields_width' => array( 'details_width', null, null ),
            'use_cart_local_storage' => array( 'bool', null, 'cart' ),
            'open_cart_after_add' => array( 'bool', null, 'cart' ),
            'show_qr_code' => array( 'bool', null, null ),
            'show_add_to_calendar' => array( 'bool', null, null ),
            'show_download_invoice' => array( 'bool', null, 'invoices' ),
        );
        foreach ( array( 'phone', 'email', 'full_name', 'first_name', 'last_name', 'birthday', 'address' ) as $field ) {
            $keys[ $field . '_required' ] = array( 'bool', null, null );
        }

        return $keys;
    }

    /**
     * Whether the add-on (or one of "a|b") of a key is active.
     *
     * @param string|null $addon
     * @return bool
     */
    protected static function addonActive( $addon )
    {
        if ( $addon === null ) {
            return true;
        }
        foreach ( explode( '|', $addon ) as $slug ) {
            if ( Access::addonActive( $slug ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keys of a form kind that the editor of this site offers.
     *
     * @param string $type
     * @return array key => array( kind, forms, addon )
     */
    public static function keysOf( $type )
    {
        $out = array();
        foreach ( self::keys() as $key => $meta ) {
            $forms = $meta[1] === null ? array( 'search-form', 'services-form', 'staff-form', 'tags-form' ) : $meta[1];
            if ( in_array( $type, $forms, true ) && self::addonActive( $meta[2] ) ) {
                $out[ $key ] = $meta;
            }
        }

        return $out;
    }

    /**
     * Entity of the ids of a list or default key in a form of the kind.
     *
     * @param string $key
     * @param string $type
     * @return string service | staff | category | staff_category | location
     */
    protected static function entityOf( $key, $type )
    {
        switch ( $key ) {
            case 'default_staff':
            case 'staff_list':
                return 'staff';
            case 'default_location':
                return 'location';
            case 'categories_list':
                return $type === 'staff-form' ? 'staff_category' : 'category';
        }

        return 'service';
    }

    /**
     * Existing ids of an entity, in the editor's order, with "$N" of objects created earlier.
     *
     * @param string $entity
     * @param State  $state
     * @return array
     */
    protected static function allIds( $entity, State $state )
    {
        switch ( $entity ) {
            case 'staff':
                $ids = array_keys( FormData::staff() );
                break;
            case 'location':
                $ids = array_keys( FormData::locations() );
                break;
            case 'category':
                $ids = array_keys( FormData::categories() );
                break;
            case 'staff_category':
                $ids = array_keys( FormData::staffCategories() );
                break;
            default:
                $ids = array_keys( FormData::services() );
        }
        $ids = array_map( 'strval', $ids );
        if ( $entity !== 'location' ) {
            foreach ( $state->createdItems() as $item => $created ) {
                if ( $created === $entity && $item < $state->item() ) {
                    $ids[] = '$' . $item;
                }
            }
        }

        return $ids;
    }

    /**
     * Name of an object of a list for the card.
     *
     * @param string $entity
     * @param string $id
     * @param State  $state
     * @return string
     */
    protected static function objectName( $entity, $id, State $state )
    {
        $id = (string) $id;
        switch ( $entity ) {
            case 'staff':
                return StaffData::name( $id[0] === '$' ? $id : (int) $id, $state );
            case 'location':
                $list = FormData::locations();
                break;
            case 'category':
                if ( $id[0] === '$' ) {
                    $categories = SetCategory::categories( $state );

                    return isset( $categories[ $id ] ) ? $categories[ $id ]['name'] : $id;
                }
                $list = FormData::categories();
                break;
            case 'staff_category':
                if ( $id === '-1' ) {
                    $list = FormData::staffCategories();
                    break;
                }

                return StaffData::categoryName( $id[0] === '$' ? $id : (int) $id, $state );
            default:
                if ( $id[0] === '$' ) {
                    return ServiceAction::serviceTitle( $state, $id );
                }
                $list = FormData::services();
        }

        return isset( $list[ (int) $id ] ) ? $list[ (int) $id ] : '#' . $id;
    }

    /**
     * Ids of the details step this site offers for a form: the ids of its order whose add-on is
     * active.
     *
     * @param array $full
     * @return string[]
     */
    protected static function detailsFields( array $full )
    {
        $addons = array();
        foreach ( explode( ',', self::ADDON_FIELDS ) as $pair ) {
            list( $field, $addon ) = explode( ':', $pair );
            $addons[ $field ] = $addon;
        }
        $out = array();
        foreach ( isset( $full['details_fields_order'] ) ? $full['details_fields_order'] : array() as $field ) {
            if ( ! isset( $addons[ $field ] ) || Access::addonActive( $addons[ $field ] ) ) {
                $out[] = (string) $field;
            }
        }

        return $out;
    }

    /**
     * Texts of a step the form does not show (their tab is not in the editor).
     *
     * @return string[] l10n keys, groups as "group.key"
     */
    protected static function hiddenStepTexts()
    {
        $hidden = array();
        if ( ! Lib\Config::serviceExtrasActive() ) {
            $hidden = array_merge( $hidden, array( 'text_extras', 'summary', 'steps.extras', 'steps_descriptions.extras' ) );
        }
        if ( ! Lib\Config::cartActive() ) {
            $hidden = array_merge( $hidden, array( 'text_cart', 'cart_items_error', 'added_to_cart', 'cart_empty', 'steps.cart', 'steps_descriptions.cart' ) );
        }
        if ( ! FormData::hasPaymentStep() ) {
            $hidden = array_merge( $hidden, array( 'text_payment', 'buy_now', 'coupon_button', 'coupon_expired', 'coupon_invalid', 'coupon_label', 'coupon_text',
                'deposit_label', 'deposit_option', 'full_price_option', 'gift_card_button', 'gift_card_expired', 'gift_card_invalid', 'gift_card_label',
                'gift_card_low_balance', 'gift_card_not_found', 'gift_card_text', 'steps.payment', 'steps_descriptions.payment', 'payment_system_*' ) );
        }

        return $hidden;
    }

    /**
     * @inheritDoc
     */
    public function validate( array $args, State $state )
    {
        if ( $error = $this->forbidden() ) {
            return array( $error );
        }
        if ( ! Lib\Config::proActive() ) {
            return array( 'pro_inactive: modern booking forms need Bookly Pro' );
        }
        $id = isset( $args['form_id'] ) ? self::id( $args['form_id'] ) : 0;
        if ( ! self::exists( $id, $state ) ) {
            return array( 'form_not_found: ' . wp_json_encode( isset( $args['form_id'] ) ? $args['form_id'] : null ) );
        }
        $form = self::current( $state, $id );
        $result = $this->evaluate( $form, $args, $state );
        if ( ! $result['errors'] ) {
            $form['name'] = $result['name'];
            $form['settings'] = $result['settings'];
            self::setCurrent( $state, $id, $form );
        }

        return $result['errors'];
    }

    /**
     * Check the item and compute the form after it.
     *
     * @param array $form see FormAction::current()
     * @param array $args
     * @param State $state
     * @return array array( 'errors' => string[], 'name' => string, 'settings' => array full settings after, 'full' => array before )
     */
    protected function evaluate( array $form, array $args, State $state )
    {
        $type = $form['type'];
        $full = FormData::full( $type, $form['settings'] );
        $out = array( 'errors' => array(), 'name' => $form['name'], 'settings' => $full, 'full' => $full );
        if ( ! in_array( $type, FormData::bookingKinds(), true ) ) {
            $out['errors'][] = 'form_kind_not_supported: a ' . $type . ' is not changed by set_form'
                . ( $type === Lib\Entities\Form::TYPE_AI_ASSISTANT ? ' (use set_ai_form)' : ' (its own area changes it)' );

            return $out;
        }
        $keys = self::keys();
        $errors = array();
        $given = array();
        foreach ( $args as $key => $value ) {
            if ( $key === 'form_id' ) {
                continue;
            }
            if ( in_array( $key, array( 'slug', 'token' ), true ) ) {
                $errors[] = 'slug_not_editable: the slug is changed only by the owner in the form\'s Settings';
            } elseif ( in_array( $key, array( 'theme', 'theme_settings' ), true ) ) {
                $errors[] = 'theme_not_editable: the owner applies a theme in the form\'s Settings';
            } elseif ( $key === 'custom_css' ) {
                $errors[] = 'custom_css_not_editable: custom CSS is changed only by the owner';
            } elseif ( ! isset( $keys[ $key ] ) ) {
                $errors[] = 'unknown_argument: ' . $key;
            } else {
                $forms = $keys[ $key ][1] === null ? array( 'search-form', 'services-form', 'staff-form', 'tags-form' ) : $keys[ $key ][1];
                if ( ! in_array( $type, $forms, true ) ) {
                    $errors[] = 'key_not_for_kind: ' . $key . ' is not a setting of a ' . $type;
                } elseif ( ! self::addonActive( $keys[ $key ][2] ) ) {
                    $errors[] = 'addon_inactive: ' . $key . ' needs the ' . str_replace( '|', ' or ', $keys[ $key ][2] ) . ' add-on';
                } else {
                    $given[ $key ] = $value;
                }
            }
        }
        if ( ! $given && ! $errors ) {
            $out['errors'][] = 'nothing_to_change: give at least one key to change';

            return $out;
        }

        $after = $full;
        $name = $form['name'];
        $details = self::detailsFields( $full );
        foreach ( $given as $key => $value ) {
            $kind = $keys[ $key ][0];
            if ( is_array( $kind ) ) {
                if ( ! in_array( $value, $kind, true ) ) {
                    $errors[] = 'value_not_allowed: ' . $key . ' is one of ' . implode( ', ', $kind );
                } else {
                    $after[ $key ] = $value;
                }
                continue;
            }
            switch ( $kind ) {
                case 'name':
                    if ( ! is_string( $value ) || trim( $value ) === '' ) {
                        $errors[] = 'name_length: the name is empty';
                    } elseif ( mb_strlen( $value ) > 255 ) {
                        $errors[] = 'name_length: at most 255 characters';
                    } else {
                        $name = $value;
                    }
                    break;
                case 'color':
                    if ( ! is_string( $value ) || ! preg_match( '/^#[0-9a-fA-F]{6}$/', $value ) ) {
                        $errors[] = 'color_hex: ' . $key . ' is "#rrggbb"';
                    } else {
                        $after[ $key ] = $value;
                    }
                    break;
                case 'bool':
                    if ( ! is_bool( $value ) ) {
                        $errors[] = 'value_not_allowed: ' . $key . ' is true or false';
                    } else {
                        $after[ $key ] = $value;
                    }
                    break;
                case 'width':
                case 'height':
                    $min = $kind === 'width' ? 200 : 0;
                    $max = $kind === 'width' ? 400 : 600;
                    if ( ! is_int( $value ) || $value < $min || $value > $max ) {
                        $errors[] = 'size_range: ' . $key . ' is ' . $min . '-' . $max;
                    } else {
                        $after[ $key ] = $value;
                    }
                    break;
                case 'verify':
                    if ( ! in_array( $value, array( false, 'phone', 'email' ), true ) ) {
                        $errors[] = 'verify_values: verify_credentials is false, "phone" or "email"';
                    } else {
                        $after[ $key ] = $value;
                    }
                    break;
                case 'object':
                    $entity = self::entityOf( $key, $type );
                    if ( $value === null ) {
                        $after[ $key ] = null;
                    } elseif ( ! is_int( $value ) || ! in_array( (string) $value, self::allIds( $entity, $state ), true ) ) {
                        $errors[] = 'object_not_found: ' . $key . ' ' . wp_json_encode( $value ) . ' is not a ' . str_replace( '_', ' ', $entity )
                            . ( $entity === 'service' ? ' (packages are not offered)' : '' );
                    } else {
                        $after[ $key ] = (string) $value;
                    }
                    break;
                case 'list':
                    $errors = array_merge( $errors, $this->applyList( $key, $value, $type, $full, $after, $state ) );
                    break;
                case 'details_show':
                    $errors = array_merge( $errors, $this->applyDetailsShow( $value, $details, $full, $after ) );
                    break;
                case 'details_order':
                    $active = $value;
                    if ( is_array( $active ) ) {
                        // Ids of add-on fields that are off are left out of the order, as the editor's list does.
                        $active = array_values( array_filter( $active, function ( $field ) use ( $details ) {
                            return in_array( $field, $details, true );
                        } ) );
                    }
                    $sorted_value = is_array( $active ) ? $active : array();
                    $sorted_details = $details;
                    sort( $sorted_value );
                    sort( $sorted_details );
                    if ( ! is_array( $value ) || $sorted_value !== $sorted_details || count( array_unique( $active ) ) !== count( $active ) ) {
                        $errors[] = 'details_field_unknown: details_fields_order is the whole order of exactly ' . implode( ', ', $details );
                    } else {
                        $after[ $key ] = $active;
                    }
                    break;
                case 'details_width':
                    if ( ! is_array( $value ) || ! $value ) {
                        $errors[] = 'details_field_unknown: details_fields_width is an object {field: 1-12}';
                        break;
                    }
                    foreach ( $value as $field => $width ) {
                        if ( ! in_array( (string) $field, $details, true ) ) {
                            $errors[] = self::unknownField( $field, $full );
                        } elseif ( ! in_array( (string) $field, explode( ',', self::WIDTH_FIELDS ), true ) ) {
                            $errors[] = 'width_field_has_control: ' . $field . ' has no Width control';
                        } elseif ( ! is_int( $width ) || $width < 1 || $width > 12 ) {
                            $errors[] = 'size_range: details_fields_width.' . $field . ' is 1-12';
                        } else {
                            $after['details_fields_width'][ $field ] = $width;
                        }
                    }
                    break;
                case 'l10n':
                    $errors = array_merge( $errors, $this->applyTexts( $value, $type, $full['l10n'], $after['l10n'], '' ) );
                    break;
            }
        }

        // Conditions on the values after the item.
        if ( array_key_exists( 'l10n', $given ) && is_array( $given['l10n'] ) && array_key_exists( 'initial_view_button_title', $given['l10n'] )
            && ( ! isset( $after['initial_view'] ) || $after['initial_view'] !== 'button' ) ) {
            $errors[] = 'button_title_needs_button: l10n.initial_view_button_title changes only while initial_view is "button"';
        }
        if ( array_key_exists( 'open_cart_after_add', $given ) && ! empty( $after['skip_cart_step'] ) ) {
            $errors[] = 'open_cart_needs_cart_step: open_cart_after_add changes only while skip_cart_step is off';
        }
        if ( array_key_exists( 'required_reason', $given ) && empty( $after['show_reason'] ) ) {
            $errors[] = 'required_reason_needs_show_reason: required_reason changes only while show_reason is on';
        }
        if ( array_key_exists( 'details_fields_show', $given ) && ! $errors ) {
            $show = $after['details_fields_show'];
            if ( ! array_intersect( array( 'full_name', 'first_name', 'last_name' ), $show ) || ! array_intersect( array( 'email', 'phone' ), $show ) ) {
                $errors[] = 'details_without_name_or_contact: the details step must keep a name field (full_name, first_name or last_name) and a contact field (email or phone)';
            }
        }
        foreach ( array( 'phone', 'email', 'full_name', 'first_name', 'last_name', 'birthday', 'address' ) as $field ) {
            if ( array_key_exists( $field . '_required', $given ) && ! in_array( $field, isset( $after['details_fields_show'] ) ? $after['details_fields_show'] : array(), true ) ) {
                $errors[] = 'required_field_hidden: ' . $field . '_required acts only while the ' . $field . ' field is shown (details_fields_show)';
            }
        }
        if ( ! $errors && $name === $form['name'] && $after === $full ) {
            $errors[] = 'nothing_to_change: every given key already has this value';
        }
        $out['errors'] = $errors;
        $out['name'] = $name;
        $out['settings'] = $after;

        return $out;
    }

    /**
     * details_field_unknown / details_field_addon error of a field id.
     *
     * @param string $field
     * @param array  $full
     * @return string
     */
    protected static function unknownField( $field, array $full )
    {
        foreach ( explode( ',', self::ADDON_FIELDS ) as $pair ) {
            list( $addon_field, $addon ) = explode( ':', $pair );
            if ( (string) $field === $addon_field ) {
                return 'details_field_addon: ' . $field . ' needs the ' . $addon . ' add-on';
            }
        }

        return 'details_field_unknown: ' . $field . ' is not a field of the details step (' . implode( ', ', self::detailsFields( $full ) ) . ')';
    }

    /**
     * Apply a list key (hidden_services or a *_list) to $after.
     *
     * @param string $key
     * @param mixed  $value
     * @param string $type
     * @param array  $full
     * @param array  $after
     * @param State  $state
     * @return string[] errors
     */
    protected function applyList( $key, $value, $type, array $full, array &$after, State $state )
    {
        $nullable = $key !== 'hidden_services';
        if ( $value === null ) {
            if ( ! $nullable ) {
                return array( 'list_not_present: hidden_services takes add / remove' );
            }
            $after[ $key ] = null;

            return array();
        }
        if ( ! is_array( $value ) || ! $value || array_diff( array_keys( $value ), array( 'add', 'remove' ) ) ) {
            return array( 'list_not_present: ' . $key . ' takes an object with add and / or remove' . ( $nullable ? ', or null' : '' ) );
        }
        $entity = self::entityOf( $key, $type );
        $all = self::allIds( $entity, $state );
        $current = isset( $full[ $key ] ) ? $full[ $key ] : ( $nullable ? null : array() );
        $is_all = $current === null;
        $list = $is_all ? $all : array_map( 'strval', (array) $current );
        $add = isset( $value['add'] ) && is_array( $value['add'] ) ? $value['add'] : array();
        $remove = isset( $value['remove'] ) && is_array( $value['remove'] ) ? $value['remove'] : array();
        $errors = array();
        foreach ( $add as $id ) {
            $id = (string) $id;
            if ( ! in_array( $id, $all, true ) ) {
                $errors[] = 'object_not_found: ' . $key . '.add ' . $id . ' is not a ' . str_replace( '_', ' ', $entity ) . ( $entity === 'service' ? ' (packages are not offered)' : '' );
            } elseif ( $is_all ) {
                $errors[] = 'list_already_present: ' . $key . ' shows everything now (null); only remove is possible';
            } elseif ( in_array( $id, $list, true ) ) {
                $errors[] = 'list_already_present: ' . $key . ' already has ' . $id;
            }
        }
        foreach ( $remove as $id ) {
            if ( ! is_int( $id ) && ! ( is_string( $id ) && preg_match( '/^-?\d+$/', $id ) ) ) {
                $errors[] = 'list_not_present: ' . $key . '.remove takes ids of the list';
            } elseif ( ! in_array( (string) $id, $list, true ) ) {
                $errors[] = 'list_not_present: ' . $key . ' has no ' . $id;
            }
        }
        $add_ids = array_map( 'strval', $add );
        $remove_ids = array_map( 'strval', $remove );
        if ( array_intersect( $add_ids, $remove_ids ) ) {
            $errors[] = 'list_add_remove_overlap: ' . $key . ' has the same id in add and remove';
        }
        if ( $errors ) {
            return $errors;
        }
        $new = array();
        foreach ( array_merge( $list, $add_ids ) as $id ) {
            if ( ! in_array( $id, $remove_ids, true ) && ! in_array( $id, $new, true ) ) {
                $new[] = $id;
            }
        }
        if ( $nullable && ! $new ) {
            return array( 'custom_list_empty: ' . $key . ' would be empty; hide the step or the form instead' );
        }
        $after[ $key ] = $new;

        return array();
    }

    /**
     * Apply details_fields_show {add, remove} to $after.
     *
     * @param mixed    $value
     * @param string[] $details
     * @param array    $full
     * @param array    $after
     * @return string[] errors
     */
    protected function applyDetailsShow( $value, array $details, array $full, array &$after )
    {
        if ( ! is_array( $value ) || ! $value || array_diff( array_keys( $value ), array( 'add', 'remove' ) ) ) {
            return array( 'details_field_unknown: details_fields_show takes an object with add and / or remove' );
        }
        $show = array_map( 'strval', isset( $full['details_fields_show'] ) ? $full['details_fields_show'] : array() );
        $add = isset( $value['add'] ) && is_array( $value['add'] ) ? array_map( 'strval', $value['add'] ) : array();
        $remove = isset( $value['remove'] ) && is_array( $value['remove'] ) ? array_map( 'strval', $value['remove'] ) : array();
        $errors = array();
        foreach ( array_merge( $add, $remove ) as $field ) {
            if ( ! in_array( $field, $details, true ) ) {
                $errors[] = self::unknownField( $field, $full );
            }
        }
        foreach ( $add as $field ) {
            if ( in_array( $field, $show, true ) ) {
                $errors[] = 'list_already_present: ' . $field . ' is shown already';
            }
        }
        foreach ( $remove as $field ) {
            if ( ! in_array( $field, $show, true ) ) {
                $errors[] = 'list_not_present: ' . $field . ' is not shown now';
            }
        }
        if ( array_intersect( $add, $remove ) ) {
            $errors[] = 'list_add_remove_overlap: details_fields_show has the same field in add and remove';
        }
        if ( $errors ) {
            return array_values( array_unique( $errors ) );
        }
        $new = array();
        foreach ( array_merge( $show, $add ) as $field ) {
            if ( ! in_array( $field, $remove, true ) && ! in_array( $field, $new, true ) ) {
                $new[] = $field;
            }
        }
        $after['details_fields_show'] = $new;

        return array();
    }

    /**
     * Apply texts of l10n to $after (a group key by key).
     *
     * @param mixed  $value
     * @param string $type
     * @param array  $current texts of the form at this level
     * @param array  $after
     * @param string $prefix "group." for a group
     * @return string[] errors
     */
    protected function applyTexts( $value, $type, array $current, array &$after, $prefix )
    {
        if ( ! is_array( $value ) || ! $value ) {
            return array( 'text_key_unknown: l10n' . ( $prefix ? '.' . rtrim( $prefix, '.' ) : '' ) . ' is an object of texts by key' );
        }
        $no_editor = explode( ',', self::NO_EDITOR_TEXTS );
        if ( $type === 'tags-form' ) {
            $no_editor[] = 'text_tags_category';
        }
        if ( $type === 'cancellation-confirmation' ) {
            $no_editor[] = 'required';
        }
        $hidden = self::hiddenStepTexts();
        $errors = array();
        foreach ( $value as $key => $text ) {
            $path = $prefix . $key;
            if ( ! array_key_exists( $key, $current ) ) {
                $errors[] = 'text_key_unknown: l10n.' . $path . ' is not a text of this form (list_forms with form_id shows them)';
            } elseif ( is_array( $current[ $key ] ) ) {
                $errors = array_merge( $errors, $this->applyTexts( $text, $type, $current[ $key ], $after[ $key ], $path . '.' ) );
            } elseif ( ! is_string( $text ) ) {
                $errors[] = 'text_key_unknown: l10n.' . $path . ' is a string';
            } elseif ( $prefix === '' && in_array( $key, $no_editor, true ) ) {
                $errors[] = 'text_key_has_editor: l10n.' . $path . ' has no control in the editor';
            } elseif ( in_array( $path, $hidden, true ) || ( $prefix === '' && strpos( $key, 'payment_system_' ) === 0 && in_array( 'payment_system_*', $hidden, true ) ) ) {
                $errors[] = 'text_of_hidden_step: l10n.' . $path . ' belongs to a step this form does not show';
            } else {
                $after[ $key ] = $text;
            }
        }

        return $errors;
    }

    /**
     * Value of a key for the card.
     *
     * @param string $key
     * @param mixed  $value
     * @param string $type
     * @param State  $state
     * @return string
     */
    protected static function show( $key, $value, $type, State $state )
    {
        $keys = self::keys();
        $kind = $keys[ $key ][0];
        if ( $kind === 'object' ) {
            return $value === null || $value === '' ? '—' : self::objectName( self::entityOf( $key, $type ), $value, $state );
        }
        if ( $kind === 'width' || $kind === 'height' ) {
            return $value === null ? '—' : $value . ' px';
        }
        if ( $kind === 'list' ) {
            $entity = self::entityOf( $key, $type );
            if ( $value === null ) {
                return __( 'All, also the ones created later', 'bookly-responsive-appointment-booking-tool' );
            }
            $names = array();
            foreach ( (array) $value as $id ) {
                $names[] = self::objectName( $entity, $id, $state );
            }
            if ( $key === 'hidden_services' ) {
                return $names ? implode( ', ', $names ) : '—';
            }

            return sprintf( '%s (%d / %d)', $names ? implode( ', ', $names ) : '—', count( $names ), count( self::allIds( $entity, $state ) ) );
        }

        return self::value( 'field:form.settings.' . $key, $value );
    }

    /**
     * Fields of the details step for the card: shown ones in order with * for required, then hidden.
     *
     * @param array $settings
     * @return string
     */
    protected static function detailsText( array $settings )
    {
        $show = isset( $settings['details_fields_show'] ) ? $settings['details_fields_show'] : array();
        $shown = array();
        $hidden = array();
        foreach ( self::detailsFields( $settings ) as $field ) {
            if ( in_array( $field, $show, true ) ) {
                $shown[] = $field . ( ! empty( $settings[ $field . '_required' ] ) ? '*' : '' );
            } else {
                $hidden[] = $field;
            }
        }

        return implode( ', ', $shown ) . ( $hidden ? '; ' . sprintf( __( 'hidden: %s', 'bookly-responsive-appointment-booking-tool' ), implode( ', ', $hidden ) ) : '' );
    }

    /**
     * Changed texts as flat "key" / "group.key" => array( before, after ).
     *
     * @param array  $before
     * @param array  $after
     * @param string $prefix
     * @return array
     */
    protected static function changedTexts( array $before, array $after, $prefix = '' )
    {
        $out = array();
        foreach ( $after as $key => $text ) {
            $old = array_key_exists( $key, $before ) ? $before[ $key ] : null;
            if ( is_array( $text ) ) {
                $out = array_merge( $out, self::changedTexts( is_array( $old ) ? $old : array(), $text, $prefix . $key . '.' ) );
            } elseif ( $old !== $text ) {
                $out[ $prefix . $key ] = array( $old, $text );
            }
        }

        return $out;
    }

    /**
     * @inheritDoc
     */
    public function preview( array $args, State $state )
    {
        $id = self::id( $args['form_id'] );
        $form = self::current( $state, $id );
        $type = $form['type'];
        $result = $this->evaluate( $form, $args, $state );
        $before = $result['full'];
        $after = $result['settings'];
        $name_before = $form['name'];
        $form['name'] = $result['name'];
        $form['settings'] = $after;
        self::setCurrent( $state, $id, $form );
        // The card names the form as it is after the item.
        $object = self::cardObject( $state, $id );

        $entries = Rules::entries();
        $rows = array();
        $warnings = array();
        if ( isset( $args['name'] ) ) {
            $rows[] = array( 'label' => Format::rowLabel( $object, self::label( 'field:form.name', 'Form title' ), array( 'name' => true ) ), 'before' => $name_before, 'after' => $result['name'] );
        }
        $details_changed = false;
        foreach ( self::keys() as $key => $meta ) {
            if ( ! array_key_exists( $key, $args ) || in_array( $key, array( 'name', 'l10n' ), true ) ) {
                continue;
            }
            if ( in_array( $meta[0], array( 'details_show', 'details_order' ), true ) || substr( $key, -9 ) === '_required' ) {
                $details_changed = true;
                continue;
            }
            if ( $meta[0] === 'details_width' ) {
                foreach ( $args[ $key ] as $field => $width ) {
                    $rows[] = array(
                        'label' => Format::rowLabel( $object, self::label( 'field:form.settings.details_fields_width', 'Width' ), array( 'item' => $field ) ),
                        'before' => isset( $before['details_fields_width'][ $field ] ) ? $before['details_fields_width'][ $field ] . '/12' : '—',
                        'after' => $width . '/12',
                    );
                }
                continue;
            }
            $old = array_key_exists( $key, $before ) ? $before[ $key ] : null;
            $new = array_key_exists( $key, $after ) ? $after[ $key ] : null;
            $text = self::show( $key, $new, $type, $state );
            if ( $meta[0] === 'list' && $key !== 'hidden_services' ) {
                if ( $old === null && $new !== null ) {
                    $text .= ' — ' . __( 'objects created later will NOT appear in this form until you add them', 'bookly-responsive-appointment-booking-tool' );
                } elseif ( $new === null ) {
                    $text .= ' — ' . __( 'shows every one, also the ones created later', 'bookly-responsive-appointment-booking-tool' );
                }
            }
            $rows[] = array(
                'label' => Format::rowLabel( $object, self::label( 'field:form.settings.' . $key, $key ) ),
                'before' => self::show( $key, $old, $type, $state ),
                'after' => $text,
            );
            if ( isset( $entries[ 'field:form.settings.' . $key ] ) ) {
                $warnings = array_merge( $warnings, Format::warnings( $entries[ 'field:form.settings.' . $key ], $old, $new, $state ) );
            }
            if ( strpos( $key, 'skip_' ) === 0 && $new && ! $old ) {
                $rows[] = array( 'label' => Format::rowLabel( $object, __( 'Hidden step', 'bookly-responsive-appointment-booking-tool' ), array( 'item' => $key ) ), 'before' => null, 'after' =>
                    __( 'The step is no longer shown to clients; the form starts with the next step. Its texts stay saved.', 'bookly-responsive-appointment-booking-tool' ),
                );
            }
            if ( $key === 'initial_view' && $new !== $old ) {
                $warnings[] = $new === 'button'
                    ? sprintf( __( 'The form shows a button with the text "%s" that opens the form in a window.', 'bookly-responsive-appointment-booking-tool' ), $after['l10n']['initial_view_button_title'] )
                    : __( 'The button disappears; the form is shown on the page.', 'bookly-responsive-appointment-booking-tool' );
            }
            if ( $key === 'verify_credentials' && in_array( $new, array( 'phone', 'email' ), true ) && $new !== $old ) {
                $rows[] = array( 'label' => Format::rowLabel( $object, __( 'Verification', 'bookly-responsive-appointment-booking-tool' ) ), 'before' => null, 'after' =>
                    __( 'The code reaches the client only through the "Verify customer\'s phone" / email notification; the check runs on the settings of the form the visitor\'s request names, so it does not stop a client who sends a request for another form.', 'bookly-responsive-appointment-booking-tool' ),
                );
            }
        }
        if ( $details_changed ) {
            $text = self::detailsText( $after );
            $lost = array();
            foreach ( isset( $before['details_fields_show'] ) ? $before['details_fields_show'] : array() as $field ) {
                if ( ! empty( $before[ $field . '_required' ] ) && ! in_array( $field, $after['details_fields_show'], true ) ) {
                    $lost[] = $field;
                }
            }
            if ( $lost ) {
                $text .= ' — ' . sprintf( __( 'the form no longer asks for %s', 'bookly-responsive-appointment-booking-tool' ), implode( ', ', $lost ) );
            }
            $rows[] = array(
                'label' => Format::rowLabel( $object, __( 'Details fields (* required)', 'bookly-responsive-appointment-booking-tool' ) ),
                'before' => self::detailsText( $before ),
                'after' => $text,
            );
        }
        if ( isset( $args['l10n'] ) ) {
            foreach ( self::changedTexts( $before['l10n'], $after['l10n'] ) as $key => $texts ) {
                $rows[] = array(
                    'label' => Format::rowLabel( $object, sprintf( __( 'Text «%s»', 'bookly-responsive-appointment-booking-tool' ), $key ) ),
                    'before' => self::text( $texts[0] ),
                    'after' => self::text( $texts[1] ),
                );
            }
            if ( defined( 'WPML_ST_VERSION' ) ) {
                $warnings[] = __( 'Translations of these texts in other languages are lost — WPML ties a translation to the exact text, so each changed text must be translated again; identical texts of other forms are unaffected.', 'bookly-responsive-appointment-booking-tool' );
            }
        }
        $style = array( 'main_color', 'corner_radius', 'shadows', 'adaptive_grid', 'transparent_bg', 'hide_borders', 'service_card_width', 'service_header_height', 'staff_card_width', 'step_service_card_width', 'category_card_width' );
        if ( ! empty( $before['theme'] ) && array_intersect( $style, array_keys( $args ) ) ) {
            $warnings[] = sprintf(
                __( 'The form uses the theme %s; this value is now set by hand and wins over the theme\'s preset until the owner re-applies the theme.', 'bookly-responsive-appointment-booking-tool' ),
                FormData::themeName( $before['theme'] )
            );
        }
        $warnings[] = __( 'Pages that use the shortcode show the change at once; the classic [bookly-form] and the AI chat do not read these settings; other modern forms are not changed.', 'bookly-responsive-appointment-booking-tool' );

        return array( 'rows' => $rows, 'warnings' => array_values( array_unique( $warnings ) ) );
    }

    /**
     * @inheritDoc
     */
    public function apply( array $args )
    {
        $row = FormData::row( $args['form_id'] );
        $form = self::current( new State(), (int) $row['id'] );
        $state = new State();
        $state->setItem( PHP_INT_MAX );
        $result = $this->evaluate( $form, $args, $state );
        $settings = $result['settings'];
        unset( $settings['custom_css'], $settings['token'] );

        return self::save( (int) $row['id'], $result['name'], $settings, isset( $settings['l10n'] ) ? $settings['l10n'] : array() );
    }

    /**
     * @inheritDoc
     */
    public function undo( array $snapshot )
    {
        self::restore( $snapshot );
    }
}
