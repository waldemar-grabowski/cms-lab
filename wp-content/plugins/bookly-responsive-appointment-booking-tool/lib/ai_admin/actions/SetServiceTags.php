<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\Format;
use Bookly\Lib\AiAdmin\Services\ServiceAction;
use Bookly\Lib\AiAdmin\State;

/**
 * set_service_tags — add tags to one service or remove them; the other tags stay (Pro). New names
 * create catalog tags, a tag removed from its last service leaves the catalog
 * (assets/ai-setup/actions/set_service_tags.yaml).
 */
class SetServiceTags extends ServiceAction implements ActionInterface
{
    protected function name()
    {
        return 'set_service_tags';
    }

    /**
     * @inheritDoc
     */
    public function validate( array $args, State $state )
    {
        if ( $error = $this->forbidden() ) {
            return array( $error );
        }
        $id = isset( $args['service_id'] ) ? self::id( $args['service_id'] ) : 0;
        if ( ! self::exists( $id, $state ) ) {
            return array( 'service_not_found: ' . wp_json_encode( isset( $args['service_id'] ) ? $args['service_id'] : null ) );
        }
        $rule = Access::rule( 'field:service.tags' );
        if ( ! $rule || empty( $rule['edit'] ) || ! Access::allowed( $rule['edit'] ) ) {
            return array( 'forbidden: tags cannot be changed by the assistant' );
        }
        if ( ! Lib\Config::proActive() ) {
            return array( 'addon_inactive: tags need the pro add-on' );
        }
        foreach ( array_keys( $args ) as $key ) {
            if ( ! in_array( $key, array( 'service_id', 'add', 'remove', 'new_tag_colors' ), true ) ) {
                return array( 'arguments: unknown argument ' . $key . ' — the action takes service_id, add, remove, new_tag_colors' );
            }
        }
        $add = isset( $args['add'] ) ? $args['add'] : array();
        $remove = isset( $args['remove'] ) ? $args['remove'] : array();
        if ( ! is_array( $add ) || ! is_array( $remove ) ) {
            return array( 'arguments: add and remove are lists of tag names' );
        }
        if ( ! $add && ! $remove ) {
            return array( 'nothing_to_change: add and remove are both empty' );
        }
        $errors = array();
        $seen = array();
        $current = array_map( 'mb_strtolower', self::current( $state, $id, 'tags' ) );
        foreach ( $add as $tag ) {
            if ( ! is_string( $tag ) || trim( $tag ) === '' ) {
                $errors[] = 'tag_empty: ' . wp_json_encode( $tag );
            } elseif ( mb_strlen( $tag ) > 255 ) {
                $errors[] = 'tag_empty: a tag name is at most 255 characters';
            } elseif ( in_array( mb_strtolower( $tag ), $seen, true ) ) {
                $errors[] = 'tag_duplicate: ' . $tag;
            } elseif ( in_array( mb_strtolower( $tag ), $current, true ) ) {
                $errors[] = 'tag_already_present: the service already has ' . $tag;
            } else {
                $seen[] = mb_strtolower( $tag );
            }
        }
        foreach ( $remove as $tag ) {
            if ( ! is_string( $tag ) || ! in_array( mb_strtolower( $tag ), $current, true ) ) {
                $errors[] = 'tag_not_present: the service has no tag ' . wp_json_encode( $tag ) . ' (get_service.tags)';
            } elseif ( in_array( mb_strtolower( $tag ), $seen, true ) ) {
                $errors[] = 'tag_duplicate: ' . $tag . ' is both in add and in remove';
            }
        }
        if ( $errors ) {
            return $errors;
        }
        $tags = $this->changed( self::current( $state, $id, 'tags' ), $args, $state );
        $catalog = $this->catalog( $state );
        $colors = Lib\Proxy\Pro::getTagsData( 'service' );
        $colors = $colors['colors'];
        foreach ( isset( $args['new_tag_colors'] ) && is_array( $args['new_tag_colors'] ) ? $args['new_tag_colors'] : array() as $name => $color_id ) {
            if ( isset( $catalog[ mb_strtolower( $name ) ] ) || ! in_array( mb_strtolower( $name ), $seen, true ) ) {
                $errors[] = 'color_out_of_range: ' . $name . ' is not a new tag of this list';
            } elseif ( ! is_int( $color_id ) || ! isset( $colors[ $color_id ] ) ) {
                $errors[] = 'color_out_of_range: ' . $name . ' — an index of get_service.allowed.tag_colors';
            }
        }
        if ( ! $errors ) {
            $this->record( $id, $tags, $state );
        }

        return $errors;
    }

