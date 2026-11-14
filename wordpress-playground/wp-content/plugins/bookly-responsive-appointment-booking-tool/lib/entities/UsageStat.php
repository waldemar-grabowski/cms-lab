<?php
namespace Bookly\Lib\Entities;

use Bookly\Lib;

/**
 * Counter of one usage event with one set of properties, accumulated until sent to Cloud.
 * Rows are written by Lib\UsageStats::track().
 */
class UsageStat extends Lib\Base\Entity
{
    /** @var string */
    protected $name;
    /** @var string */
    protected $props;
    /** @var string */
    protected $props_hash;
    /** @var int */
    protected $count;

    protected static $table = 'bookly_usage_stats';

    protected static $schema = array(
        'id' => array( 'format' => '%d' ),
        'name' => array( 'format' => '%s' ),
        'props' => array( 'format' => '%s' ),
        'props_hash' => array( 'format' => '%s' ),
        'count' => array( 'format' => '%d' ),
    );

    /**************************************************************************
     * Entity Fields Getters & Setters                                        *
     **************************************************************************/

    /**
     * Gets name
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Sets name
     *
     * @param string $name
     * @return $this
     */
    public function setName( $name )
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Gets props
     *
     * @return string
     */
    public function getProps()
    {
        return $this->props;
    }

    /**
     * Sets props
     *
     * @param string $props
     * @return $this
     */
    public function setProps( $props )
    {
        $this->props = $props;

        return $this;
    }

    /**
     * Gets props_hash
     *
     * @return string
     */
    public function getPropsHash()
    {
        return $this->props_hash;
    }

    /**
     * Sets props_hash
     *
     * @param string $props_hash
     * @return $this
     */
    public function setPropsHash( $props_hash )
    {
        $this->props_hash = $props_hash;

        return $this;
    }

    /**
     * Gets count
     *
     * @return int
     */
    public function getCount()
    {
        return $this->count;
    }

    /**
     * Sets count
     *
     * @param int $count
     * @return $this
     */
    public function setCount( $count )
    {
        $this->count = $count;

        return $this;
    }
}
