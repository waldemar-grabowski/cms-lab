<?php
namespace Bookly\Lib\Entities;

use Bookly\Lib;

class MailingList extends Lib\Base\Entity
{
    /** @var string */
    protected $name;

    protected static $table = 'bookly_mailing_lists';

    protected static $schema = array(
        'id' => array( 'format' => '%d' ),
        'name' => array( 'format' => '%s' ),
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

    /**************************************************************************
     * Public Methods                                                         *
     **************************************************************************/

    /**
     * Delete a list unless some campaign still goes to it.
     *
     * @param int $list_id
     */
    public static function releaseUnused( $list_id )
    {
        if ( $list_id && ! MailingCampaign::query()->where( 'mailing_list_id', $list_id )->count() ) {
            self::query()->delete()->where( 'id', $list_id )->execute();
        }
    }
}