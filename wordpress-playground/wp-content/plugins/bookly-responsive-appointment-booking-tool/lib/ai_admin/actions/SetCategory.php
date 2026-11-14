<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\Format;
use Bookly\Lib\AiAdmin\Services\ServiceAction;
use Bookly\Lib\AiAdmin\State;

/**
 * set_category — create a service category or change one: name, info, place in the list
 * (assets/ai-setup/actions/set_category.yaml). Only the given fields of one category are written;
 * a new place renumbers the list 0…n-1 as the Categories dialog does.
 */
class SetCategory extends ServiceAction implements ActionInterface
{
    protected function name()
    {
        return 'set_category';
    }

    /**
     * Categories after the earlier items, in their order: array( id|"$N" => array( name, info ) ).
     *
     * @param State $state
     * @return array
     */
    public static function categories( State $state )
    {
        if ( $state->has( 'list:categories' ) ) {
            return $state->get( 'list:categories' );
        }
        $categories = array();
        foreach ( Lib\Entities\Category::query()->sortBy( 'position, id' )->fetchArray() as $row ) {
            $categories[ (int) $row['id'] ] = array( 'name' => (string) $row['name'], 'info' => (string) $row['info'] );
        }

        return $categories;
    }

    /**
     * @inheritDoc
     */
    public function validate( array $args, State $state )
    {
        if ( $error = $this->forbidden() ) {
            return array( $error );
        }
        foreach ( array( 'name', 'info', 'position' ) as $field ) {
            $rule = Access::rule( 'field:category.' . $field );
            if ( array_key_exists( $field, $args ) && ( ! $rule || empty( $rule['edit'] ) || ! Access::allowed( $rule['edit'] ) ) ) {
                return array( 'forbidden: category ' . $field . ' cannot be changed by the assistant' );
            }
        }
        $categories = self::categories( $state );
        $new = ! isset( $args['category_id'] );
        if ( ! $new && ! array_key_exists( (int) $args['category_id'], $categories ) ) {
            return array( 'category_not_found: ' . wp_json_encode( $args['category_id'] ) );
        }
        $errors = array();
        if ( $new && ! isset( $args['name'] ) ) {
            $errors[] = 'name_required: a new category needs a name';
        }
        if ( isset( $args['name'] ) ) {
            if ( ! is_string( $args['name'] ) || trim( $args['name'] ) === '' ) {
                $errors[] = 'name_required: the name is empty';
            } elseif ( mb_strlen( $args['name'] ) > 255 ) {
                $errors[] = 'name_too_long: at most 255 characters';
            }
        }
        if ( isset( $args['info'] ) && ! is_string( $args['info'] ) ) {
            $errors[] = 'name_required: info is a text';
        }
        if ( array_key_exists( 'position', $args ) ) {
            $count = count( $categories ) + ( $new ? 1 : 0 );
            if ( ! is_int( $args['position'] ) || $args['position'] < 0 || $args['position'] > $count - 1 ) {
                $errors[] = 'position_out_of_range: 0…' . ( $count - 1 );
            }
        }
        if ( ! $new && ! array_intersect_key( $args, array( 'name' => 1, 'info' => 1, 'position' => 1 ) ) ) {
            $errors[] = 'nothing_to_change: give name, info or position';
        }
        if ( ! $errors ) {
            $this->record( $args, $state );
        }

        return $errors;
    }

    /**
     * @param array $args
     * @param State $state
     * @return int|string id or "$N" of the category
     */
    protected function record( array $args, State $state )
    {
        $categories = self::categories( $state );
        if ( isset( $args['category_id'] ) ) {
            $id = (int) $args['category_id'];
        } else {
            $id = self::newRef( $state );
            $state->created( 'category' );
            $categories[ $id ] = array( 'name' => '', 'info' => '' );
        }
        foreach ( array( 'name', 'info' ) as $field ) {
            if ( isset( $args[ $field ] ) ) {
                $categories[ $id ][ $field ] = $args[ $field ];
            }
        }
        if ( array_key_exists( 'position', $args ) ) {
            $categories = self::move( $categories, $id, $args['position'] );
        }
        $state->set( 'list:categories', $categories );

        return $id;
    }

