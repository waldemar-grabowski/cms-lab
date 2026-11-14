<?php
namespace Bookly\Lib\AiAdmin;

/**
 * Values as they are after the earlier items of one proposal: the plugin checks the items in
 * order and each one sees the effect of the previous ones (README of the kit → «Одно
 * предложение — один вызов propose»). Reads fall through to the database.
 *
 * Objects created by earlier items are referenced as "$N" (N — number of the create_* item,
 * from 1): while checking, isNew( "$N" ) tells an action the id belongs to such an object and
 * of which entity; at apply time Proposal replaces "$N" with the real id.
 */
class State
{
    /** @var array ref => value set by earlier items */
    protected $overlay = array();
    /** @var array item number => entity created by it (service, staff, …) */
    protected $created = array();
    /** @var int number of the item being checked, from 1 */
    protected $item = 0;

    /**
     * @param string $ref option:<name> or field:<entity>.<field>[#<id>]
     * @return mixed
     */
    public function get( $ref )
    {
        if ( array_key_exists( $ref, $this->overlay ) ) {
            return $this->overlay[ $ref ];
        }
        if ( strpos( $ref, 'option:' ) === 0 ) {
            return get_option( substr( $ref, 7 ) );
        }

        return null;
    }

    /**
     * @param string $ref
     * @param mixed  $value
     */
    public function set( $ref, $value )
    {
        $this->overlay[ $ref ] = $value;
    }

    /**
     * Whether a value of the overlay is known (set by an earlier item).
     *
     * @param string $ref
     * @return bool
     */
    public function has( $ref )
    {
        return array_key_exists( $ref, $this->overlay );
    }

    /**
     * @param int $item
     */
    public function setItem( $item )
    {
        $this->item = $item;
    }

    /**
     * Number of the item being checked, from 1 — its own "$N" when it creates an object.
     *
     * @return int
     */
    public function item()
    {
        return $this->item;
    }

    /**
     * Record that the current item creates an object of $entity.
     *
     * @param string $entity
     */
    public function created( $entity )
    {
        $this->created[ $this->item ] = $entity;
    }

    /**
     * Objects created by the items checked so far.
     *
     * @return array item number => entity
     */
    public function createdItems()
    {
        return $this->created;
    }

    /**
     * Entity of the object a "$N" reference points to, or null when $value is not such a
     * reference (or N is not an earlier create_* item).
     *
     * @param mixed $value
     * @return string|null
     */
    public function isNew( $value )
    {
        if ( is_string( $value ) && preg_match( '/^\$(\d+)$/', $value, $m ) && (int) $m[1] < $this->item
            && isset( $this->created[ (int) $m[1] ] ) ) {
            return $this->created[ (int) $m[1] ];
        }

        return null;
    }

    /**
     * Reader for Constraints / conditions.
     *
     * @return \Closure
     */
    public function reader()
    {
        $state = $this;

        return function ( $ref ) use ( $state ) {
            return $state->get( $ref );
        };
    }
}
