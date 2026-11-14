<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Backend\Components\Dialogs\Customer\Edit\Proxy as DialogProxy;
use Bookly\Lib;
use Bookly\Lib\AiAdmin\State;

/**
 * Customers for the setup assistant: the fields of the customer dialog as one array (the shape
 * get_customer returns and set_customer takes), values after the earlier items of a proposal,
 * and saving the whole set the way the dialog does.
 *
 * Values of a customer live in State under "customer:<id>" (id or "$N" of a customer created
 * earlier in the same proposal); a customer not touched by an earlier item is read from the
 * database.
 */
abstract class CustomerData
{
    /** Address parts of Pro with the length of their columns. */
    protected static $address = array(
        'country' => 32,
        'state' => 32,
        'postcode' => 10,
        'city' => 64,
        'street' => 64,
        'street_number' => 16,
        'additional_address' => 255,
    );

    /**
     * Address parts with the length of their columns.
     *
     * @return array
     */
    public static function addressLimits()
    {
        return self::$address;
    }

    /**
     * Address parts the dialog shows (Settings → Customers → Customer's address fields); empty
     * without Pro.
     *
     * @return string[]
     */
    public static function addressParts()
    {
        if ( ! Lib\Config::proActive() ) {
            return array();
        }

        return array_keys( (array) Lib\Proxy\Pro::getDisplayedAddressFields() );
    }

    /**
     * @return string full_name | first_last_name
     */
    public static function nameMode()
    {
        return Lib\Config::showFirstLastName() ? 'first_last_name' : 'full_name';
    }

    /**
     * The dialog's phone input takes "+" and digits with a country chosen, anything without.
     *
     * @return string international | free
     */
    public static function phoneFormat()
    {
        return get_option( 'bookly_cst_phone_default_country' ) === 'disabled' ? 'free' : 'international';
    }

    /**
     * Fields of the Customer Information add-on that hold a value, by id; empty without the add-on.
     *
     * @return \stdClass[]
     */
    public static function infoDefs()
    {
        $defs = array();
        if ( Lib\Config::customerInformationActive() ) {
            foreach ( (array) Lib\Proxy\CustomerInformation::getFieldsWhichMayHaveData() as $field ) {
                $defs[ (int) $field->id ] = $field;
            }
        }

        return $defs;
    }

    /**
     * Definitions of the own fields as get_customer shows them.
     *
     * @return array
     */
    public static function infoDefsList()
    {
        $list = array();
        foreach ( self::infoDefs() as $id => $field ) {
            $def = array( 'id' => $id, 'label' => wp_strip_all_tags( (string) $field->label ), 'type' => $field->type );
            if ( in_array( $field->type, array( 'drop-down', 'radio-buttons', 'checkboxes' ), true ) ) {
                $def['items'] = array_values( (array) $field->items );
            }
            if ( $field->type === 'time' ) {
                $def['delimiter'] = self::timeStep( $field );
            }
            $list[] = $def;
        }

        return $list;
    }

    /**
     * Step of the times of a time field in minutes.
     *
     * @param \stdClass $field
     * @return int
     */
    public static function timeStep( $field )
    {
        return isset( $field->delimiter ) && (int) $field->delimiter > 0 ? (int) $field->delimiter : 60;
    }

    /**
     * Whether an own-field value is empty ('' or an empty list).
     *
     * @param mixed $value
     * @return bool
     */
    public static function infoEmpty( $value )
    {
        return $value === null || $value === '' || $value === array();
    }

    /**
     * Customer as the dialog has it: the fields set_customer changes plus the ones it keeps.
     *
     * @param array $row a row of bookly_customers
     * @return array
     */
    public static function fromRow( array $row )
    {
        $tags = $row['tags'] !== null && $row['tags'] !== '' ? json_decode( $row['tags'], true ) : array();
        $info = json_decode( (string) $row['info_fields'], true );
        $info_fields = array();
        foreach ( is_array( $info ) ? $info : array() as $field ) {
            if ( isset( $field['id'] ) ) {
                $info_fields[] = array( 'id' => (int) $field['id'], 'value' => array_key_exists( 'value', $field ) ? $field['value'] : '' );
            }
        }
        $values = array(
            'full_name' => (string) $row['full_name'],
            'first_name' => (string) $row['first_name'],
            'last_name' => (string) $row['last_name'],
            'phone' => (string) $row['phone'],
            'email' => (string) $row['email'],
            'birthday' => $row['birthday'] === null || $row['birthday'] === '' ? null : (string) $row['birthday'],
        );
        foreach ( array_keys( self::$address ) as $part ) {
            $values[ $part ] = (string) $row[ $part ];
        }

        return array_merge( $values, array(
            'notes' => (string) $row['notes'],
            'group_id' => $row['group_id'] === null ? null : (int) $row['group_id'],
            'tags' => is_array( $tags ) ? array_values( $tags ) : array(),
            'info_fields' => $info_fields,
            'wp_user_id' => $row['wp_user_id'] === null ? null : (int) $row['wp_user_id'],
            'attachment_id' => $row['attachment_id'] === null ? null : (int) $row['attachment_id'],
        ) );
    }

