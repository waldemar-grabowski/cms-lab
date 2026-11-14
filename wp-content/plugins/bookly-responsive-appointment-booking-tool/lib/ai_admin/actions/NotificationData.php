<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\State;
use Bookly\Lib\DataHolders\Notification\Settings;
use Bookly\Lib\Entities\CustomerAppointment;
use Bookly\Lib\Entities\Notification;
use Bookly\Backend\Modules\Notifications\Lib\Codes as NotificationCodes;

/**
 * A notification as its dialog shows it — the shape get_notification returns and the notification
 * actions read (set_notification, set_notifications_state), plus what the dialog of each channel
 * offers on this site: types, statuses, codes, WhatsApp templates.
 */
abstract class NotificationData
{
    /** Channels, each with its own page and dialog. */
    const GATEWAYS = 'email,sms,voice,whatsapp';

    /** Types whose dialog shows Appointment status and Services. */
    const STATUS_TYPES = 'new_booking,new_booking_recurring,ca_status_changed,ca_status_changed_recurring,appointment_reminder';

    /** Settings keys of the send time. */
    const TIMING_KEYS = 'option,offset_hours,perform,offset_bidirectional_hours,at_hour,offset_before_hours,before_at_hour';

    /** Patterns of the template language, as Lib\Utils\Codes reads it. */
    const T_CODE = '{(\w+(?:\.\w+)*(?:\#\w+)*)}';
    const T_IF = '{#if\s+(\w+(?:\.\w+)*(?:\#\w+)*)\s*(?:(==|!=|>=|>|<=|<|=|!empty|empty)\s*(.*?))?}\n?';
    const T_ELSE = '{:else}\n?';
    const T_END_IF = '{\/if}\n?';
    const T_EACH = '{#each\s+(\w+(?:\.\w+)*)\s+as\s+(\w+)(?:\s+delimited\s+by\s+"(.+?)")?\s*}\n?';
    const T_END_EACH = '{\/each}\n?';

    /** @var array gateway => types of the dialog */
    protected static $types = array();
    /** @var array|null */
    protected static $cloud;
    /** @var array|null */
    protected static $templates;

    /**
     * @return string[]
     */
    public static function gateways()
    {
        return explode( ',', self::GATEWAYS );
    }

    /**
     * Dialog of a channel; its templates/_types.php is the list of types the channel offers.
     *
     * @param string $gateway
     * @return string
     */
    protected static function dialog( $gateway )
    {
        switch ( $gateway ) {
            case 'sms':
                return '\Bookly\Backend\Components\Dialogs\Sms\Dialog';
            case 'voice':
                return '\Bookly\Backend\Components\Dialogs\Voice\Dialog';
            case 'whatsapp':
                return '\Bookly\Backend\Components\Dialogs\Whatsapp\Dialog';
        }

        return '\Bookly\Backend\Components\Dialogs\Notifications\Dialog';
    }

    /**
     * Types the dialog of a channel offers on this site, in its order: the options its Type list
     * renders (recipients, attachments and the send time of each).
     *
     * @param string $gateway
     * @return array type => array( type, title, kind, recipients, attach, timing_options )
     */
    public static function allowedTypes( $gateway )
    {
        if ( ! isset( self::$types[ $gateway ] ) ) {
            $class = self::dialog( $gateway );
            $html  = call_user_func( array( $class, 'renderTemplate' ), '_types', array(), false );
            $types = array();
            preg_match_all( '/<option value="([^"]+)" data-set="([^"]*)" data-recipients=\'([^\']*)\' data-icon="[^"]*" data-attach=\'([^\']*)\'>/', $html, $matches, PREG_SET_ORDER );
            foreach ( $matches as $m ) {
                switch ( $m[2] ) {
                    case 'bidirectional full':
                        $timing = array( 1, 2 );
                        break;
                    case 'bidirectional at-time':
                        $timing = array( 2 );
                        break;
                    case 'before':
                        $timing = array( 3 );
                        break;
                    default:
                        $timing = array();
                }
                $types[ $m[1] ] = array(
                    'type' => $m[1],
                    'title' => Notification::getTitle( $m[1] ),
                    'kind' => self::kind( $m[1] ),
                    'recipients' => (array) json_decode( $m[3], true ),
                    'attach' => (array) json_decode( $m[4], true ),
                    'timing_options' => $timing,
                );
            }
            self::$types[ $gateway ] = $types;
        }

        return self::$types[ $gateway ];
    }

    /**
     * instant — sent at the event, scheduled — sent by cron.
     *
     * @param string $type
     * @return string
     */
    public static function kind( $type )
    {
        $notification = new Notification();
        $notification->setType( $type )->setSettings( json_encode( Settings::getDefault() ) );

        return $notification->getSettingsObject()->getInstant() ? 'instant' : 'scheduled';
    }

    /**
     * Values of Appointment status.
     *
     * @return string[]
     */
    public static function statuses()
    {
        return array_merge( array( 'any' ), CustomerAppointment::getStatuses() );
    }

