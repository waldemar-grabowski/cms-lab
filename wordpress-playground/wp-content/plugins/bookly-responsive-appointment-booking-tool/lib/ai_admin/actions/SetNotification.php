<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\Format;
use Bookly\Lib\AiAdmin\State;
use Bookly\Lib\Entities\Notification;
use Bookly\Lib\Entities\SentNotification;

/**
 * set_notification — create a notification of the email, sms, voice or whatsapp channel or change
 * the given fields of one, as Save of the notification dialog writes the record
 * (assets/ai-setup/actions/set_notification.yaml).
 */
class SetNotification implements ActionInterface
{
    const ARGS = 'notification_id,gateway,type,name,active,subject,message,whatsapp,to_customer,to_staff,to_admin,to_organizer,to_custom,custom_recipients,attach_ics,attach_invoice,settings';
    const FLAGS = 'to_customer,to_staff,to_admin,to_organizer,to_custom';
    const OFFSET_HOURS = '1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,48,72,96,120,144,168,192,216,240,264,288,312,336,504,672,720';
    const OFFSET_BIDIRECTIONAL = '-8760,-4380,-2920,-2190,-1460,-672,-504,-336,-312,-288,-264,-240,-216,-192,-168,-144,-120,-96,-72,-48,-24,0,24,48,72,96,120,144,168,192,216,240,264,288,312,336,504,672,1460,2190,2920,4380,8760';
    const OFFSET_BEFORE = '-672,-504,-336,-312,-288,-264,-240,-216,-192,-168,-144,-120,-96,-72,-48,-24,0';

