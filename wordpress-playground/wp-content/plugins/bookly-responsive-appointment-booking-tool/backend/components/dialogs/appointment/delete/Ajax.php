<?php
namespace Bookly\Backend\Components\Dialogs\Appointment\Delete;

use Bookly\Lib;

class Ajax extends Lib\Base\Ajax
{
    /**
     * @inheritDoc
     */
    protected static function permissions()
    {
        return array( '_default' => array( 'staff', 'supervisor' ) );
    }

    /**
     * Delete single appointment.
     */
    public static function deleteAppointment()
    {
        $appointment = new Lib\Entities\Appointment();
        if ( ! $appointment->load( self::parameter( 'appointment_id' ) )
            || ! Lib\Utils\Common::currentUserCanManageStaff( $appointment->getStaffId() )
        ) {
            wp_send_json_error( array( 'message' => __( 'You are not allowed to manage this appointment.', 'bookly-responsive-appointment-booking-tool' ) ) );
        }

        wp_send_json( Lib\Utils\Appointment::delete(
            self::parameter( 'appointment_id' ),
            self::parameter( 'notify' ),
            self::parameter( 'reason' ) )
        );
    }
}