    /**
     * Label of an Appointment status value.
     *
     * @param string $status
     * @return string
     */
    public static function statusText( $status )
    {
        return $status === 'any' ? __( 'Any', 'bookly-responsive-appointment-booking-tool' ) : CustomerAppointment::statusToString( $status );
    }

    /**
     * Cloud sign-in and the products of the channels, read once per request.
     *
     * @return array array( signed_in, sms, voice, whatsapp )
     */
    public static function cloud()
    {
        if ( self::$cloud === null ) {
            $api = Lib\Cloud\API::getInstance();
            $signed_in = $api->getToken() && $api->account->loadProfile();
            self::$cloud = array(
                'signed_in' => (bool) $signed_in,
                'sms' => $signed_in && $api->account->productActive( Lib\Cloud\Account::PRODUCT_SMS_NOTIFICATIONS ),
                'voice' => $signed_in && $api->account->productActive( Lib\Cloud\Account::PRODUCT_VOICE ),
                'whatsapp' => $signed_in && $api->account->productActive( Lib\Cloud\Account::PRODUCT_WHATSAPP ),
            );
        }

        return self::$cloud;
    }

    /**
     * Error of a channel whose page is not open (no Cloud sign-in or the product is not active).
     *
     * @param string $gateway
     * @return string|null
     */
    public static function channelError( $gateway )
    {
        if ( $gateway === 'email' ) {
            return null;
        }
        $cloud = self::cloud();
        $id    = $gateway === 'sms' ? 'sms_unavailable' : 'voice_whatsapp_unavailable';
        $page  = self::pageTitle( $gateway );
        if ( ! $cloud['signed_in'] ) {
            return $id . ': ' . $page . ' needs the site signed in to Bookly Cloud — the owner signs in first';
        }
        if ( ! $cloud[ $gateway ] ) {
            return $id . ': the ' . $page . ' product is not active in Bookly Cloud — the owner activates it in Bookly Cloud → Products first';
        }

        return null;
    }

    /**
     * @param string $gateway
     * @return string
     */
    public static function pageTitle( $gateway )
    {
        switch ( $gateway ) {
            case 'sms':
                return __( 'SMS Notifications', 'bookly-responsive-appointment-booking-tool' );
            case 'voice':
                return __( 'Voice Notifications', 'bookly-responsive-appointment-booking-tool' );
            case 'whatsapp':
                return __( 'WhatsApp Notifications', 'bookly-responsive-appointment-booking-tool' );
        }

        return __( 'Email Notifications', 'bookly-responsive-appointment-booking-tool' );
    }

    /**
     * Name of a channel in a sentence.
     *
     * @param string $gateway
     * @return string
     */
    public static function channelName( $gateway )
    {
        switch ( $gateway ) {
            case 'sms':
                return __( 'SMS', 'bookly-responsive-appointment-booking-tool' );
            case 'voice':
                return __( 'Voice', 'bookly-responsive-appointment-booking-tool' );
            case 'whatsapp':
                return __( 'WhatsApp', 'bookly-responsive-appointment-booking-tool' );
        }

        return __( 'Email', 'bookly-responsive-appointment-booking-tool' );
    }

    /**
     * Title of the dialog that creates a notification of the channel.
     *
     * @param string $gateway
     * @return string
     */
    public static function newTitle( $gateway )
    {
        switch ( $gateway ) {
            case 'sms':
                return __( 'New SMS notification', 'bookly-responsive-appointment-booking-tool' );
            case 'voice':
                return __( 'New Voice notification', 'bookly-responsive-appointment-booking-tool' );
            case 'whatsapp':
                return __( 'New WhatsApp notification', 'bookly-responsive-appointment-booking-tool' );
        }

        return __( 'New email notification', 'bookly-responsive-appointment-booking-tool' );
    }

    /**
     * Notifications page of the channel.
     *
     * @param string $gateway
     * @return string
     */
    public static function link( $gateway )
    {
        switch ( $gateway ) {
            case 'sms':
                $page = \Bookly\Backend\Modules\CloudSms\Page::pageSlug();
                break;
            case 'voice':
                $page = \Bookly\Backend\Modules\CloudVoice\Page::pageSlug();
                break;
            case 'whatsapp':
                $page = \Bookly\Backend\Modules\CloudWhatsapp\Page::pageSlug();
                break;
            default:
                $page = \Bookly\Backend\Modules\Notifications\Page::pageSlug();
        }

        return add_query_arg( array( 'page' => $page, 'tab' => 'notifications' ), admin_url( 'admin.php' ) );
    }