    /**
     * Tag catalog after the earlier items: lower-case name => array( tag, color_id ).
     *
     * @param State $state
     * @return array
     */
    protected function catalog( State $state )
    {
        if ( $state->has( 'catalog:service_tags' ) ) {
            return $state->get( 'catalog:service_tags' );
        }
        $catalog = array();
        foreach ( (array) Lib\Proxy\Pro::getTagRows( 'service' ) as $row ) {
            $catalog[ mb_strtolower( $row['tag'] ) ] = array( 'tag' => $row['tag'], 'color_id' => (int) $row['color_id'] );
        }

        return $catalog;
    }

    /**
     * Names with the catalog spelling of existing tags.
     *
     * @param array $tags
     * @param State $state
     * @return string[]
     */
    protected function normalize( array $tags, State $state )
    {
        $catalog = $this->catalog( $state );
        $result = array();
        foreach ( $tags as $tag ) {
            $key = mb_strtolower( $tag );
            $result[] = isset( $catalog[ $key ] ) ? $catalog[ $key ]['tag'] : $tag;
        }

        return $result;
    }

    /**
     * Tags after the change: the current ones without those in remove (any case), then the names
     * of add with the catalog spelling of existing tags.
     *
     * @param string[]   $current
     * @param array      $args
     * @param State|null $state null — read the catalog from the database
     * @return string[]
     */
    protected function changed( array $current, array $args, $state )
    {
        $remove = array_map( 'mb_strtolower', isset( $args['remove'] ) ? $args['remove'] : array() );
        $tags = array();
        foreach ( $current as $tag ) {
            if ( ! in_array( mb_strtolower( $tag ), $remove, true ) ) {
                $tags[] = $tag;
            }
        }
        $add = isset( $args['add'] ) ? $args['add'] : array();
        if ( $state ) {
            $add = $this->normalize( $add, $state );
        } else {
            $catalog = array();
            foreach ( (array) Lib\Proxy\Pro::getTagRows( 'service' ) as $row ) {
                $catalog[ mb_strtolower( $row['tag'] ) ] = $row['tag'];
            }
            foreach ( $add as $i => $tag ) {
                if ( isset( $catalog[ mb_strtolower( $tag ) ] ) ) {
                    $add[ $i ] = $catalog[ mb_strtolower( $tag ) ];
                }
            }
        }

        return array_values( array_merge( $tags, $add ) );
    }

    /**
     * @param int|string $id
     * @param string[]   $tags
     * @param State      $state
     */
    protected function record( $id, array $tags, State $state )
    {
        $catalog = $this->catalog( $state );
        foreach ( $tags as $tag ) {
            if ( ! isset( $catalog[ mb_strtolower( $tag ) ] ) ) {
                $catalog[ mb_strtolower( $tag ) ] = array( 'tag' => $tag, 'color_id' => 0 );
            }
        }
        $state->set( 'catalog:service_tags', $catalog );
        self::setCurrent( $state, $id, 'tags', $tags );
    }

    /**
     * Tags no other service than $service_id has.
     *
     * @param string[] $tags
     * @param int      $service_id
     * @return string[]
     */
    protected static function unused( array $tags, $service_id )
    {
        $unused = array();
        foreach ( $tags as $tag ) {
            $exists = Lib\Entities\Service::query()
                ->whereNot( 'id', $service_id )
                ->whereNot( 'tags', null )
                ->whereLike( 'tags', '%"' . trim( json_encode( $tag, 256 ), '"' ) . '"%' )
                ->count();
            if ( ! $exists ) {
                $unused[] = $tag;
            }
        }

        return $unused;
    }