    /**
     * Move a key of an ordered array to a position.
     *
     * @param array      $list
     * @param int|string $key
     * @param int        $position
     * @return array
     */
    protected static function move( array $list, $key, $position )
    {
        $item = $list[ $key ];
        unset( $list[ $key ] );
        $result = array();
        $i = 0;
        foreach ( $list as $k => $v ) {
            if ( $i === $position ) {
                $result[ $key ] = $item;
            }
            $result[ $k ] = $v;
            $i++;
        }
        if ( ! array_key_exists( $key, $result ) ) {
            $result[ $key ] = $item;
        }

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function preview( array $args, State $state )
    {
        $before = self::categories( $state );
        $id = $this->record( $args, $state );
        $after = self::categories( $state );
        $new = ! isset( $args['category_id'] );
        // Each category is an object of its own: three new ones are three groups of the card.
        $object = Format::object(
            'category:' . $id,
            $new ? $args['name'] : $before[ $id ]['name'],
            __( 'New category', 'bookly-responsive-appointment-booking-tool' ),
            $new
        );
        $rows = array();
        $labels = array( 'name' => __( 'Name', 'bookly-responsive-appointment-booking-tool' ), 'info' => __( 'Info', 'bookly-responsive-appointment-booking-tool' ) );
        foreach ( $labels as $field => $label ) {
            if ( isset( $args[ $field ] ) ) {
                $rule = Access::rule( 'field:category.' . $field );
                $rows[] = array(
                    'label' => Format::rowLabel( $object, $label, array( 'name' => $field === 'name' && $new ) ),
                    'before' => $new || $before[ $id ][ $field ] === '' ? '—' : $before[ $id ][ $field ],
                    'after' => $args[ $field ] === '' ? '—' : $args[ $field ],
                    'link' => Format::link( $rule ),
                );
            }
        }
        $old_place = array_search( $id, array_keys( $before ), true );
        $new_place = array_search( $id, array_keys( $after ), true );
        if ( $new || $old_place !== $new_place ) {
            $rows[] = array(
                'label' => Format::rowLabel( $object, __( 'Position', 'bookly-responsive-appointment-booking-tool' ) ),
                'before' => $new ? '—' : '#' . ( $old_place + 1 ),
                'after' => '#' . ( $new_place + 1 ),
            );
        }
        $warnings = array();
        if ( ! $new ) {
            $count = Lib\Entities\Service::query()->where( 'category_id', $id )->count();
            $warnings[] = sprintf( _n( '%d service is in this category; it stays there.', '%d services are in this category; they stay there.', $count, 'bookly-responsive-appointment-booking-tool' ), $count );
        }

        return array( 'rows' => $rows, 'warnings' => $warnings );
    }

    /**
     * @inheritDoc
     */
    public function apply( array $args )
    {
        $positions = array();
        foreach ( Lib\Entities\Category::query()->sortBy( 'position, id' )->fetchArray() as $row ) {
            $positions[ (int) $row['id'] ] = (int) $row['position'];
        }
        $snapshot = array( 'positions' => $positions, 'created' => null, 'category' => null, 'after' => null );
        if ( isset( $args['category_id'] ) ) {
            $category = Lib\Entities\Category::find( $args['category_id'], false );
            $snapshot['category'] = $category->getFields();
        } else {
            $category = new Lib\Entities\Category();
            $category->setInfo( '' )->setPosition( $positions ? max( $positions ) + 1 : 0 );
        }
        if ( isset( $args['name'] ) ) {
            $category->setName( $args['name'] );
        }
        if ( isset( $args['info'] ) ) {
            $category->setInfo( $args['info'] );
        }
        $category->save();
        if ( ! isset( $args['category_id'] ) ) {
            $snapshot['created'] = (int) $category->getId();
            // Later items of the proposal refer to the new category as "$N".
            $snapshot['created_id'] = $snapshot['created'];
        }
        if ( array_key_exists( 'position', $args ) ) {
            $order = array_keys( $positions );
            if ( $snapshot['created'] ) {
                $order[] = $snapshot['created'];
            }
            $order = array_values( array_diff( $order, array( (int) $category->getId() ) ) );
            array_splice( $order, $args['position'], 0, array( (int) $category->getId() ) );
            foreach ( $order as $position => $category_id ) {
                Lib\Entities\Category::query()->update()->set( 'position', $position )->where( 'id', $category_id )->whereNot( 'position', $position )->execute();
            }
        }
        $snapshot['after'] = Lib\Entities\Category::query()->where( 'id', $category->getId() )->fetchRow();

        return $snapshot;
    }

    /**
     * @inheritDoc
     */
    public function undo( array $snapshot )
    {
        if ( $snapshot['created'] ) {
            $category = Lib\Entities\Category::find( $snapshot['created'], false );
            if ( $category ) {
                $category->delete();
            }
        } elseif ( $snapshot['category'] ) {
            $current = Lib\Entities\Category::query()->where( 'id', $snapshot['category']['id'] )->fetchRow();
            // Restore only when the category still has the values the assistant wrote.
            if ( ! $current || $current != $snapshot['after'] ) {
                return;
            }
            $category = Lib\Entities\Category::find( $snapshot['category']['id'], false );
            $category->setFields( $snapshot['category'] )->save();
        }
        foreach ( $snapshot['positions'] as $category_id => $position ) {
            Lib\Entities\Category::query()->update()->set( 'position', $position )->where( 'id', $category_id )->execute();
        }
    }
}