    /**
     * Stored settings over the defaults, as the dialog opens them, with the types of the values
     * the dialog reads. A type with one send time option always has it (the dialog switches to it).
     *
     * @param string       $type
     * @param string|array $json
     * @param string       $gateway
     * @return array
     */
    public static function settings( $type, $json, $gateway )
    {
        $stored   = is_array( $json ) ? $json : (array) json_decode( (string) $json, true );
        $settings = array_merge( Settings::getDefault(), $stored );
        foreach ( array( 'option', 'offset_hours', 'offset_bidirectional_hours', 'at_hour', 'offset_before_hours', 'before_at_hour' ) as $key ) {
            $settings[ $key ] = (int) $settings[ $key ];
        }
        $settings['status'] = (string) $settings['status'];
        $settings['perform'] = (string) $settings['perform'];
        $services = is_array( $settings['services'] ) ? $settings['services'] : array();
        $settings['services'] = array(
            'any' => isset( $services['any'] ) && $services['any'] === 'selected' ? 'selected' : 'any',
            'ids' => isset( $services['ids'] ) ? array_values( array_map( 'intval', (array) $services['ids'] ) ) : array(),
        );
        $settings['payment_statuses'] = array_values( array_map( 'strval', (array) $settings['payment_statuses'] ) );
        if ( $type === Notification::TYPE_STAFF_DAY_AGENDA ) {
            $settings['option'] = 3;
        } elseif ( $type === Notification::TYPE_CUSTOMER_BIRTHDAY ) {
            $settings['option'] = 2;
        }
        if ( $gateway === 'whatsapp' ) {
            $whatsapp = isset( $stored['whatsapp'] ) && is_array( $stored['whatsapp'] ) ? $stored['whatsapp'] : array();
            $settings['whatsapp'] = array(
                'template' => isset( $whatsapp['template'] ) ? (string) $whatsapp['template'] : '',
                'language' => isset( $whatsapp['language'] ) ? (string) $whatsapp['language'] : '',
                'header' => isset( $whatsapp['header'] ) ? array_values( array_map( 'strval', (array) $whatsapp['header'] ) ) : array(),
                'body' => isset( $whatsapp['body'] ) ? array_values( array_map( 'strval', (array) $whatsapp['body'] ) ) : array(),
            );
        } else {
            unset( $settings['whatsapp'] );
        }

        return $settings;
    }

    /**
     * Custom recipients as the sending code reads them: one per line, empty lines dropped.
     *
     * @param string|null $text
     * @return string[]
     */
    public static function customList( $text )
    {
        $list = array();
        foreach ( explode( "\n", (string) $text ) as $line ) {
            $line = trim( $line );
            if ( $line !== '' ) {
                $list[] = $line;
            }
        }

        return $list;
    }

    /**
     * A notification in the shape of the actions (every column, lists as arrays).
     *
     * @param Notification $notification
     * @return array
     */
    public static function record( Notification $notification )
    {
        $gateway = $notification->getGateway();

        return array(
            'id' => (int) $notification->getId(),
            'gateway' => $gateway,
            'type' => $notification->getType(),
            'name' => (string) $notification->getName(),
            'active' => (int) $notification->getActive(),
            'subject' => (string) $notification->getSubject(),
            'message' => (string) $notification->getMessage(),
            'to_customer' => (int) $notification->getToCustomer(),
            'to_staff' => (int) $notification->getToStaff(),
            'to_admin' => (int) $notification->getToAdmin(),
            'to_organizer' => (int) $notification->getToOrganizer(),
            'to_custom' => (int) $notification->getToCustom(),
            'custom_recipients' => self::customList( $notification->getCustomRecipients() ),
            'attach_ics' => (int) $notification->getAttachIcs(),
            'attach_invoice' => (int) $notification->getAttachInvoice(),
            'settings' => self::settings( $notification->getType(), $notification->getSettings(), $gateway ),
        );
    }

    /**
     * A notification the dialog creates: Enabled, no recipient, default settings.
     *
     * @param string $gateway
     * @param string $type
     * @return array
     */
    public static function newRecord( $gateway, $type )
    {
        return array(
            'id' => null,
            'gateway' => $gateway,
            'type' => $type,
            'name' => '',
            'active' => 1,
            'subject' => '',
            'message' => '',
            'to_customer' => 0,
            'to_staff' => 0,
            'to_admin' => 0,
            'to_organizer' => 0,
            'to_custom' => 0,
            'custom_recipients' => array(),
            'attach_ics' => 0,
            'attach_invoice' => 0,
            'settings' => self::settings( $type, array(), $gateway ),
        );
    }

    /**
     * All notifications after the earlier items: id|"$N" => record.
     *
     * @param State $state
     * @return array
     */
    public static function all( State $state )
    {
        if ( $state->has( 'list:notifications' ) ) {
            return $state->get( 'list:notifications' );
        }
        $all = array();
        /** @var Notification $notification */
        foreach ( Notification::query()->sortBy( 'id' )->find() as $notification ) {
            $all[ (int) $notification->getId() ] = self::record( $notification );
        }
        $state->set( 'list:notifications', $all );

        return $all;
    }

    /**
     * @param State      $state
     * @param int|string $id
     * @param array      $record
     */
    public static function put( State $state, $id, array $record )
    {
        $all        = self::all( $state );
        $all[ $id ] = $record;
        $state->set( 'list:notifications', $all );
    }

    /**
     * Whether a notification is listed on the page of its channel (the table lists only the types
     * the channel offers).
     *
     * @param array $record
     * @return bool
     */
    public static function listed( array $record )
    {
        return in_array( $record['type'], Notification::getTypes( $record['gateway'] ), true );
    }

