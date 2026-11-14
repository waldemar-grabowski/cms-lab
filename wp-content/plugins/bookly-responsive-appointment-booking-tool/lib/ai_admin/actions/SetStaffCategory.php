<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\Format;
use Bookly\Lib\AiAdmin\StaffData;
use Bookly\Lib\AiAdmin\State;

/**
 * set_staff_category — create a staff category or change its name, info or place in the list
 * (Pro, Staff Members → Categories…) (assets/ai-setup/actions/set_staff_category.yaml).
 */
class SetStaffCategory implements ActionInterface
{
    /**
     * @inheritDoc
     */
    public function validate( array $args, State $state )
    {
        foreach ( array( 'name', 'info', 'position' ) as $field ) {
            if ( $error = StaffData::forbidden( 'field:staff_category.' . $field ) ) {
                return array( $error );
            }
        }
        if ( ! Lib\Config::proActive() ) {
            return array( 'pro_required: staff categories need Pro' );
        }
        foreach ( array_keys( $args ) as $key ) {
            if ( ! in_array( $key, array( 'staff_category_id', 'name', 'info', 'position' ), true ) ) {
                return array( 'arguments: unknown argument ' . $key );
            }
        }
        $errors = array();
        $list   = StaffData::categories( $state );
        $index  = self::index( $list, $args );
        if ( $index === false ) {
            return array( 'category_not_found: ' . wp_json_encode( $args['staff_category_id'] ) );
        }
        $is_new = $index === null;
        if ( array_key_exists( 'name', $args ) || $is_new ) {
            $name = isset( $args['name'] ) && is_string( $args['name'] ) ? trim( $args['name'] ) : '';
            if ( $name === '' ) {
                $errors[] = 'name_required: the category name is empty';
            } elseif ( strlen( $name ) > 255 ) {
                $errors[] = 'name_too_long: at most 255 characters';
            }
        }
        if ( array_key_exists( 'info', $args ) && ! is_string( $args['info'] ) ) {
            $errors[] = 'info: a string';
        }
        if ( array_key_exists( 'position', $args ) ) {
            $last = count( $list ) - ( $is_new ? 0 : 1 );
            if ( ! is_int( $args['position'] ) || $args['position'] < 0 || $args['position'] > $last ) {
                $errors[] = 'position_out_of_range: 0…' . $last;
            }
        }
        if ( ! $errors && ! $is_new && wp_json_encode( self::merge( $list, $args, null ) ) === wp_json_encode( $list ) ) {
            $errors[] = 'nothing_to_change: the category already has these values';
        }
        if ( $is_new ) {
            $state->created( 'staff_category' );
        }
        if ( ! $errors ) {
            $state->set( 'staff_category:list', self::merge( $list, $args, $is_new ? StaffData::newRef( $state ) : null ) );
        }

        return $errors;
    }