    /**
     * @inheritDoc
     */
    public function preview( array $args, State $state )
    {
        $id = self::id( $args['service_id'] );
        $before = self::current( $state, $id, 'tags' );
        $tags = $this->changed( $before, $args, $state );
        $catalog = $this->catalog( $state );
        $rule = Access::rule( 'field:service.tags' );
        $colors = isset( $args['new_tag_colors'] ) ? $args['new_tag_colors'] : array();
        $after = array();
        foreach ( $tags as $tag ) {
            $after[] = isset( $catalog[ mb_strtolower( $tag ) ] )
                ? $tag
                : $tag . ' (' . __( 'new', 'bookly-responsive-appointment-booking-tool' ) . ', ' . __( 'color', 'bookly-responsive-appointment-booking-tool' ) . ' ' . ( isset( $colors[ $tag ] ) ? $colors[ $tag ] : 0 ) . ')';
        }
        $rows = array( array(
            'label' => self::rowLabel( $state, $id, Format::label( $rule, 'tags' ) ),
            'before' => $before ? implode( ', ', $before ) : '—',
            'after' => $after ? implode( ', ', $after ) : '—',
            'link' => Format::link( $rule ),
        ) );
        $warnings = array();
        if ( is_int( $id ) ) {
            $removed = array();
            foreach ( $before as $tag ) {
                if ( ! in_array( mb_strtolower( $tag ), array_map( 'mb_strtolower', $tags ), true ) ) {
                    $removed[] = $tag;
                }
            }
            $unused = self::unused( $removed, $id );
            if ( $unused ) {
                $warnings[] = sprintf( __( 'No other service has these tags, they are deleted together with their image and info: %s', 'bookly-responsive-appointment-booking-tool' ), implode( ', ', $unused ) );
            }
        }
        $this->record( $id, $tags, $state );

        return array( 'rows' => $rows, 'warnings' => $warnings );
    }

    /**
     * @inheritDoc
     */
    public function apply( array $args )
    {
        $service = Lib\Entities\Service::find( $args['service_id'], false );
        $catalog = array();
        foreach ( (array) Lib\Proxy\Pro::getTagRows( 'service' ) as $row ) {
            $catalog[ mb_strtolower( $row['tag'] ) ] = $row;
        }
        $before = $service->getTags();
        $old = $before ? (array) json_decode( $before, true ) : array();
        $tags = $this->changed( $old, $args, null );
        $snapshot = array( 'service_id' => (int) $service->getId(), 'tags' => $before, 'created' => array(), 'deleted' => array() );

        $service->setTags( $tags ? json_encode( $tags, 256 ) : null )->save();
        $colors = isset( $args['new_tag_colors'] ) ? $args['new_tag_colors'] : array();
        foreach ( $tags as $tag ) {
            if ( ! isset( $catalog[ mb_strtolower( $tag ) ] ) ) {
                $snapshot['created'][] = Lib\Proxy\Pro::insertTagRow( array(
                    'tag' => $tag,
                    'type' => 'service',
                    'color_id' => isset( $colors[ $tag ] ) ? (int) $colors[ $tag ] : 0,
                ) );
            }
        }
        $removed = array();
        foreach ( $old as $tag ) {
            if ( ! in_array( mb_strtolower( $tag ), array_map( 'mb_strtolower', $tags ), true ) ) {
                $removed[] = $tag;
            }
        }
        foreach ( self::unused( $removed, $service->getId() ) as $tag ) {
            if ( isset( $catalog[ mb_strtolower( $tag ) ] ) ) {
                $snapshot['deleted'][] = $catalog[ mb_strtolower( $tag ) ];
                Lib\Proxy\Pro::deleteTagRows( array( $catalog[ mb_strtolower( $tag ) ]['id'] ) );
            }
        }

        return $snapshot;
    }

    /**
     * @inheritDoc
     */
    public function undo( array $snapshot )
    {
        $service = Lib\Entities\Service::find( $snapshot['service_id'], false );
        if ( $service ) {
            $service->setTags( $snapshot['tags'] )->save();
        }
        Lib\Proxy\Pro::deleteTagRows( $snapshot['created'] );
        foreach ( $snapshot['deleted'] as $row ) {
            Lib\Proxy\Pro::insertTagRow( $row );
        }
    }
}
