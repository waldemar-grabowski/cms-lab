<?php
namespace Bookly\Backend\Modules\AiAdmin\Tools;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Access;

/**
 * find_customers({search, limit}) — customers whose id, name, phone, email, tags (Pro) or own-field
 * values contain the text, newest first; the latest customers without a text.
 */
class FindCustomers extends Tool
{
    const NAME = 'find_customers';
    const MAX = 50;

    public function getName()
    {
        return self::NAME;
    }

    protected function description()
    {
        return 'Find customers by a text in id, name, phone, email, tags and own fields; returns ids for get_customer and set_customer.';
    }

    protected function parameters()
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'search' => array( 'type' => 'string', 'description' => 'Text to look for. Omit for the latest customers.' ),
                'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX ),
            ),
        );
    }

    public function execute( array $arguments )
    {
        global $wpdb;

        if ( ! Access::allowed( array( 'admin' ) ) ) {
            return self::json( array( 'error' => 'forbidden' ) );
        }
        $query = Lib\Entities\Customer::query( 'c' )
            ->select( 'c.id, c.full_name, c.phone, c.email, c.group_id, c.tags,
                (
                    SELECT COUNT(DISTINCT ca.appointment_id) FROM ' . Lib\Entities\CustomerAppointment::getTableName() . ' ca
                        WHERE ca.customer_id = c.id
                ) AS total_appointments' );
        $search = isset( $arguments['search'] ) && is_string( $arguments['search'] ) ? trim( $arguments['search'] ) : '';
        if ( $search !== '' ) {
            $columns = array( 'c.id', 'c.full_name', 'c.first_name', 'c.last_name', 'c.phone', 'c.email', 'c.info_fields' );
            if ( Lib\Config::proActive() ) {
                $columns[] = 'c.tags';
            }
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $conditions = array();
            foreach ( $columns as $column ) {
                $conditions[] = $column . ' LIKE %s';
            }
            $query->whereRaw( implode( ' OR ', $conditions ), array_fill( 0, count( $conditions ), $like ) );
        }
        $total = $query->count();
        $limit = isset( $arguments['limit'] ) ? max( 1, min( self::MAX, (int) $arguments['limit'] ) ) : 20;
        $groups = Lib\Config::customerGroupsActive();
        $customers = array();
        foreach ( $query->sortBy( 'c.id' )->order( 'DESC' )->limit( $limit )->fetchArray() as $row ) {
            $tags = $row['tags'] ? json_decode( $row['tags'], true ) : array();
            $customers[] = array(
                'id' => (int) $row['id'],
                'full_name' => $row['full_name'],
                'phone' => $row['phone'],
                'email' => $row['email'],
                'group_id' => $groups && $row['group_id'] !== null ? (int) $row['group_id'] : null,
                'tags' => is_array( $tags ) ? array_values( $tags ) : array(),
                'total_appointments' => (int) $row['total_appointments'],
            );
        }

        return self::json( array( 'total' => $total, 'customers' => $customers ) );
    }
}