    /**
     * @inheritDoc
     */
    public function preview( array $args, State $state )
    {
        $list   = StaffData::categories( $state );
        $index  = self::index( $list, $args );
        $is_new = $index === null;
        if ( $is_new ) {
            $state->created( 'staff_category' );
        }
        $ref   = $is_new ? StaffData::newRef( $state ) : $list[ $index ]['id'];
        $after = self::merge( $list, $args, $is_new ? $ref : null );
        $old   = $is_new ? array( 'name' => '', 'info' => '' ) : $list[ $index ];
        $new   = array();
        $place = 0;
        foreach ( $after as $i => $category ) {
            if ( (string) $category['id'] === (string) $ref ) {
                $new   = $category;
                $place = $i;
            }
        }
        // Each category is an object of its own: three new ones are three groups of the card.
        $object = Format::object(
            'staff_category:' . $ref,
            $is_new ? $new['name'] : $old['name'],
            __( 'New staff category', 'bookly-responsive-appointment-booking-tool' ),
            $is_new
        );
        $rows  = array();
        foreach ( array( 'name' => __( 'Name', 'bookly-responsive-appointment-booking-tool' ), 'info' => __( 'Info', 'bookly-responsive-appointment-booking-tool' ) ) as $field => $label ) {
            if ( $old[ $field ] !== $new[ $field ] ) {
                $rule   = Access::rule( 'field:staff_category.' . $field );
                $rows[] = array(
                    'label'  => Format::rowLabel( $object, $label, array( 'name' => $field === 'name' && $is_new ) ),
                    'before' => $old[ $field ] === '' ? '—' : $old[ $field ],
                    'after'  => $new[ $field ] === '' ? '—' : $new[ $field ],
                    'link'   => Format::link( $rule ),
                );
            }
        }
        if ( $is_new || $index !== $place ) {
            $rule   = Access::rule( 'field:staff_category.position' );
            $rows[] = array(
                'label'  => Format::rowLabel( $object, __( 'Order', 'bookly-responsive-appointment-booking-tool' ) ),
                'before' => $is_new ? '—' : (string) ( $index + 1 ),
                'after'  => (string) ( $place + 1 ) . ' / ' . count( $after ),
                'link'   => Format::link( $rule ),
            );
        }
        $warnings = array();
        if ( ! $is_new && is_numeric( $ref ) ) {
            $count      = Lib\Entities\Staff::query()->where( 'category_id', (int) $ref )->count();
            $warnings[] = sprintf( _n( '%d staff member is in this category (unchanged).', '%d staff members are in this category (unchanged).', $count, 'bookly-responsive-appointment-booking-tool' ), $count );
        }
        $state->set( 'staff_category:list', $after );

        return array( 'rows' => $rows, 'warnings' => $warnings );
    }

    /**
     * @inheritDoc
     */
    public function apply( array $args )
    {
        $list   = StaffData::categories();
        $index  = self::index( $list, $args );
        $after  = self::merge( $list, $args, $index === null ? 'new' : null );
        $ids    = Lib\Proxy\Pro::saveStaffCategories( $after );
        $result = array( 'before' => $list );
        if ( $index === null ) {
            foreach ( $after as $i => $category ) {
                if ( $category['id'] === 'new' ) {
                    $result['created_id'] = (int) $ids[ $i ];
                }
            }
        }

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function undo( array $snapshot )
    {
        if ( ! isset( $snapshot['before'] ) ) {
            return;
        }
        if ( ! empty( $snapshot['created_id'] ) ) {
            Lib\Proxy\Pro::deleteStaffCategory( $snapshot['created_id'] );
        }
        Lib\Proxy\Pro::saveStaffCategories( $snapshot['before'] );
    }

    /**
     * Index of the edited category in the list, null for a new one, false when not found.
     *
     * @param array $list
     * @param array $args
     * @return int|null|false
     */
    protected static function index( array $list, array $args )
    {
        if ( ! isset( $args['staff_category_id'] ) ) {
            return null;
        }
        foreach ( $list as $i => $category ) {
            if ( (string) $category['id'] === (string) $args['staff_category_id'] ) {
                return $i;
            }
        }

        return false;
    }

    /**
     * Categories after the change, in the new order.
     *
     * @param array       $list
     * @param array       $args
     * @param string|null $new_id id of a created category ("$N" while checking)
     * @return array
     */
    protected static function merge( array $list, array $args, $new_id )
    {
        $index = self::index( $list, $args );
        if ( $index === null ) {
            $category = array( 'id' => $new_id, 'name' => '', 'info' => '' );
            $from     = count( $list );
        } else {
            $category = $list[ $index ];
            $from     = $index;
            array_splice( $list, $index, 1 );
        }
        if ( array_key_exists( 'name', $args ) ) {
            $category['name'] = trim( (string) $args['name'] );
        }
        if ( array_key_exists( 'info', $args ) ) {
            $category['info'] = (string) $args['info'];
        }
        $position = array_key_exists( 'position', $args ) ? (int) $args['position'] : $from;
        array_splice( $list, min( $position, count( $list ) ), 0, array( $category ) );

        return array_values( $list );
    }
}
