<?php
namespace Bookly\Backend\Modules\Diagnostics\Tests;

use Bookly\Lib;

class SSL extends Test
{
    protected $slug = 'check-ssl';

    public function __construct()
    {
        $this->title = __( 'Secure connection', 'bookly-responsive-appointment-booking-tool' );
        $this->description = __( 'Some Bookly integrations require HTTPS connection and won\'t work with your website if there is no valid SSL certificate installed on your web server.', 'bookly-responsive-appointment-booking-tool' );
    }

    /**
     * @inheritDoc
     */
    public function run()
    {
        if ( ! is_ssl() ) {
            return false;
        }

        return empty( $this->errors );
    }
}