    /**
     * Codes the text of a type may use in a channel: Voice and WhatsApp use the SMS set.
     *
     * @param string $gateway
     * @param string $type
     * @return array code => array( description, if?, loop? )
     */
    public static function codes( $gateway, $type )
    {
        $codes = new NotificationCodes( $gateway === 'email' ? 'email' : 'sms' );

        return $codes->getCodes( $type );
    }

    /**
     * Problems of a text in the template language: codes not in $codes (also in conditions and
     * loops, item codes as item.code) and tags without their pair.
     *
     * @param string $text
     * @param array  $codes see codes()
     * @param bool   $blocks whether {#if} and {#each} are allowed
     * @return array array( 'unknown' => string[], 'unbalanced' => string[], 'blocks' => string[] )
     */
    public static function checkText( $text, array $codes, $blocks = true )
    {
        $result   = array( 'unknown' => array(), 'unbalanced' => array(), 'blocks' => array() );
        $patterns = array(
            'T_CODE' => self::T_CODE,
            'T_IF' => self::T_IF,
            'T_ELSE' => self::T_ELSE,
            'T_END_IF' => self::T_END_IF,
            'T_EACH' => self::T_EACH,
            'T_END_EACH' => self::T_END_EACH,
        );
        $stack  = array();
        $offset = 0;
        $length = strlen( $text );
        while ( $offset < $length ) {
            $type = null;
            foreach ( $patterns as $name => $pattern ) {
                if ( preg_match( '/' . $pattern . '/A', $text, $m, 0, $offset ) ) {
                    $type = $name;
                    break;
                }
            }
            if ( $type === null ) {
                ++$offset;
                continue;
            }
            $offset += strlen( $m[0] );
            switch ( $type ) {
                case 'T_CODE':
                case 'T_IF':
                    if ( $type === 'T_IF' ) {
                        if ( ! $blocks ) {
                            $result['blocks'][] = trim( $m[0] );
                        }
                        $stack[] = array( 'if', null, array() );
                    }
                    if ( ! self::knownCode( $m[1], $codes, $stack ) ) {
                        $result['unknown'][] = '{' . $m[1] . '}';
                    }
                    break;
                case 'T_EACH':
                    if ( ! $blocks ) {
                        $result['blocks'][] = trim( $m[0] );
                    }
                    $loop = isset( $codes[ $m[1] ]['loop'] ) ? $codes[ $m[1] ]['loop'] : null;
                    if ( ! $loop ) {
                        $result['unknown'][] = '{#each ' . $m[1] . '}';
                    }
                    $stack[] = array( 'each', $m[2], $loop ? $loop['codes'] : array() );
                    break;
                case 'T_ELSE':
                    if ( ! $stack ) {
                        $result['unbalanced'][] = '{:else} outside {#if} / {#each}';
                    }
                    break;
                case 'T_END_IF':
                case 'T_END_EACH':
                    $kind = $type === 'T_END_IF' ? 'if' : 'each';
                    $top  = $stack ? $stack[ count( $stack ) - 1 ] : null;
                    if ( $top === null || $top[0] !== $kind ) {
                        $result['unbalanced'][] = '{/' . $kind . '} without its {#' . $kind . '}';
                    } else {
                        array_pop( $stack );
                    }
                    break;
            }
        }
        foreach ( $stack as $open ) {
            $result['unbalanced'][] = '{#' . $open[0] . '} is not closed';
        }
        $result['unknown'] = array_values( array_unique( $result['unknown'] ) );

        return $result;
    }

    /**
     * @param string $code  as written, with modifiers (#…)
     * @param array  $codes
     * @param array  $stack open blocks: array( kind, item name, item codes )
     * @return bool
     */
    protected static function knownCode( $code, array $codes, array $stack )
    {
        $parts = explode( '#', $code, 2 );
        $path  = explode( '.', $parts[0] );
        if ( count( $path ) === 1 ) {
            return isset( $codes[ $path[0] ] );
        }
        foreach ( array_reverse( $stack ) as $open ) {
            if ( $open[0] === 'each' && $open[1] === $path[0] ) {
                return count( $path ) === 2 && isset( $open[2][ $path[1] ] );
            }
        }

        return isset( $codes[ $parts[0] ] );
    }

    /**
     * Distinct {{N}} of a WhatsApp template text, ascending, as the dialog makes its Variable fields.
     *
     * @param string|null $text
     * @return int[]
     */
    public static function variables( $text )
    {
        preg_match_all( '/{{(\d+)}}/', (string) $text, $m );
        $numbers = array_values( array_unique( array_map( 'intval', $m[1] ) ) );
        sort( $numbers );

        return $numbers;
    }