    /**
     * Values of a customer the dialog creates: empty fields, the own fields without values.
     *
     * @return array
     */
    public static function blank()
    {
        $info_fields = array();
        foreach ( self::infoDefs() as $id => $field ) {
            $info_fields[] = array( 'id' => $id, 'value' => $field->type === 'checkboxes' ? array() : '' );
        }
        $values = array( 'full_name' => '', 'first_name' => '', 'last_name' => '', 'phone' => '', 'email' => '', 'birthday' => null );
        foreach ( array_keys( self::$address ) as $part ) {
            $values[ $part ] = '';
        }

        return array_merge( $values, array(
            'notes' => '',
            'group_id' => null,
            'tags' => array(),
            'info_fields' => $info_fields,
            'wp_user_id' => null,
            'attachment_id' => null,
        ) );
    }

    /**
     * @param int $id
     * @return array|null
     */
    public static function row( $id )
    {
        $row = Lib\Entities\Customer::query()->where( 'id', (int) $id )->fetchRow();

        return $row ?: null;
    }

    /**
     * Whether $id is an existing customer or "$N" of one created earlier in the proposal.
     *
     * @param mixed      $id
     * @param State|null $state
     * @return bool
     */
    public static function exists( $id, $state = null )
    {
        if ( $state && $state->isNew( $id ) === 'customer' ) {
            return true;
        }

        return ( is_int( $id ) || ( is_string( $id ) && ctype_digit( $id ) ) ) && (int) $id > 0 && self::row( $id ) !== null;
    }

    /**
     * @param mixed $id
     * @return int|string int for an existing customer, "$N" as is
     */
    public static function id( $id )
    {
        return is_string( $id ) && $id !== '' && $id[0] === '$' ? $id : (int) $id;
    }

    /**
     * Values of a customer after the earlier items of the proposal.
     *
     * @param int|string $id
     * @param State|null $state
     * @return array
     */
    public static function values( $id, $state = null )
    {
        if ( $state && $state->has( 'customer:' . $id ) ) {
            return $state->get( 'customer:' . $id );
        }
        $row = is_int( $id ) ? self::row( $id ) : null;

        return $row ? self::fromRow( $row ) : self::blank();
    }

    /**
     * Record the values of a customer after the current item.
     *
     * @param State      $state
     * @param int|string $id
     * @param array      $values
     */
    public static function record( State $state, $id, array $values )
    {
        $state->set( 'customer:' . $id, $values );
        $touched = $state->has( 'list:customers' ) ? $state->get( 'list:customers' ) : array();
        $touched[ (string) $id ] = $id;
        $state->set( 'list:customers', $touched );
    }

    /**
     * Name of a customer as the interface shows it.
     *
     * @param array $values
     * @return string
     */
    public static function name( array $values )
    {
        if ( self::nameMode() === 'first_last_name' && ( $values['first_name'] !== '' || $values['last_name'] !== '' ) ) {
            return trim( $values['first_name'] . ' ' . $values['last_name'] );
        }

        return $values['full_name'];
    }

    /**
     * Customer other than $id that has this email (without case) or this phone (as saved), after
     * the earlier items of the proposal.
     *
     * @param string          $field email | phone
     * @param string          $value
     * @param int|string|null $id
     * @param State           $state
     * @return string|null name and id of that customer
     */
    public static function duplicate( $field, $value, $id, State $state )
    {
        $same = function ( $a ) use ( $field, $value ) {
            return $field === 'email' ? strtolower( $a ) === strtolower( $value ) : $a === $value;
        };
        $touched = $state->has( 'list:customers' ) ? $state->get( 'list:customers' ) : array();
        foreach ( $touched as $other ) {
            if ( (string) $other !== (string) $id ) {
                $values = $state->get( 'customer:' . $other );
                if ( $same( $values[ $field ] ) ) {
                    return '"' . self::name( $values ) . '" (' . ( is_int( $other ) ? 'id ' . $other : 'created by item ' . substr( $other, 1 ) . ' of this proposal' ) . ')';
                }
            }
        }
        $rows = Lib\Entities\Customer::query()
            ->select( 'id, full_name, first_name, last_name, ' . $field )
            ->where( $field, $value )
            ->fetchArray();
        foreach ( $rows as $row ) {
            if ( (string) $row['id'] === (string) $id || isset( $touched[ (string) $row['id'] ] ) || ! $same( $row[ $field ] ) ) {
                continue;
            }

            return '"' . $row['full_name'] . '" (id ' . $row['id'] . ')';
        }

        return null;
    }