    /**
     * @param mixed $value
     * @return bool
     */
    public static function isId( $value )
    {
        return is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) );
    }

    /**
     * @param mixed $value
     * @return bool
     */
    protected static function isFlag( $value )
    {
        return $value === 0 || $value === 1 || $value === '0' || $value === '1';
    }

    /**
     * @param mixed $value
     * @return bool
     */
    protected static function isStringList( $value )
    {
        if ( ! is_array( $value ) || array_values( $value ) !== $value ) {
            return false;
        }
        foreach ( $value as $item ) {
            if ( ! is_string( $item ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string $text
     * @return int
     */
    protected static function length( $text )
    {
        return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
    }

    /**
     * Shape of the arguments (the JSON Schema of the action).
     *
     * @param array $args
     * @return string[]
     */
    protected static function shapeErrors( array $args )
    {
        $errors = array();
        $known  = explode( ',', self::ARGS );
        foreach ( array_keys( $args ) as $key ) {
            if ( ! in_array( $key, $known, true ) ) {
                $errors[] = 'arguments: unknown argument ' . $key;
            }
        }
        if ( array_key_exists( 'notification_id', $args ) && ! self::isId( $args['notification_id'] ) && ! ( is_string( $args['notification_id'] ) && preg_match( '/^\$\d+$/', $args['notification_id'] ) ) ) {
            $errors[] = 'arguments: notification_id is an integer id from list_notifications';
        }
        foreach ( array( 'type', 'name', 'subject', 'message' ) as $key ) {
            if ( array_key_exists( $key, $args ) && ! is_string( $args[ $key ] ) ) {
                $errors[] = 'arguments: ' . $key . ' is a string';
            }
        }
        foreach ( array( 'active', 'to_customer', 'to_staff', 'to_admin', 'to_organizer', 'to_custom', 'attach_ics', 'attach_invoice' ) as $key ) {
            if ( array_key_exists( $key, $args ) && ! self::isFlag( $args[ $key ] ) ) {
                $errors[] = 'arguments: ' . $key . ' is 0 or 1';
            }
        }
        if ( array_key_exists( 'whatsapp', $args ) ) {
            $w = $args['whatsapp'];
            if ( ! is_array( $w ) || ! isset( $w['template'], $w['language'] ) || ! is_string( $w['template'] ) || ! is_string( $w['language'] )
                || array_diff( array_keys( $w ), array( 'template', 'language', 'header', 'body' ) )
                || ( isset( $w['header'] ) && ! self::isStringList( $w['header'] ) ) || ( isset( $w['body'] ) && ! self::isStringList( $w['body'] ) ) ) {
                $errors[] = 'arguments: whatsapp is {template, language, header: [strings], body: [strings]}';
            }
        }
        if ( array_key_exists( 'custom_recipients', $args ) ) {
            $c = $args['custom_recipients'];
            if ( ! is_array( $c ) || ! $c || array_diff( array_keys( $c ), array( 'add', 'remove' ) )
                || ( isset( $c['add'] ) && ! self::isStringList( $c['add'] ) ) || ( isset( $c['remove'] ) && ! self::isStringList( $c['remove'] ) ) ) {
                $errors[] = 'arguments: custom_recipients is {add: [strings], remove: [strings]} — only the change of the list';
            }
        }
        if ( array_key_exists( 'settings', $args ) ) {
            $s = $args['settings'];
            $keys = array_merge( array( 'status', 'services', 'payment_statuses' ), explode( ',', NotificationData::TIMING_KEYS ) );
            if ( ! is_array( $s ) || ! $s || array_diff( array_keys( $s ), $keys ) ) {
                $errors[] = 'arguments: settings takes only ' . implode( ', ', $keys );
            } else {
                if ( isset( $s['status'] ) && ! is_string( $s['status'] ) ) {
                    $errors[] = 'arguments: settings.status is a string';
                }
                if ( array_key_exists( 'services', $s ) ) {
                    $v = $s['services'];
                    $bad = ! is_array( $v ) || ! $v || array_diff( array_keys( $v ), array( 'any', 'add', 'remove' ) )
                        || ( isset( $v['any'] ) && ! in_array( $v['any'], array( 'any', 'selected' ), true ) );
                    foreach ( array( 'add', 'remove' ) as $list ) {
                        if ( ! $bad && isset( $v[ $list ] ) ) {
                            $bad = ! is_array( $v[ $list ] ) || count( array_filter( $v[ $list ], array( __CLASS__, 'isId' ) ) ) !== count( $v[ $list ] );
                        }
                    }
                    if ( $bad ) {
                        $errors[] = 'arguments: settings.services is {any: any|selected, add: [service ids], remove: [service ids]}';
                    }
                }
                if ( array_key_exists( 'payment_statuses', $s ) ) {
                    $v = $s['payment_statuses'];
                    $bad = ! is_array( $v ) || ! $v || array_diff( array_keys( $v ), array( 'add', 'remove' ) );
                    foreach ( array( 'add', 'remove' ) as $list ) {
                        if ( ! $bad && isset( $v[ $list ] ) ) {
                            $bad = ! self::isStringList( $v[ $list ] ) || array_diff( $v[ $list ], array( 'pending', 'completed', 'no_payment' ) );
                        }
                    }
                    if ( $bad ) {
                        $errors[] = 'arguments: settings.payment_statuses is {add: [...], remove: [...]} of pending, completed, no_payment';
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Key of the notification in the state: its id, or "$N" of a new one.
     *
     * @param array $args
     * @param State $state
     * @return int|string
     */
    protected static function key( array $args, State $state )
    {
        if ( ! array_key_exists( 'notification_id', $args ) ) {
            return '$' . $state->item();
        }

        return self::isId( $args['notification_id'] ) ? (int) $args['notification_id'] : $args['notification_id'];
    }

    /**
     * The record after the change.
     *
     * @param array $before
     * @param array $args
     * @return array
     */
    public static function merge( array $before, array $args )
    {
        $after = $before;
        foreach ( array( 'name', 'subject', 'message' ) as $key ) {
            if ( array_key_exists( $key, $args ) ) {
                $after[ $key ] = (string) $args[ $key ];
            }
        }
        foreach ( array( 'active', 'to_customer', 'to_staff', 'to_admin', 'to_organizer', 'to_custom', 'attach_ics', 'attach_invoice' ) as $key ) {
            if ( array_key_exists( $key, $args ) ) {
                $after[ $key ] = (int) $args[ $key ];
            }
        }
        if ( isset( $args['custom_recipients'] ) && is_array( $args['custom_recipients'] ) ) {
            $gateway = $before['gateway'];
            $remove  = array();
            foreach ( isset( $args['custom_recipients']['remove'] ) ? $args['custom_recipients']['remove'] : array() as $entry ) {
                $remove[] = NotificationData::entryKey( $gateway, $entry );
            }
            $list = array();
            foreach ( $before['custom_recipients'] as $entry ) {
                if ( ! in_array( NotificationData::entryKey( $gateway, $entry ), $remove, true ) ) {
                    $list[] = $entry;
                }
            }
            foreach ( isset( $args['custom_recipients']['add'] ) ? $args['custom_recipients']['add'] : array() as $entry ) {
                $list[] = $gateway === 'email' ? trim( $entry ) : NotificationData::phone( $entry );
            }
            $after['custom_recipients'] = $list;
        }
        if ( isset( $args['settings'] ) && is_array( $args['settings'] ) ) {
            $s = $args['settings'];
            if ( isset( $s['status'] ) ) {
                $after['settings']['status'] = (string) $s['status'];
            }
            if ( isset( $s['services'] ) && is_array( $s['services'] ) ) {
                $services = $after['settings']['services'];
                if ( isset( $s['services']['any'] ) ) {
                    $services['any'] = $s['services']['any'];
                }
                $remove = isset( $s['services']['remove'] ) ? array_map( 'intval', $s['services']['remove'] ) : array();
                $ids    = array();
                foreach ( $services['ids'] as $id ) {
                    if ( ! in_array( $id, $remove, true ) ) {
                        $ids[] = $id;
                    }
                }
                foreach ( isset( $s['services']['add'] ) ? $s['services']['add'] : array() as $id ) {
                    $ids[] = (int) $id;
                }
                $services['ids'] = $ids;
                $after['settings']['services'] = $services;
            }
            if ( isset( $s['payment_statuses'] ) && is_array( $s['payment_statuses'] ) ) {
                $remove = isset( $s['payment_statuses']['remove'] ) ? $s['payment_statuses']['remove'] : array();
                $list   = array();
                foreach ( $after['settings']['payment_statuses'] as $status ) {
                    if ( ! in_array( $status, $remove, true ) ) {
                        $list[] = $status;
                    }
                }
                foreach ( isset( $s['payment_statuses']['add'] ) ? $s['payment_statuses']['add'] : array() as $status ) {
                    $list[] = $status;
                }
                $after['settings']['payment_statuses'] = $list;
            }
            foreach ( explode( ',', NotificationData::TIMING_KEYS ) as $key ) {
                if ( array_key_exists( $key, $s ) ) {
                    $after['settings'][ $key ] = $key === 'perform' ? (string) $s[ $key ] : (int) $s[ $key ];
                }
            }
        }
        if ( isset( $args['whatsapp'] ) && is_array( $args['whatsapp'] ) ) {
            $after['settings']['whatsapp'] = array(
                'template' => (string) $args['whatsapp']['template'],
                'language' => (string) $args['whatsapp']['language'],
                'header' => isset( $args['whatsapp']['header'] ) ? array_values( $args['whatsapp']['header'] ) : array(),
                'body' => isset( $args['whatsapp']['body'] ) ? array_values( $args['whatsapp']['body'] ) : array(),
            );
        }

        return $after;
    }

    /**
     * The record before the change and its key, or an error.
     *
     * @param array $args
     * @param State $state
     * @return array array( record|null, error|null )
     */
    protected static function before( array $args, State $state )
    {
        if ( array_key_exists( 'notification_id', $args ) ) {
            $key = self::key( $args, $state );
            $all = NotificationData::all( $state );
            if ( ! isset( $all[ $key ] ) || ! NotificationData::listed( $all[ $key ] ) ) {
                return array( null, 'notification_not_found: ' . wp_json_encode( $args['notification_id'] ) . ' — ids are in list_notifications of each gateway' );
            }

            return array( $all[ $key ], null );
        }
        $gateway = isset( $args['gateway'] ) ? $args['gateway'] : null;
        if ( ! in_array( $gateway, NotificationData::gateways(), true ) ) {
            return array( null, 'gateway_required_on_create: a new notification needs gateway email, sms, voice or whatsapp' );
        }
        if ( ! isset( $args['type'] ) || $args['type'] === '' ) {
            return array( null, 'type_required_on_create: a new notification needs a type of list_notifications.allowed.types' );
        }
        $record = NotificationData::newRecord( $gateway, (string) $args['type'] );
        $record['id'] = '$' . $state->item();

        return array( $record, null );
    }

    /**
     * @inheritDoc
     */
    public function validate( array $args, State $state )
    {
        if ( ! Access::allowed( array( 'admin' ) ) ) {
            return array( 'forbidden: the current user is not a Bookly administrator' );
        }
        $errors = self::shapeErrors( $args );
        if ( $errors ) {
            return $errors;
        }
        $new = ! array_key_exists( 'notification_id', $args );
        if ( ! $new && array_key_exists( 'gateway', $args ) ) {
            $errors[] = 'gateway_given_on_update: the channel of an existing notification is not changed — create a new notification and disable the old one';
        }
        if ( ! $new && array_key_exists( 'type', $args ) ) {
            $errors[] = 'type_given_on_update: the type of an existing notification is not changed — create a new notification and disable the old one';
        }
        if ( $errors ) {
            return $errors;
        }
        list( $before, $error ) = self::before( $args, $state );
        if ( $error ) {
            return array( $error );
        }
        $gateway = $before['gateway'];
        if ( $error = NotificationData::channelError( $gateway ) ) {
            return array( $error );
        }
        $types = NotificationData::allowedTypes( $gateway );
        if ( ! isset( $types[ $before['type'] ] ) ) {
            return array( 'type_unavailable: ' . $before['type'] . ' is not offered for ' . $gateway . ' notifications on this site — list_notifications.allowed.types (gateway ' . $gateway . ')' );
        }
        $type   = $types[ $before['type'] ];
        $after  = self::merge( $before, $args );
        $errors = array_merge(
            $this->fieldErrors( $args, $before, $after, $type, $new ),
            $this->recipientErrors( $args, $before, $after, $type ),
            $this->settingsErrors( $args, $before, $after, $type ),
            $this->whatsappErrors( $args, $after, $new )
        );
        if ( ! $errors && ! $new && $after === $before ) {
            $errors[] = 'nothing_to_change: every given value equals the stored one';
        }
        if ( ! $errors ) {
            NotificationData::put( $state, self::key( $args, $state ), $after );
            if ( $new ) {
                $state->created( 'notification' );
            }
        }

        return $errors;
    }

    /**
     * Name, subject, text, attachments.
     *
     * @param array $args
     * @param array $before
     * @param array $after
     * @param array $type
     * @param bool  $new
     * @return string[]
     */
    protected function fieldErrors( array $args, array $before, array $after, array $type, $new )
    {
        $errors  = array();
        $gateway = $after['gateway'];
        if ( $gateway !== 'email' && array_key_exists( 'subject', $args ) ) {
            $errors[] = 'subject_not_for_sms: a ' . $gateway . ' notification has no subject';
        }
        if ( $gateway === 'whatsapp' && array_key_exists( 'message', $args ) ) {
            $errors[] = 'message_not_for_whatsapp: a WhatsApp notification has no text — give `whatsapp` (template and values of its variables)';
        }
        if ( $gateway !== 'whatsapp' && array_key_exists( 'whatsapp', $args ) ) {
            $errors[] = 'message_not_for_whatsapp: `whatsapp` is only for a WhatsApp notification — give message';
        }
        if ( $new && $gateway === 'whatsapp' && ! array_key_exists( 'whatsapp', $args ) ) {
            $errors[] = 'whatsapp_required_on_create: a new WhatsApp notification needs `whatsapp` — template, language and the values of its variables (list_whatsapp_templates)';
        }
        if ( $new ) {
            $missing = array();
            if ( trim( $after['name'] ) === '' ) {
                $missing[] = 'name';
            }
            if ( $gateway !== 'whatsapp' && trim( $after['message'] ) === '' ) {
                $missing[] = 'message';
            }
            if ( $gateway === 'email' && trim( $after['subject'] ) === '' ) {
                $missing[] = 'subject';
            }
            $recipient = false;
            foreach ( $type['recipients'] as $r ) {
                $recipient = $recipient || ! empty( $after[ 'to_' . $r ] );
            }
            if ( ! $recipient ) {
                $missing[] = 'a recipient (' . implode( ', ', $type['recipients'] ) . ')';
            }
            if ( $missing ) {
                $errors[] = 'create_fields_missing: a new notification needs ' . implode( ', ', $missing );
            }
        }
        if ( array_key_exists( 'name', $args ) && self::length( $after['name'] ) > 255 ) {
            $errors[] = 'name_too_long: name is at most 255 characters';
        }
        if ( $gateway === 'email' && array_key_exists( 'subject', $args ) && self::length( $after['subject'] ) > 255 ) {
            $errors[] = 'subject_too_long: subject is at most 255 characters';
        }
        $codes = NotificationData::codes( $gateway, $after['type'] );
        foreach ( array( 'subject', 'message' ) as $field ) {
            if ( ! array_key_exists( $field, $args ) || ( $field === 'subject' && $gateway !== 'email' ) || ( $field === 'message' && $gateway === 'whatsapp' ) ) {
                continue;
            }
            $check = NotificationData::checkText( $after[ $field ], $codes );
            if ( $check['unknown'] ) {
                $errors[] = 'unknown_code: ' . $field . ' uses ' . implode( ', ', $check['unknown'] ) . ' — not a code of ' . $after['type'] . ' ' . $gateway . ' notifications (get_notification_codes, gateway ' . $gateway . ')';
            }
            if ( $check['unbalanced'] ) {
                $errors[] = 'unbalanced_template: ' . $field . ' — ' . implode( '; ', $check['unbalanced'] );
            }
        }
        if ( $after['type'] === Notification::TYPE_VERIFY_PHONE && in_array( $gateway, array( 'sms', 'voice' ), true )
            && ( strpos( $after['message'], '{verification_code}' ) === false || ! $after['to_customer'] ) ) {
            $errors[] = 'verification_code_required: the verify_phone text must contain {verification_code} and Client must stay a recipient — otherwise customers cannot finish a booking';
        }
        foreach ( array( 'attach_ics' => 'ics', 'attach_invoice' => 'invoice' ) as $field => $attach ) {
            if ( ! array_key_exists( $field, $args ) ) {
                continue;
            }
            if ( $gateway !== 'email' ) {
                $errors[] = 'attach_not_for_type: ' . $field . ' — no ' . $gateway . ' notification has attachments';
            } elseif ( $after[ $field ] && ! in_array( $attach, $type['attach'], true ) ) {
                $errors[] = 'attach_not_for_type: ' . $field . ' is not offered for ' . $after['type'] . ' (attach: ' . ( $type['attach'] ? implode( ', ', $type['attach'] ) : 'none' ) . ')';
            } elseif ( $field === 'attach_invoice' && $after[ $field ] && ! Lib\Config::invoicesActive() ) {
                $errors[] = 'invoices_inactive: Attach invoice needs the Invoices add-on';
            }
        }

        return $errors;
    }

    /**
     * Recipient flags and custom addresses / numbers.
     *
     * @param array $args
     * @param array $before
     * @param array $after
     * @param array $type
     * @return string[]
     */
    protected function recipientErrors( array $args, array $before, array $after, array $type )
    {
        $errors  = array();
        $gateway = $after['gateway'];
        foreach ( explode( ',', self::FLAGS ) as $flag ) {
            if ( array_key_exists( $flag, $args ) && $after[ $flag ] && ! in_array( substr( $flag, 3 ), $type['recipients'], true ) ) {
                $errors[] = 'recipient_not_for_type: ' . $flag . ' — ' . $after['type'] . ' is sent only to ' . implode( ', ', $type['recipients'] );
            }
        }
        if ( ! array_key_exists( 'custom_recipients', $args ) ) {
            if ( array_key_exists( 'to_custom', $args ) && $after['to_custom'] && ! $after['custom_recipients'] ) {
                $errors[] = 'custom_needs_addresses: Custom needs at least one entry in custom_recipients';
            }

            return $errors;
        }
        $add    = isset( $args['custom_recipients']['add'] ) ? $args['custom_recipients']['add'] : array();
        $remove = isset( $args['custom_recipients']['remove'] ) ? $args['custom_recipients']['remove'] : array();
        $stored = array();
        foreach ( $before['custom_recipients'] as $entry ) {
            $stored[] = NotificationData::entryKey( $gateway, $entry );
        }
        if ( $add && ! $after['to_custom'] ) {
            $errors[] = 'custom_addresses_need_custom: entries are added only while Custom is on (to_custom 1)';
        }
        $seen = array();
        foreach ( $add as $entry ) {
            if ( $gateway === 'email' ) {
                if ( ! is_email( trim( $entry ) ) || preg_match( '/\s/', trim( $entry ) ) ) {
                    $errors[] = 'custom_address_invalid: ' . $entry . ' is not one email address';
                    continue;
                }
            } else {
                $digits = preg_replace( '/\D/', '', $entry );
                if ( ! preg_match( '/^\s*\+?[\d\s().\-]+$/', $entry ) || strlen( $digits ) < 7 || strlen( $digits ) > 15 ) {
                    $errors[] = 'custom_phone_invalid: ' . $entry . ' is not one phone number (optional "+" and 7–15 digits, one number per entry)';
                    continue;
                }
            }
            $key = NotificationData::entryKey( $gateway, $entry );
            if ( in_array( $key, $stored, true ) || isset( $seen[ $key ] ) ) {
                $errors[] = 'recipient_already_present: ' . $entry . ' is in the list already';
            }
            $seen[ $key ] = true;
        }
        foreach ( $remove as $entry ) {
            if ( ! in_array( NotificationData::entryKey( $gateway, $entry ), $stored, true ) ) {
                $errors[] = 'recipient_not_present: ' . $entry . ' is not in the list (get_notification.custom_recipients)';
            }
        }
        if ( $after['to_custom'] && ! $after['custom_recipients'] ) {
            $errors[] = 'custom_needs_addresses: Custom needs at least one entry in custom_recipients';
        }
        if ( self::length( implode( "\n", $after['custom_recipients'] ) ) > 255 ) {
            $errors[] = 'custom_recipients_too_long: the entries joined one per line are longer than 255 characters';
        }

        return $errors;
    }

    /**
     * Conditions and send time.
     *
     * @param array $args
     * @param array $before
     * @param array $after
     * @param array $type
     * @return string[]
     */
    protected function settingsErrors( array $args, array $before, array $after, array $type )
    {
        if ( ! isset( $args['settings'] ) ) {
            return array();
        }
        $errors = array();
        $s      = $args['settings'];
        $name   = $after['type'];
        $status_types = explode( ',', NotificationData::STATUS_TYPES );
        foreach ( array( 'status', 'services' ) as $key ) {
            if ( array_key_exists( $key, $s ) && ! in_array( $name, $status_types, true ) ) {
                $errors[] = 'condition_not_for_type: settings.' . $key . ' is only for ' . implode( ', ', $status_types );
            }
        }
        if ( array_key_exists( 'payment_statuses', $s ) && $name !== Notification::TYPE_NEW_ATTENDEES ) {
            $errors[] = 'condition_not_for_type: settings.payment_statuses is only for new_attendees (Events)';
        }
        if ( isset( $s['status'] ) && ! in_array( $s['status'], NotificationData::statuses(), true ) ) {
            $errors[] = 'status_unknown: ' . $s['status'] . ' — one of ' . implode( ', ', NotificationData::statuses() );
        }
        if ( isset( $s['services'] ) ) {
            $v       = $s['services'];
            $ids_now = $before['settings']['services']['ids'];
            if ( ( ! empty( $v['add'] ) || ! empty( $v['remove'] ) ) && $after['settings']['services']['any'] !== 'selected' ) {
                $errors[] = 'services_ids_need_selected: the chosen services change only while services.any is "selected"';
            }
            foreach ( isset( $v['add'] ) ? $v['add'] : array() as $id ) {
                $service = Lib\Entities\Service::find( (int) $id );
                if ( ! $service || $service->getType() === Lib\Entities\Service::TYPE_PACKAGE ) {
                    $errors[] = 'service_unknown: ' . $id . ' is not a service of list_services (packages are not offered)';
                } elseif ( in_array( (int) $id, $ids_now, true ) ) {
                    $errors[] = 'service_already_selected: ' . $id;
                }
            }
            foreach ( isset( $v['remove'] ) ? $v['remove'] : array() as $id ) {
                if ( ! in_array( (int) $id, $ids_now, true ) ) {
                    $errors[] = 'service_not_selected: ' . $id;
                }
            }
            if ( $after['settings']['services']['any'] === 'selected' && ! $after['settings']['services']['ids'] ) {
                $errors[] = 'selected_needs_services: "selected" with no service never sends — add services or use "any"';
            }
        }
        if ( isset( $s['payment_statuses'] ) ) {
            $now = $before['settings']['payment_statuses'];
            foreach ( isset( $s['payment_statuses']['add'] ) ? $s['payment_statuses']['add'] : array() as $status ) {
                if ( in_array( $status, $now, true ) ) {
                    $errors[] = 'payment_status_already_selected: ' . $status;
                }
            }
            foreach ( isset( $s['payment_statuses']['remove'] ) ? $s['payment_statuses']['remove'] : array() as $status ) {
                if ( ! in_array( $status, $now, true ) ) {
                    $errors[] = 'payment_status_not_selected: ' . $status;
                }
            }
            if ( ! $after['settings']['payment_statuses'] ) {
                $errors[] = 'payment_statuses_empty: with no payment status the notification is never sent';
            }
        }
        $timing = array_intersect( array_keys( $s ), explode( ',', NotificationData::TIMING_KEYS ) );
        if ( $timing ) {
            if ( ! $type['timing_options'] ) {
                $errors[] = 'timing_not_for_type: ' . $name . ' has no send time — it is sent at the event';

                return $errors;
            }
            $option = $after['settings']['option'];
            if ( ! in_array( $option, $type['timing_options'], true ) ) {
                $errors[] = 'option_not_for_type: option ' . $option . ' — ' . $name . ' takes ' . implode( ' or ', $type['timing_options'] );
            }
            $need = array(
                'offset_hours' => 1,
                'perform' => 1,
                'offset_bidirectional_hours' => 2,
                'at_hour' => 2,
            );
            foreach ( $need as $key => $opt ) {
                if ( array_key_exists( $key, $s ) && $option !== $opt ) {
                    $errors[] = 'timing_key_not_for_option: ' . $key . ' is used only with option ' . $opt . ' (option is ' . $option . ')';
                }
            }
            foreach ( array( 'offset_before_hours', 'before_at_hour' ) as $key ) {
                if ( array_key_exists( $key, $s ) && $name !== Notification::TYPE_STAFF_DAY_AGENDA ) {
                    $errors[] = 'timing_key_not_for_option: ' . $key . ' is only for staff_day_agenda';
                }
            }
            $lists = array(
                'option' => array( 1, 2, 3 ),
                'offset_hours' => array_map( 'intval', explode( ',', self::OFFSET_HOURS ) ),
                'offset_bidirectional_hours' => array_map( 'intval', explode( ',', self::OFFSET_BIDIRECTIONAL ) ),
                'offset_before_hours' => array_map( 'intval', explode( ',', self::OFFSET_BEFORE ) ),
                'at_hour' => range( 0, 23 ),
                'before_at_hour' => range( 0, 23 ),
            );
            foreach ( $lists as $key => $values ) {
                if ( array_key_exists( $key, $s ) && ( ! is_int( $s[ $key ] ) || ! in_array( $s[ $key ], $values, true ) ) ) {
                    $errors[] = 'timing_value_not_listed: settings.' . $key . ' = ' . wp_json_encode( $s[ $key ] );
                }
            }
            if ( array_key_exists( 'perform', $s ) && ! in_array( $s['perform'], array( 'before', 'after' ), true ) ) {
                $errors[] = 'timing_value_not_listed: settings.perform is before or after';
            }
        }

        return $errors;
    }

    /**
     * The WhatsApp template and the values of its variables.
     *
     * @param array $args
     * @param array $after
     * @param bool  $new
     * @return string[]
     */
    protected function whatsappErrors( array $args, array $after, $new )
    {
        if ( $after['gateway'] !== 'whatsapp' || ! isset( $args['whatsapp'] ) ) {
            return array();
        }
        $w    = $after['settings']['whatsapp'];
        $list = NotificationData::whatsappTemplates();
        if ( $list['error'] !== null ) {
            return array( 'whatsapp_template_not_found: the template list cannot be read (' . $list['error'] . ') — the owner checks the credentials in Bookly Cloud → WhatsApp → Settings' );
        }
        if ( ! $list['templates'] ) {
            return array( 'whatsapp_template_not_found: the Meta account has no templates, or the credentials in Bookly Cloud → WhatsApp → Settings are wrong — the owner checks them' );
        }
        $tpl = NotificationData::findTemplate( $w['template'], $w['language'] );
        if ( ! $tpl ) {
            return array( 'whatsapp_template_not_found: no template ' . $w['template'] . ' (' . $w['language'] . ') in list_whatsapp_templates — name and language together' );
        }
        if ( $tpl['status'] !== 'APPROVED' ) {
            return array( 'whatsapp_template_not_approved: ' . $tpl['name'] . ' (' . $tpl['language'] . ') is ' . $tpl['status'] . ' — Meta delivers only APPROVED templates' );
        }
        if ( ! $tpl['usable'] ) {
            return array( 'whatsapp_template_unsupported: ' . $tpl['name'] . ' (' . $tpl['language'] . ') — ' . $tpl['reason'] );
        }
        $errors = array();
        foreach ( array( 'header', 'body' ) as $part ) {
            $need = count( $tpl[ $part . '_variables' ] );
            if ( count( $w[ $part ] ) !== $need ) {
                $errors[] = 'whatsapp_variables_count: ' . $part . ' needs ' . $need . ' value(s) — one for each of ' . ( $need ? '{{' . implode( '}}, {{', $tpl[ $part . '_variables' ] ) . '}}' : 'none' ) . ', got ' . count( $w[ $part ] );
            }
        }
        $codes = NotificationData::codes( 'whatsapp', $after['type'] );
        foreach ( array( 'header', 'body' ) as $part ) {
            foreach ( $w[ $part ] as $i => $value ) {
                if ( trim( $value ) === '' ) {
                    $errors[] = 'whatsapp_variable_empty: ' . $part . '[' . $i . '] is empty';
                    continue;
                }
                $check = NotificationData::checkText( $value, $codes, false );
                if ( $check['blocks'] ) {
                    $errors[] = 'whatsapp_variable_codes: ' . $part . '[' . $i . '] has ' . implode( ', ', $check['blocks'] ) . ' — a variable takes plain text with codes, no {#if} or {#each}';
                }
                if ( $check['unknown'] ) {
                    $errors[] = 'whatsapp_variable_codes: ' . $part . '[' . $i . '] uses ' . implode( ', ', $check['unknown'] ) . ' — not a code of ' . $after['type'] . ' (get_notification_codes, gateway whatsapp)';
                }
            }
        }

        return $errors;
    }

    /**
     * @inheritDoc
     */
    public function preview( array $args, State $state )
    {
        $new = ! array_key_exists( 'notification_id', $args );
        list( $before ) = self::before( $args, $state );
        $after   = self::merge( $before, $args );
        $gateway = $after['gateway'];
        $key     = self::key( $args, $state );
        $link    = NotificationData::link( $gateway );
        $object  = Format::object( 'notification:' . $key, $after['name'], NotificationData::newTitle( $gateway ), $new );
        $rows    = array();
        $add_row = function ( $field, $old, $value, $options = array() ) use ( &$rows, $object, $link, $new ) {
            if ( $new || $old !== $value ) {
                $rows[] = array(
                    'label' => Format::rowLabel( $object, $field, $options ),
                    'before' => $new ? '—' : $old,
                    'after' => $value,
                    'link' => $link,
                );
            }
        };
        $types = NotificationData::allowedTypes( $gateway );
        $type  = $types[ $after['type'] ];
        $add_row( __( 'Name', 'bookly-responsive-appointment-booking-tool' ), $before['name'], $after['name'], array( 'name' => $new ) );
        if ( $new ) {
            $add_row( __( 'Type', 'bookly-responsive-appointment-booking-tool' ), '', $type['title'] . ' (' . NotificationData::channelName( $gateway ) . ')' );
        }
        $add_row( __( 'State', 'bookly-responsive-appointment-booking-tool' ), CloudAccount::onOff( $before['active'] ), CloudAccount::onOff( $after['active'] ) );
        $who = function ( $record ) {
            $names = array();
            foreach ( explode( ',', SetNotification::FLAGS ) as $flag ) {
                if ( $record[ $flag ] ) {
                    $names[] = NotificationData::recipientName( $flag );
                }
            }

            return $names ? implode( ', ', $names ) : '—';
        };
        $add_row( __( 'Recipients', 'bookly-responsive-appointment-booking-tool' ), $who( $before ), $who( $after ) );
        $custom = function ( $record ) {
            return $record['custom_recipients'] ? implode( ', ', $record['custom_recipients'] ) : '—';
        };
        if ( $after['custom_recipients'] || ! $new ) {
            $add_row( __( 'Custom', 'bookly-responsive-appointment-booking-tool' ), $custom( $before ), $custom( $after ) );
        }
        if ( $gateway === 'email' ) {
            $add_row( __( 'Subject', 'bookly-responsive-appointment-booking-tool' ), $before['subject'], $after['subject'] );
        }
        if ( $gateway !== 'whatsapp' ) {
            $add_row( __( 'Body', 'bookly-responsive-appointment-booking-tool' ), $before['message'], $after['message'] );
            if ( $new || $before['message'] !== $after['message'] || ( $gateway === 'email' && $before['subject'] !== $after['subject'] ) ) {
                $sample = function ( $record ) use ( $gateway ) {
                    $text = NotificationData::example( $record['message'], $gateway, $record['type'] );
                    if ( $gateway === 'email' && $record['subject'] !== '' ) {
                        $text = NotificationData::example( $record['subject'], $gateway, $record['type'] ) . "\n\n" . $text;
                    }

                    return $text;
                };
                $add_row( $gateway === 'voice' ? __( 'Read aloud (sample values)', 'bookly-responsive-appointment-booking-tool' ) : __( 'As sent (sample values)', 'bookly-responsive-appointment-booking-tool' ), $new ? '' : $sample( $before ), $sample( $after ) );
            }
        }
        if ( $gateway === 'sms' && ( $new || $before['message'] !== $after['message'] ) ) {
            $length = function ( $record ) {
                $count = Lib\Utils\SMSCounter::count( NotificationData::example( $record['message'], 'sms', $record['type'] ) );

                return sprintf( __( '%1$d characters · %2$d SMS', 'bookly-responsive-appointment-booking-tool' ), $count->length, $count->messages );
            };
            $add_row( __( 'Length', 'bookly-responsive-appointment-booking-tool' ), $new ? '' : $length( $before ), $length( $after ) );
        }
        if ( $gateway === 'whatsapp' ) {
            $wa = function ( $record ) {
                $w = $record['settings']['whatsapp'];
                if ( $w['template'] === '' ) {
                    return '—';
                }
                $values = array();
                foreach ( array( 'header', 'body' ) as $part ) {
                    foreach ( $w[ $part ] as $i => $value ) {
                        $values[] = $part . ' {{' . ( $i + 1 ) . '}} ← ' . $value;
                    }
                }

                return $w['template'] . ' (' . $w['language'] . ')' . ( $values ? '; ' . implode( '; ', $values ) : '' );
            };
            $add_row( __( 'Template', 'bookly-responsive-appointment-booking-tool' ), $wa( $before ), $wa( $after ) );
            if ( isset( $args['whatsapp'] ) ) {
                $tpl = NotificationData::findTemplate( $after['settings']['whatsapp']['template'], $after['settings']['whatsapp']['language'] );
                if ( $tpl ) {
                    $add_row( __( 'As sent (sample values)', 'bookly-responsive-appointment-booking-tool' ), '', self::whatsappSample( $tpl, $after ) );
                }
            }
        }
        if ( $gateway === 'email' ) {
            $attach = function ( $record ) {
                $list = array();
                if ( $record['attach_ics'] ) {
                    $list[] = __( 'ICS file', 'bookly-responsive-appointment-booking-tool' );
                }
                if ( $record['attach_invoice'] ) {
                    $list[] = __( 'Invoice', 'bookly-responsive-appointment-booking-tool' );
                }

                return $list ? implode( ', ', $list ) : '—';
            };
            if ( $type['attach'] ) {
                $add_row( __( 'Attachments', 'bookly-responsive-appointment-booking-tool' ), $attach( $before ), $attach( $after ) );
            }
        }
        if ( in_array( $after['type'], explode( ',', NotificationData::STATUS_TYPES ), true ) ) {
            $add_row( __( 'Appointment status', 'bookly-responsive-appointment-booking-tool' ), NotificationData::statusText( $before['settings']['status'] ), NotificationData::statusText( $after['settings']['status'] ) );
            $add_row( __( 'Services', 'bookly-responsive-appointment-booking-tool' ), NotificationData::servicesText( $before ), NotificationData::servicesText( $after ) );
        }
        if ( $after['type'] === Notification::TYPE_NEW_ATTENDEES ) {
            $pay = function ( $record ) {
                $names = array();
                foreach ( $record['settings']['payment_statuses'] as $status ) {
                    $names[] = $status === 'no_payment' ? __( 'No payment', 'bookly-responsive-appointment-booking-tool' ) : Lib\Entities\Payment::statusToString( $status );
                }

                return $names ? implode( ', ', $names ) : '—';
            };
            $add_row( __( 'Associated payment status', 'bookly-responsive-appointment-booking-tool' ), $pay( $before ), $pay( $after ) );
        }
        if ( $type['timing_options'] ) {
            $add_row( __( 'Send', 'bookly-responsive-appointment-booking-tool' ), NotificationData::whenText( $before ), NotificationData::whenText( $after ) );
        }

        NotificationData::put( $state, $key, $after );
        if ( $new ) {
            $state->created( 'notification' );
        }
        $warnings = array_merge(
            $this->changeWarnings( $args, $new ? null : $before, $after, $type ),
            NotificationData::consequences( $new ? null : $before, $after, $state )
        );

        return array( 'rows' => $rows, 'warnings' => $warnings );
    }

    /**
     * Template text with the values of the variables, codes with sample values.
     *
     * @param array $tpl
     * @param array $record
     * @return string
     */
    protected static function whatsappSample( array $tpl, array $record )
    {
        $codes = NotificationData::samples( NotificationData::codes( 'whatsapp', $record['type'] ) );
        $out   = array();
        foreach ( array( 'header', 'body' ) as $part ) {
            $text = $tpl[ $part ];
            if ( $text === null || $text === '' ) {
                continue;
            }
            foreach ( $tpl[ $part . '_variables' ] as $i => $number ) {
                $value = isset( $record['settings']['whatsapp'][ $part ][ $i ] ) ? $record['settings']['whatsapp'][ $part ][ $i ] : '';
                $text  = str_replace( '{{' . $number . '}}', Lib\Utils\Codes::replace( $value, $codes, false ), $text );
            }
            $out[] = $text;
        }

        return implode( "\n", $out );
    }

    /**
     * Blocks of the card that follow from what this item changes.
     *
     * @param array      $args
     * @param array|null $before null for a new notification
     * @param array      $after
     * @param array      $type
     * @return string[]
     */
    protected function changeWarnings( array $args, $before, array $after, array $type )
    {
        $warnings = array();
        $gateway  = $after['gateway'];
        $when     = NotificationData::whenText( $after );
        $text_changed = $before === null || $before['message'] !== $after['message'];
        $switch_on    = $after['active'] && ( $before === null || ! $before['active'] );
        $send_as_html = get_option( 'bookly_email_send_as' ) === 'html';

        if ( $before !== null ) {
            // added / removed
            foreach ( explode( ',', self::FLAGS ) as $flag ) {
                if ( $flag === 'to_custom' || $before[ $flag ] === $after[ $flag ] ) {
                    continue;
                }
                $warnings[] = $after[ $flag ]
                    ? sprintf( __( '%1$s will now receive it: %2$s.', 'bookly-responsive-appointment-booking-tool' ), NotificationData::recipientName( $flag ), $when )
                    : sprintf( __( '%s stops receiving it.', 'bookly-responsive-appointment-booking-tool' ), NotificationData::recipientName( $flag ) );
            }
            $old_keys = array();
            foreach ( $before['custom_recipients'] as $entry ) {
                $old_keys[] = NotificationData::entryKey( $gateway, $entry );
            }
            $new_keys = array();
            foreach ( $after['custom_recipients'] as $entry ) {
                $new_keys[] = NotificationData::entryKey( $gateway, $entry );
                if ( ! in_array( NotificationData::entryKey( $gateway, $entry ), $old_keys, true ) ) {
                    $warnings[] = sprintf( __( '%1$s will now receive this notification: %2$s.', 'bookly-responsive-appointment-booking-tool' ), $entry, $when );
                }
            }
            foreach ( $before['custom_recipients'] as $entry ) {
                if ( ! in_array( NotificationData::entryKey( $gateway, $entry ), $new_keys, true ) ) {
                    $warnings[] = sprintf( __( '%s no longer receives it.', 'bookly-responsive-appointment-booking-tool' ), $entry );
                }
            }
            if ( $after['settings']['services']['any'] === 'selected' ) {
                foreach ( array_diff( $after['settings']['services']['ids'], $before['settings']['services']['ids'] ) as $id ) {
                    $warnings[] = sprintf( __( 'Now also sent for %s.', 'bookly-responsive-appointment-booking-tool' ), NotificationData::serviceTitle( $id ) );
                }
                foreach ( array_diff( $before['settings']['services']['ids'], $after['settings']['services']['ids'] ) as $id ) {
                    $warnings[] = sprintf( __( 'No longer sent for %s.', 'bookly-responsive-appointment-booking-tool' ), NotificationData::serviceTitle( $id ) );
                }
            }
            // no_recipients
            $anyone = $after['to_customer'] || $after['to_staff'] || $after['to_admin'] || $after['to_organizer'] || ( $after['to_custom'] && $after['custom_recipients'] );
            if ( ! $anyone ) {
                $warnings[] = __( 'Nobody receives it.', 'bookly-responsive-appointment-booking-tool' );
            }
            // whatsapp_template_state
            if ( $gateway === 'whatsapp' && $after['active'] && ! isset( $args['whatsapp'] ) ) {
                $status = NotificationData::templateStatus( $after );
                if ( $status !== null && $status !== 'APPROVED' ) {
                    $w = $after['settings']['whatsapp'];
                    $warnings[] = sprintf(
                        __( 'Template %1$s (%2$s) is %3$s — Meta does not deliver it. Pick another approved template.', 'bookly-responsive-appointment-booking-tool' ),
                        $w['template'], $w['language'], $status === 'missing' ? __( 'not in the list any more', 'bookly-responsive-appointment-booking-tool' ) : $status
                    );
                }
            }
        }
        // html_codes_in_text
        if ( $gateway === 'email' && $text_changed && ! $send_as_html
            && preg_match( '/{(company_logo|service_image|staff_photo|category_image)}/', $after['message'] ) ) {
            $warnings[] = __( 'Emails go out as plain text: picture codes print empty in appointment notifications and as a bare image URL in staff agenda, packages, events and gift cards.', 'bookly-responsive-appointment-booking-tool' );
        }
        // sms_length
        if ( $gateway === 'sms' && $text_changed ) {
            $count = Lib\Utils\SMSCounter::count( NotificationData::example( $after['message'], $gateway, $after['type'] ) );
            if ( $count->messages > 1 ) {
                $warnings[] = sprintf( __( 'Each recipient gets %d SMS, each paid from the Bookly Cloud balance by the recipient\'s country (SMS Notifications → Price list).', 'bookly-responsive-appointment-booking-tool' ), $count->messages );
            }
            if ( preg_match( '/{(company_address|cart_info|cart_info_c|next_day_agenda)}/', $after['message'] ) ) {
                $warnings[] = __( 'The length is counted with sample values; a long code (address, cart, agenda) can make the real SMS longer.', 'bookly-responsive-appointment-booking-tool' );
            }
        }
        // voice_call, voice_text_markup
        if ( $gateway === 'voice' ) {
            if ( $text_changed || $switch_on ) {
                $language = Lib\Cloud\API::getInstance()->getProduct( Lib\Cloud\Account::PRODUCT_VOICE )->language;
                $warnings[] = sprintf(
                    __( 'Each recipient is called and the text is read aloud by the robot in %s (one language for all calls, Voice Notifications → Settings) — a text in another language is mispronounced. Nobody answering or a busy line is only logged in Voice Details — the plugin does not call again.', 'bookly-responsive-appointment-booking-tool' ),
                    $language ?: '—'
                );
            }
            if ( $text_changed && ( preg_match( '~https?://|<[a-z/][^>]*>~i', $after['message'] ) ) ) {
                $warnings[] = __( 'The robot reads this aloud, character by character — a link or markup is useless in a call.', 'bookly-responsive-appointment-booking-tool' );
            }
            if ( $text_changed && $send_as_html && strpos( $after['message'], '{next_day_agenda}' ) !== false ) {
                $warnings[] = __( 'While Send emails as is HTML the agenda is built as an HTML table and the robot reads the tags (set Email Notifications → Settings to Text, or keep the agenda out of calls).', 'bookly-responsive-appointment-booking-tool' );
            }
        }
        // whatsapp_language, whatsapp_agenda_markup
        if ( $gateway === 'whatsapp' && isset( $args['whatsapp'] ) ) {
            $w = $after['settings']['whatsapp'];
            $warnings[] = sprintf( __( 'Sent in %s to every recipient — the client\'s own language is not used.', 'bookly-responsive-appointment-booking-tool' ), $w['language'] );
            if ( $send_as_html && strpos( implode( ' ', array_merge( $w['header'], $w['body'] ) ), '{next_day_agenda}' ) !== false ) {
                $warnings[] = __( 'The agenda becomes an HTML table and goes into the template parameter as it is; Meta accepts neither it nor line breaks in a parameter. Use another code.', 'bookly-responsive-appointment-booking-tool' );
            }
        }
        // admin_phone_empty
        if ( $gateway !== 'email' && $after['to_admin'] && ( $before === null || ! $before['to_admin'] ) ) {
            $phone = trim( (string) get_option( 'bookly_sms_administrator_phone' ) );
            $warnings[] = $phone === ''
                ? __( 'Administrators get nothing: the administrator phone is empty (SMS / Voice / WhatsApp Notifications → Administrator phone).', 'bookly-responsive-appointment-booking-tool' )
                : sprintf( __( 'Administrators means this one number, %s, the same one SMS, Voice and WhatsApp use — not every administrator.', 'bookly-responsive-appointment-booking-tool' ), $phone );
        }
        // phone_without_prefix
        if ( $gateway !== 'email' && isset( $args['custom_recipients']['add'] ) ) {
            $code = trim( (string) get_option( 'bookly_cst_default_country_code' ) );
            foreach ( $args['custom_recipients']['add'] as $entry ) {
                $entry = trim( $entry );
                if ( strpos( $entry, '+' ) !== 0 && strpos( $entry, '00' ) !== 0 ) {
                    $warnings[] = sprintf(
                        __( '%1$s has no international prefix: Bookly puts the Default country code in front of it (%2$s).', 'bookly-responsive-appointment-booking-tool' ),
                        $entry, $code !== '' ? $code : __( 'none set — the digits go as they are', 'bookly-responsive-appointment-booking-tool' )
                    );
                }
            }
        }
        // hidden_recipients
        foreach ( explode( ',', self::FLAGS ) as $flag ) {
            if ( $after[ $flag ] && ! in_array( substr( $flag, 3 ), $type['recipients'], true ) ) {
                $warnings[] = sprintf( __( '%s is ticked but not offered for this type — it is kept as stored.', 'bookly-responsive-appointment-booking-tool' ), NotificationData::recipientName( $flag ) );
            }
        }
        // timing_window
        if ( $type['timing_options'] && ( $before === null || $switch_on || $before['settings'] != $after['settings'] ) ) {
            $warnings[] = __( 'A scheduled notification goes out within the retry period after the planned moment; a run missed for longer is lost. The hour is in the site time zone, not the client\'s.', 'bookly-responsive-appointment-booking-tool' );
        }

        return $warnings;
    }

    /**
     * @inheritDoc
     */
    public function apply( array $args )
    {
        $new = ! array_key_exists( 'notification_id', $args );
        $notification = new Notification();
        if ( $new ) {
            $before = NotificationData::newRecord( $args['gateway'], $args['type'] );
            $notification->setGateway( $args['gateway'] )->setType( $args['type'] );
            $snapshot_before = null;
            $stored_whatsapp = array();
        } else {
            $notification->load( (int) $args['notification_id'] );
            $before          = NotificationData::record( $notification );
            $snapshot_before = $notification->getFields();
            $stored          = (array) json_decode( (string) $notification->getSettings(), true );
            $stored_whatsapp = isset( $stored['whatsapp'] ) && is_array( $stored['whatsapp'] ) ? $stored['whatsapp'] : array();
        }
        $after   = self::merge( $before, $args );
        $gateway = $after['gateway'];

        $notification
            ->setName( $after['name'] )
            ->setActive( $after['active'] )
            ->setToCustomer( $after['to_customer'] )
            ->setToStaff( $after['to_staff'] )
            ->setToAdmin( $after['to_admin'] )
            ->setToOrganizer( $after['to_organizer'] )
            ->setToCustom( $after['to_custom'] )
            ->setCustomRecipients( implode( "\n", $after['custom_recipients'] ) );
        if ( $gateway === 'email' ) {
            $notification
                ->setSubject( $after['subject'] )
                ->setAttachIcs( $after['attach_ics'] )
                ->setAttachInvoice( $after['attach_invoice'] );
        } elseif ( $new ) {
            $notification->setSubject( '' )->setAttachIcs( 0 )->setAttachInvoice( 0 );
        }
        if ( $gateway !== 'whatsapp' && array_key_exists( 'message', $args ) ) {
            $notification->setMessage( html_entity_decode( $after['message'] ) );
        }

        $settings = $after['settings'];
        unset( $settings['whatsapp'] );
        if ( $gateway === 'whatsapp' ) {
            $whatsapp = $stored_whatsapp;
            if ( isset( $args['whatsapp'] ) ) {
                $w   = $after['settings']['whatsapp'];
                $tpl = NotificationData::findTemplate( $w['template'], $w['language'] );
                $whatsapp = array( 'template' => $w['template'], 'language' => $w['language'] );
                // A part without variables is not written: the message would get an empty component.
                if ( $tpl && $tpl['header_variables'] ) {
                    $whatsapp['header'] = $w['header'];
                }
                if ( $tpl && $tpl['body_variables'] ) {
                    $whatsapp['body'] = $w['body'];
                }
            }
            $settings['whatsapp'] = $whatsapp;
            // The dialog opens the template by its position in the Cloud list kept in message.
            if ( isset( $whatsapp['template'], $whatsapp['language'] ) ) {
                $tpl = NotificationData::findTemplate( $whatsapp['template'], $whatsapp['language'] );
                if ( $tpl ) {
                    $notification->setMessage( (string) $tpl['key'] );
                }
            }
        }
        $notification->setSettings( $settings );
        $notification->save();

        $saved = new Notification();
        $saved->load( $notification->getId() );
        $snapshot = array(
            'id' => (int) $notification->getId(),
            'created' => $new,
            'before' => $snapshot_before,
            'after' => $saved->getFields(),
        );
        if ( $new ) {
            $snapshot['created_id'] = (int) $notification->getId();
        }

        return $snapshot;
    }

    /**
     * Return the record while it is as the action left it; a created notification is deleted
     * unless it has already sent something (the marks of sent notifications would go with it).
     *
     * @inheritDoc
     */
    public function undo( array $snapshot )
    {
        $notification = new Notification();
        if ( ! $notification->load( $snapshot['id'] ) || $notification->getFields() != $snapshot['after'] ) {
            return;
        }
        if ( $snapshot['created'] ) {
            if ( (int) SentNotification::query()->where( 'notification_id', $snapshot['id'] )->count() === 0 ) {
                $notification->delete();
            }

            return;
        }
        $notification->setFields( $snapshot['before'] )->save();
    }
}
