<?php
namespace Bookly\Backend\Modules\Diagnostics\Tests;

use Bookly\Lib;

class LogErrors extends Test
{
    protected $slug = 'check-log-errors';
    public $error_type = 'error';
    
    public function __construct()
    {
        $this->title = __( 'Critical errors', 'bookly-responsive-appointment-booking-tool' );
        $this->description = __( 'This test checks for critical errors in Bookly.', 'bookly-responsive-appointment-booking-tool' );
    }

    /**
     * @inheritDoc
     */
    public function run()
    {
        $errors = Lib\Entities\Log::query( 'l' )
            ->where( 'action', Lib\Utils\Log::ACTION_ERROR )
            ->whereGt( 'created_at', date_create( current_time( 'mysql' ) )->modify( '-1 day' )->format( 'Y-m-d H:i:s' ) )
            ->whereLike( 'target', '%bookly-%' )
            ->count();

        if ( $errors ) {
            $this->addError( __( 'Some critical errors in Bookly were found recently.', 'bookly-responsive-appointment-booking-tool' ) . ' ' . __( 'Please contact Bookly support.', 'bookly-responsive-appointment-booking-tool' ) );
        }

        return empty( $this->errors );
    }
}