    /**
     * The WhatsApp templates of the Meta account as the Template list shows them, read from Cloud
     * once per request. Each: key (its position in the list the dialog stores in message), name,
     * language, status, header, body, header_variables, body_variables, usable, reason.
     *
     * @return array array( 'error' => string|null, 'templates' => array )
     */
    public static function whatsappTemplates()
    {
        if ( self::$templates === null ) {
            $cloud = self::cloud();
            if ( ! $cloud['signed_in'] || ! $cloud['whatsapp'] ) {
                self::$templates = array( 'error' => 'WhatsApp Notifications is not available on this site', 'templates' => array() );
            } else {
                $api      = Lib\Cloud\API::getInstance();
                $response = $api->getProduct( Lib\Cloud\Account::PRODUCT_WHATSAPP )->getTemplates();
                $errors   = $api->getErrors();
                if ( $errors ) {
                    self::$templates = array( 'error' => (string) current( $errors ), 'templates' => array() );
                } else {
                    $list = is_array( $response ) && isset( $response['list'] ) && is_array( $response['list'] ) ? $response['list'] : array();
                    $templates = array();
                    foreach ( $list as $key => $tpl ) {
                        $templates[] = self::template( $key, (array) $tpl );
                    }
                    self::$templates = array( 'error' => null, 'templates' => $templates );
                }
            }
        }

        return self::$templates;
    }

    /**
     * @param int|string $key
     * @param array      $tpl as Cloud returns it
     * @return array
     */
    protected static function template( $key, array $tpl )
    {
        $header = isset( $tpl['header'] ) && is_array( $tpl['header'] ) ? $tpl['header'] : null;
        $body   = isset( $tpl['body']['text'] ) ? (string) $tpl['body']['text'] : '';
        $status = isset( $tpl['status'] ) ? (string) $tpl['status'] : '';
        $item   = array(
            'key' => $key,
            'name' => isset( $tpl['name'] ) ? (string) $tpl['name'] : '',
            'language' => isset( $tpl['language'] ) ? (string) $tpl['language'] : '',
            'status' => $status,
            'header' => $header && isset( $header['text'] ) ? (string) $header['text'] : null,
            'body' => $body,
        );
        $item['header_variables'] = self::variables( $item['header'] );
        $item['body_variables']   = self::variables( $body );
        $reason = null;
        if ( $status !== 'APPROVED' ) {
            $reason = 'Status ' . $status . ' — only APPROVED templates send';
        } elseif ( $header && ! isset( $header['text'] ) || isset( $header['format'] ) && strtoupper( $header['format'] ) !== 'TEXT' ) {
            $reason = 'The header is not text (image, document or video) — Bookly sends only text variables';
        } elseif ( preg_match( '/{{\s*[A-Za-z_]\w*\s*}}/', $item['header'] . ' ' . $body ) ) {
            $reason = 'Named variables {{name}} are not supported — only {{1}}, {{2}}…';
        } elseif ( isset( $tpl['buttons'] ) && preg_match( '/{{\s*\w+\s*}}/', wp_json_encode( $tpl['buttons'] ) ) ) {
            $reason = 'A button has a variable — Bookly does not send button parameters';
        }
        $item['usable'] = $reason === null;
        if ( $reason !== null ) {
            $item['reason'] = $reason;
        }

        return $item;
    }

    /**
     * Template of the list by name and language.
     *
     * @param string $name
     * @param string $language
     * @return array|null
     */
    public static function findTemplate( $name, $language )
    {
        $list = self::whatsappTemplates();
        foreach ( $list['templates'] as $tpl ) {
            if ( $tpl['name'] === (string) $name && $tpl['language'] === (string) $language ) {
                return $tpl;
            }
        }

        return null;
    }

    /**
     * Status of the template of a whatsapp record now: its status in the list, "missing" when it is
     * not there, null while the list cannot be read.
     *
     * @param array $record
     * @return string|null
     */
    public static function templateStatus( array $record )
    {
        $list = self::whatsappTemplates();
        if ( $list['error'] !== null ) {
            return null;
        }
        $tpl = self::findTemplate( $record['settings']['whatsapp']['template'], $record['settings']['whatsapp']['language'] );

        return $tpl ? $tpl['status'] : 'missing';
    }

    /**
     * Phone number as a custom recipient is stored: "+" and digits.
     *
     * @param string $phone
     * @return string
     */
    public static function phone( $phone )
    {
        $phone = trim( (string) $phone );
        $plus  = strpos( $phone, '+' ) === 0 ? '+' : '';

        return $plus . preg_replace( '/\D/', '', $phone );
    }

    /**
     * Key a custom recipient is compared by: an address ignoring case, a number by "+" and digits.
     *
     * @param string $gateway
     * @param string $entry
     * @return string
     */
    public static function entryKey( $gateway, $entry )
    {
        return $gateway === 'email' ? strtolower( trim( $entry ) ) : self::phone( $entry );
    }

    /**
     * Sample values of the codes, as the code editor shows them; a code without a sample stays as
     * it is written.
     *
     * @param array $codes
     * @return array
     */
    public static function samples( array $codes )
    {
        $editor = Lib\Utils\CodesCatalog::forEditor( $codes );
        $values = array();
        foreach ( $editor['codes'] as $name => $code ) {
            if ( isset( $code['loop'] ) ) {
                continue;
            }
            $values[ $name ] = isset( $code['sample'] ) ? (string) $code['sample'] : '{' . $name . '}';
        }

        return $values;
    }

