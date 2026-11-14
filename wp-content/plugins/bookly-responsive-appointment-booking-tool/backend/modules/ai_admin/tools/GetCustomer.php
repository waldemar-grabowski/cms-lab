<?php
namespace Bookly\Backend\Modules\AiAdmin\Tools;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\Actions\CustomerData;
use Bookly\Lib\AiAdmin\Actions\SetCustomerGroup;

/**
 * get_customer({customer_id}) — one customer in the shape set_customer takes, with what the
 * customer dialog offers on this site.
 */
class GetCustomer extends Tool
{
    const NAME = 'get_customer';

    public function getName()
    {
        return self::NAME;
    }

    protected function description()
    {
        return 'One customer in the shape set_customer takes: customer, wp_user, appointments, info_field_defs, allowed values.';
    }

    protected function parameters()
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'customer_id' => array( 'type' => 'integer', 'description' => 'Id from find_customers.' ),
            ),
            'required' => array( 'customer_id' ),
        );
    }

    public function execute( array $arguments )
    {
        if ( ! Access::allowed( array( 'admin' ) ) ) {
            return self::json( array( 'error' => 'forbidden' ) );
        }
        $id = isset( $arguments['customer_id'] ) ? (int) $arguments['customer_id'] : 0;
        $row = $id > 0 ? CustomerData::row( $id ) : null;
        if ( ! $row ) {
            return self::json( array( 'not_found' => true ) );
        }
        $values = CustomerData::fromRow( $row );
        $pro = Lib\Config::proActive();
        $groups = SetCustomerGroup::active();
        $info = Lib\Config::customerInformationActive();
        $unavailable = array();

        $customer = array( 'id' => $id );
        foreach ( array( 'full_name', 'first_name', 'last_name', 'phone', 'email' ) as $field ) {
            $customer[ $field ] = $values[ $field ];
        }
        if ( $pro ) {
            $customer['birthday'] = $values['birthday'];
            foreach ( array_keys( CustomerData::addressLimits() ) as $part ) {
                $customer[ $part ] = $values[ $part ];
            }
        } else {
            $unavailable['birthday'] = 'pro';
            $unavailable['address'] = 'pro';
            $unavailable['tags'] = 'pro';
        }
        $customer['notes'] = $values['notes'];
        if ( $groups ) {
            $customer['group_id'] = $values['group_id'];
        } else {
            $unavailable['group_id'] = 'customer-groups';
        }
        if ( $pro ) {
            $customer['tags'] = $values['tags'];
        }
        if ( $info ) {
            // One value per own field, as the dialog shows them; a field without a stored value is empty.
            $stored = array();
            foreach ( $values['info_fields'] as $field ) {
                $stored[ $field['id'] ] = $field['value'];
            }
            $customer['info_fields'] = array();
            foreach ( CustomerData::infoDefs() as $field_id => $def ) {
                $customer['info_fields'][] = array(
                    'id' => $field_id,
                    'value' => array_key_exists( $field_id, $stored ) ? $stored[ $field_id ] : ( $def->type === 'checkboxes' ? array() : '' ),
                );
            }
        } else {
            $unavailable['info_fields'] = 'customer-information';
        }

        $result = array( 'customer' => $customer );
        if ( $values['wp_user_id'] ) {
            $user = get_userdata( $values['wp_user_id'] );
            $result['wp_user'] = $user
                ? array( 'id' => (int) $user->ID, 'display_name' => $user->display_name )
                : array( 'id' => $values['wp_user_id'], 'display_name' => null );
        }
        $result['appointments'] = CustomerData::appointments( $id );
        if ( $info ) {
            $result['info_field_defs'] = CustomerData::infoDefsList();
        }
        $allowed = array( 'name_mode' => CustomerData::nameMode() );
        if ( $groups ) {
            $allowed['groups'] = array_keys( SetCustomerGroup::groups() );
        }
        if ( $pro ) {
            $allowed['address_parts'] = CustomerData::addressParts();
        }
        $allowed['phone_format'] = CustomerData::phoneFormat();
        $result['allowed'] = $allowed;
        if ( $unavailable ) {
            $result['unavailable'] = $unavailable;
        }

        return self::json( $result );
    }
}
