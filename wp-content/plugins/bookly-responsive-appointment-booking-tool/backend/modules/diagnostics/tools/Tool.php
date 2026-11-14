<?php
namespace Bookly\Backend\Modules\Diagnostics\Tools;

use Bookly\Lib\Base\Component;

abstract class Tool extends Component
{
    /** @var bool */
    protected $hidden = false;
    /** @var string */
    protected $slug;
    /** @var string */
    protected $title;
    /** @var int */
    public $position;
    /**
     * Ajax methods that only touch Bookly data and are therefore available to users with 'manage_bookly'.
     * Everything not listed here requires 'manage_options', because it either changes the site
     * outside of Bookly or exposes the site owner's credentials.
     *
     * @var array
     */
    protected $bookly_methods = array();

    /**
     * Options that hold third party credentials.
     * They are never exposed to users without 'manage_options'.
     *
     * @return array
     */
    public static function getSensitiveOptions()
    {
        return array(
            'bookly_gc_client_id',
            'bookly_gc_client_secret',
            'bookly_oc_app_id',
            'bookly_oc_app_secret',
            'bookly_zoom_oauth_client_id',
            'bookly_zoom_oauth_client_secret',
            'bookly_smtp_host',
            'bookly_smtp_port',
            'bookly_smtp_user',
            'bookly_smtp_password',
            'bookly_cloud_token',
        );
    }

    /**
     * Check whether the current user may call the given ajax method.
     * The caller is already known to pass Common::isCurrentUserAdmin().
     *
     * @param string $method
     * @return bool
     */
    public function isMethodAllowed( $method )
    {
        return current_user_can( 'manage_options' )
            || in_array( $method, $this->bookly_methods, true );
    }

    /**
     * Stop the request unless the current user has every listed capability.
     *
     * @param array $capabilities
     */
    protected static function verifyCapability( array $capabilities )
    {
        foreach ( $capabilities as $capability ) {
            if ( ! current_user_can( $capability ) ) {
                wp_send_json_error( array( 'message' => __( 'You do not have sufficient permissions to access this page.', 'bookly-responsive-appointment-booking-tool' ) ) );
            }
        }
    }

    /**
     * Render template
     *
     * @return string
     */
    public function render()
    {
        return '';
    }

    /**
     * Get tool slug.
     *
     * @return string
     */
    public function getSlug()
    {
        return $this->slug;
    }

    /**
     * Get tool title.
     *
     * @return string
     */
    public function getTitle()
    {
        return $this->title;
    }

    /**
     * Get tool broken.
     *
     * @return bool
     */
    public function hasError()
    {
        return false;
    }

    /**
     * Get tool hidden.
     *
     * @return bool
     */
    public function isHidden()
    {
        return $this->hidden;
    }
}