    /**
     * Text with sample values for the codes, without markup.
     *
     * @param string $text
     * @param string $gateway
     * @param string $type
     * @return string
     */
    public static function example( $text, $gateway, $type )
    {
        $out = Lib\Utils\Codes::replace( $text, self::samples( self::codes( $gateway, $type ) ), false );
        if ( $gateway === 'email' ) {
            $out = trim( preg_replace( '/[ \t]*\n\s*\n+/', "\n", wp_strip_all_tags( str_ireplace( array( '<br>', '<br/>', '<br />', '</p>' ), "\n", $out ) ) ) );
        }

        return $out;
    }

    /**
     * Recipients of a record in words.
     *
     * @param array $record
     * @return string[]
     */
    public static function recipients( array $record )
    {
        $names = array(
            'to_customer' => __( 'Client', 'bookly-responsive-appointment-booking-tool' ),
            'to_staff' => __( 'Staff', 'bookly-responsive-appointment-booking-tool' ),
            'to_admin' => __( 'Administrators', 'bookly-responsive-appointment-booking-tool' ),
            'to_organizer' => __( 'Organizer', 'bookly-responsive-appointment-booking-tool' ),
        );
        $out = array();
        foreach ( $names as $flag => $name ) {
            if ( $record[ $flag ] ) {
                $out[] = $name;
            }
        }
        if ( $record['to_custom'] ) {
            $out = array_merge( $out, $record['custom_recipients'] );
        }

        return $out;
    }

    /**
     * Recipient flag → label.
     *
     * @param string $flag to_customer, …
     * @return string
     */
    public static function recipientName( $flag )
    {
        switch ( $flag ) {
            case 'to_customer':
                return __( 'Client', 'bookly-responsive-appointment-booking-tool' );
            case 'to_staff':
                return __( 'Staff', 'bookly-responsive-appointment-booking-tool' );
            case 'to_admin':
                return __( 'Administrators', 'bookly-responsive-appointment-booking-tool' );
            case 'to_organizer':
                return __( 'Organizer', 'bookly-responsive-appointment-booking-tool' );
        }

        return __( 'Custom', 'bookly-responsive-appointment-booking-tool' );
    }

    /**
     * When a record is sent, in words: the event, or the send time of a scheduled type.
     *
     * @param array $record
     * @return string
     */
    public static function whenText( array $record )
    {
        $types = self::allowedTypes( $record['gateway'] );
        $s     = $record['settings'];
        if ( ! isset( $types[ $record['type'] ] ) || ! $types[ $record['type'] ]['timing_options'] ) {
            return sprintf( __( 'at the event "%s"', 'bookly-responsive-appointment-booking-tool' ), Notification::getTitle( $record['type'] ) );
        }
        if ( $record['type'] === Notification::TYPE_STAFF_DAY_AGENDA ) {
            $day = $s['offset_before_hours'] === 0
                ? __( 'on the day of the agenda', 'bookly-responsive-appointment-booking-tool' )
                : sprintf( __( '%s before the day of the agenda', 'bookly-responsive-appointment-booking-tool' ), Lib\Utils\DateTime::secondsToInterval( abs( $s['offset_before_hours'] ) * HOUR_IN_SECONDS ) );

            return sprintf( __( '%1$s at %2$s (site time zone)', 'bookly-responsive-appointment-booking-tool' ), $day, Lib\Utils\DateTime::formatTime( $s['before_at_hour'] * HOUR_IN_SECONDS ) );
        }
        $event = $record['type'] === Notification::TYPE_CUSTOMER_BIRTHDAY
            ? __( 'the birthday', 'bookly-responsive-appointment-booking-tool' )
            : __( 'the appointment', 'bookly-responsive-appointment-booking-tool' );
        if ( $s['option'] === 1 ) {
            $interval = Lib\Utils\DateTime::secondsToInterval( $s['offset_hours'] * HOUR_IN_SECONDS );

            return $s['perform'] === 'after'
                ? sprintf( __( '%1$s after the start of %2$s', 'bookly-responsive-appointment-booking-tool' ), $interval, $event )
                : sprintf( __( '%1$s before the start of %2$s', 'bookly-responsive-appointment-booking-tool' ), $interval, $event );
        }
        $hours = $s['offset_bidirectional_hours'];
        if ( $hours === 0 ) {
            $day = sprintf( __( 'on the day of %s', 'bookly-responsive-appointment-booking-tool' ), $event );
        } elseif ( $hours < 0 ) {
            $day = sprintf( __( '%1$s before %2$s', 'bookly-responsive-appointment-booking-tool' ), Lib\Utils\DateTime::secondsToInterval( - $hours * HOUR_IN_SECONDS ), $event );
        } else {
            $day = sprintf( __( '%1$s after %2$s', 'bookly-responsive-appointment-booking-tool' ), Lib\Utils\DateTime::secondsToInterval( $hours * HOUR_IN_SECONDS ), $event );
        }

        return sprintf( __( '%1$s at %2$s (site time zone)', 'bookly-responsive-appointment-booking-tool' ), $day, Lib\Utils\DateTime::formatTime( $s['at_hour'] * HOUR_IN_SECONDS ) );
    }