    /**
     * Number of appointments of a customer.
     *
     * @param int $id
     * @return int
     */
    public static function appointments( $id )
    {
        return count( array_unique( Lib\Entities\CustomerAppointment::query()
            ->where( 'customer_id', $id )
            ->fetchCol( 'appointment_id' ) ) );
    }

    /**
     * Rows of the customer tag catalog by id; empty without Pro.
     *
     * @return array
     */
    public static function tagRows()
    {
        $rows = array();
        if ( Lib\Config::proActive() ) {
            foreach ( (array) Lib\Proxy\Pro::getTagRows( 'customer' ) as $row ) {
                $rows[ (int) $row['id'] ] = $row;
            }
        }

        return $rows;
    }

    /**
     * Whether some customer has the tag.
     *
     * @param string $tag
     * @return bool
     */
    public static function tagUsed( $tag )
    {
        $lists = Lib\Entities\Customer::query()
            ->whereNot( 'tags', null )
            ->whereLike( 'tags', '%' . trim( json_encode( (string) $tag, 256 ), '"' ) . '%' )
            ->fetchCol( 'tags' );
        foreach ( $lists as $json ) {
            $list = json_decode( $json, true );
            if ( is_array( $list ) && in_array( $tag, $list, true ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Save a customer with the whole set of values, as the dialog's Save does: the same setters
     * in the same order and the same add-on handling of tags and own fields (the dialog's
     * prepareSaveCustomer with a request holding the set). Customer::save() derives the name
     * fields from each other and tells the add-ons (Mailchimp) about the save.
     *
     * @param int|null $id null for a new customer
     * @param array    $values see fromRow()
     * @return Lib\Entities\Customer
     */
    public static function write( $id, array $values )
    {
        $customer = new Lib\Entities\Customer();
        if ( $id ) {
            $customer->load( $id );
        }
        $customer->setWpUserId( $values['wp_user_id'] );
        $info_fields = array();
        foreach ( $values['info_fields'] as $field ) {
            $info_fields[] = array( 'id' => $field['id'], 'value' => $field['value'] );
        }
        $request = new Lib\Utils\Collection( array(
            'wp_user_id' => $values['wp_user_id'],
            'full_name' => $values['full_name'],
            'first_name' => $values['first_name'],
            'last_name' => $values['last_name'],
            'email' => $values['email'],
            'tags' => $values['tags'] ? array_values( $values['tags'] ) : null,
            'tagColors' => array(),
            'info_fields' => $info_fields,
        ) );
        DialogProxy\Shared::prepareSaveCustomer( array( 'errors' => array() ), $request, $customer );
        $customer
            ->setBirthday( $values['birthday'] )
            ->setGroupId( $values['group_id'] )
            ->setFirstName( $values['first_name'] )
            ->setLastName( $values['last_name'] )
            ->setFullName( $values['full_name'] )
            ->setPhone( $values['phone'] )
            ->setEmail( $values['email'] )
            ->setCountry( $values['country'] )
            ->setState( $values['state'] )
            ->setPostcode( $values['postcode'] )
            ->setCity( $values['city'] )
            ->setStreet( $values['street'] )
            ->setStreetNumber( $values['street_number'] )
            ->setAdditionalAddress( $values['additional_address'] )
            ->setNotes( $values['notes'] )
            ->setAttachmentId( $values['attachment_id'] )
            ->save();

        return $customer;
    }

    /**
     * Whether saving a customer sends it to the Mailchimp audience.
     *
     * @return bool
     */
    public static function mailchimpConnected()
    {
        return Lib\Config::mailchimpActive()
            && get_option( 'bookly_mailchimp_api_key' ) != ''
            && get_option( 'bookly_mailchimp_server' ) != ''
            && get_option( 'bookly_mailchimp_audience_id' ) != '';
    }
}