    /**
     * Services condition in words.
     *
     * @param array $record
     * @return string
     */
    public static function servicesText( array $record )
    {
        $services = $record['settings']['services'];
        if ( $services['any'] !== 'selected' ) {
            return __( 'Any', 'bookly-responsive-appointment-booking-tool' );
        }
        if ( ! $services['ids'] ) {
            return __( 'No service selected', 'bookly-responsive-appointment-booking-tool' );
        }
        $titles = array();
        foreach ( $services['ids'] as $id ) {
            $titles[] = self::serviceTitle( $id );
        }

        return implode( ', ', $titles );
    }

    /**
     * @param int $id
     * @return string
     */
    public static function serviceTitle( $id )
    {
        $service = Lib\Entities\Service::find( $id );

        return $service ? $service->getTitle() : '#' . $id;
    }

    /**
     * Whether a record's appointment conditions overlap another's.
     *
     * @param array $a
     * @param array $b
     * @return bool
     */
    protected static function conditionsOverlap( array $a, array $b )
    {
        $statuses = explode( ',', self::STATUS_TYPES );
        if ( ! in_array( $a['type'], $statuses, true ) || $a['type'] !== $b['type'] ) {
            return true;
        }
        $sa = $a['settings']['status'];
        $sb = $b['settings']['status'];
        if ( $sa !== 'any' && $sb !== 'any' && $sa !== $sb ) {
            return false;
        }
        $va = $a['settings']['services'];
        $vb = $b['settings']['services'];
        if ( $va['any'] === 'selected' && $vb['any'] === 'selected' && ! array_intersect( $va['ids'], $vb['ids'] ) ) {
            return false;
        }

        return true;
    }

    /**
     * Other enabled notifications of the channel that send the same event to the same people.
     *
     * @param array $record
     * @param State $state
     * @return array records
     */
    public static function duplicates( array $record, State $state )
    {
        $out = array();
        foreach ( self::all( $state ) as $id => $other ) {
            if ( (string) $id === (string) $record['id'] || ! $other['active'] || $other['gateway'] !== $record['gateway'] ) {
                continue;
            }
            $combined = array( Notification::TYPE_NEW_BOOKING, Notification::TYPE_NEW_BOOKING_COMBINED );
            if ( $other['type'] === $record['type'] ) {
                $shared = false;
                foreach ( array( 'to_customer', 'to_staff', 'to_admin', 'to_organizer' ) as $flag ) {
                    $shared = $shared || ( $record[ $flag ] && $other[ $flag ] );
                }
                if ( $record['to_custom'] && $other['to_custom'] && array_intersect(
                    array_map( 'strtolower', $record['custom_recipients'] ),
                    array_map( 'strtolower', $other['custom_recipients'] )
                ) ) {
                    $shared = true;
                }
                if ( $shared && self::conditionsOverlap( $record, $other ) ) {
                    $out[] = $other;
                }
            } elseif ( in_array( $record['type'], $combined, true ) && in_array( $other['type'], $combined, true )
                && $record['to_customer'] && $other['to_customer'] ) {
                $out[] = $other;
            }
        }

        return $out;
    }

    /**
     * Name of a record for a sentence: «name» (#id).
     *
     * @param array $record
     * @return string
     */
    public static function nameText( array $record )
    {
        return '«' . $record['name'] . '»' . ( is_int( $record['id'] ) ? ' (#' . $record['id'] . ')' : '' );
    }

    /**
     * Consequences of a notification's state after the change, for the card: the blocks both
     * notification actions share.
     *
     * @param array|null $before null for a new notification
     * @param array      $after
     * @param State      $state after the change
     * @param bool       $reach whether to say who gets it and when (the caller may say it itself)
     * @return string[]
     */
    public static function consequences( $before, array $after, State $state, $reach = true )
    {
        $warnings  = array();
        $gateway   = $after['gateway'];
        $switch_on = $after['active'] && ( $before === null || ! $before['active'] );
        $flags     = array( 'to_customer', 'to_staff', 'to_admin', 'to_organizer', 'to_custom' );
        $recipients_changed = $before === null;
        if ( $before !== null ) {
            foreach ( $flags as $flag ) {
                $recipients_changed = $recipients_changed || $before[ $flag ] !== $after[ $flag ];
            }
            $recipients_changed = $recipients_changed || $before['custom_recipients'] !== $after['custom_recipients'];
        }
        $types = self::allowedTypes( $gateway );
        $type  = isset( $types[ $after['type'] ] ) ? $types[ $after['type'] ] : null;

        if ( ! $reach ) {
            // Said by the caller.
        } elseif ( ! $after['active'] ) {
            if ( $before === null || $before['active'] ) {
                $warnings[] = __( 'Disabled: sends nothing until it is enabled.', 'bookly-responsive-appointment-booking-tool' );
            }
        } else {
            $conditions = $before === null || $switch_on || $recipients_changed || $before['settings'] != $after['settings'];
            $who = self::recipients( $after );
            if ( $conditions && $who ) {
                $text = sprintf( __( '%1$s get it %2$s.', 'bookly-responsive-appointment-booking-tool' ), implode( ', ', $who ), self::whenText( $after ) );
                if ( in_array( $after['type'], explode( ',', self::STATUS_TYPES ), true ) ) {
                    $text .= ' ' . sprintf( __( 'Appointment status: %1$s; services: %2$s.', 'bookly-responsive-appointment-booking-tool' ), self::statusText( $after['settings']['status'] ), self::servicesText( $after ) );
                }
                $warnings[] = $text;
            }
        }
        if ( $after['active'] && $type && $type['kind'] === 'scheduled' && $switch_on ) {
            $warnings[] = sprintf(
                __( 'Scheduled notifications are sent only while cron runs (Bookly Cloud Cron or the server cron); at the next run it may send to appointments inside the current window of %d hours (Email Notifications → Settings → Scheduled notifications retry period).', 'bookly-responsive-appointment-booking-tool' ),
                (int) get_option( 'bookly_ntf_processing_interval' )
            );
        }
        if ( $after['type'] === Notification::TYPE_APPOINTMENT_REMINDER && $after['active'] && $after['settings']['status'] === 'any'
            && ( $before === null || ! $before['active'] || $before['settings']['status'] !== 'any' ) ) {
            $warnings[] = __( 'Status Any also sends the reminder to appointments that are Cancelled, Rejected or on the waiting list — pick Approved (or Pending) to avoid that.', 'bookly-responsive-appointment-booking-tool' );
        }
        if ( $after['active'] && ( $switch_on || $recipients_changed || $before['settings'] != $after['settings'] ) ) {
            foreach ( self::duplicates( $after, $state ) as $other ) {
                $warnings[] = sprintf( __( 'Clients may receive this twice by %1$s — %2$s sends the same event to the same recipients.', 'bookly-responsive-appointment-booking-tool' ), self::channelName( $gateway ), self::nameText( $other ) );
            }
        }
        if ( $after['active'] && ( $switch_on || $recipients_changed ) ) {
            if ( $gateway === 'sms' ) {
                $warnings[] = __( 'Each SMS is paid from the Bookly Cloud balance by the recipient\'s country; at zero balance an SMS is not sent ("Out of credit") — see SMS Notifications → Balance.', 'bookly-responsive-appointment-booking-tool' );
            } elseif ( $gateway === 'voice' ) {
                $warnings[] = __( 'Each call is paid by the minute from the Bookly Cloud balance by the recipient\'s country; at zero balance a call is not made ("Out of credit"), a country without service is refused ("Country out of service") — see the Price list of Voice Notifications.', 'bookly-responsive-appointment-booking-tool' );
            } elseif ( $gateway === 'whatsapp' ) {
                $warnings[] = __( 'WhatsApp Notifications is a monthly plan with a message limit — when it runs out Bookly Cloud stops sending; Meta\'s own fees are separate. There is no test message: check with a real booking to your own number.', 'bookly-responsive-appointment-booking-tool' );
            }
        }
        // verify_phone of both channels / verification blocked
        $verify = array();
        foreach ( self::all( $state ) as $other ) {
            if ( $other['type'] === Notification::TYPE_VERIFY_PHONE && $other['active'] && in_array( $other['gateway'], array( 'sms', 'voice' ), true ) ) {
                $verify[] = $other;
            }
        }
        if ( $after['type'] === Notification::TYPE_VERIFY_PHONE && $after['active'] ) {
            foreach ( $verify as $other ) {
                if ( $other['gateway'] !== $gateway && in_array( $gateway, array( 'sms', 'voice' ), true ) ) {
                    $warnings[] = sprintf( __( 'The client gets the verification code both by SMS and by a call — %s.', 'bookly-responsive-appointment-booking-tool' ), self::nameText( $other ) );
                }
            }
        }
        if ( $after['type'] === Notification::TYPE_VERIFY_PHONE && in_array( get_option( 'bookly_cst_verify_customer_details' ), array( 'always_phone', 'on_update' ), true ) ) {
            $working = false;
            foreach ( $verify as $other ) {
                $working = $working || ( $other['to_customer'] && strpos( $other['message'], '{verification_code}' ) !== false );
            }
            if ( ! $working ) {
                $warnings[] = __( 'Customers cannot finish a booking: the form asks for a code and no SMS or call with it is sent.', 'bookly-responsive-appointment-booking-tool' );
            }
        }

        return $warnings;
    }

    /**
     * The record as get_notification returns it: no subject and attachments for sms, voice and
     * whatsapp, no message for whatsapp (its message is settings.whatsapp).
     *
     * @param array $record
     * @return array
     */
    public static function data( array $record )
    {
        $data = array(
            'id' => $record['id'],
            'gateway' => $record['gateway'],
            'title' => Notification::getTitle( $record['type'] ),
            'kind' => self::kind( $record['type'] ),
        );
        $data += $record;
        if ( $record['gateway'] !== 'email' ) {
            unset( $data['subject'], $data['attach_ics'], $data['attach_invoice'] );
        }
        if ( $record['gateway'] === 'whatsapp' ) {
            unset( $data['message'] );
            $status = self::templateStatus( $record );
            if ( $status !== null ) {
                $data['template_status'] = $status;
            }
        }

        return $data;
    }
}
