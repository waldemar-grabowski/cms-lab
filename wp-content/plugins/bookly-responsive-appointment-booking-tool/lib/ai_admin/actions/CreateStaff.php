<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\Constraints;
use Bookly\Lib\AiAdmin\Format;
use Bookly\Lib\AiAdmin\Rules;
use Bookly\Lib\AiAdmin\StaffData;
use Bookly\Lib\AiAdmin\State;

/**
 * create_staff — a new staff member with a full name, email and phone; the rest are the defaults
 * of New staff member (assets/ai-setup/actions/create_staff.yaml).
 */
class CreateStaff implements ActionInterface
{
    /**
     * @inheritDoc
     */
    public function validate( array $args, State $state )
    {
        $errors = array();
        foreach ( array( 'full_name', 'email', 'phone' ) as $field ) {
            if ( $error = StaffData::forbidden( 'field:staff.' . $field ) ) {
                return array( $error );
            }
        }
        foreach ( array_keys( $args ) as $key ) {
            if ( ! in_array( $key, array( 'full_name', 'email', 'phone' ), true ) ) {
                $errors[] = 'arguments: unknown argument ' . $key;
            }
        }
        if ( ! Lib\Config::proActive() && count( StaffData::order( $state ) ) > 0 ) {
            $errors[] = 'pro_required: without Pro only one staff member can exist';
        }
        $full_name = isset( $args['full_name'] ) && is_string( $args['full_name'] ) ? trim( $args['full_name'] ) : '';
        $email     = isset( $args['email'] ) && is_string( $args['email'] ) ? trim( $args['email'] ) : '';
        $entries   = Rules::entries();
        foreach ( Constraints::failed( $entries['field:staff.full_name'], $full_name, null, $state->reader() ) as $failed ) {
            $errors[] = $failed;
        }
        if ( $full_name === '' && ! preg_grep( '/^name_required/', $errors ) ) {
            $errors[] = 'name_required: full_name is empty';
        }
        foreach ( Constraints::failed( $entries['field:staff.email'], $email, null, $state->reader() ) as $failed ) {
            $errors[] = $failed;
        }
        if ( $email === '' ) {
            if ( ! preg_grep( '/^email_required/', $errors ) ) {
                $errors[] = 'email_required: email is empty';
            }
        } elseif ( ! is_email( $email ) ) {
            $errors[] = 'email_format: ' . $email . ' is not a valid address';
        }
        if ( isset( $args['phone'] ) && ( $error = self::phoneError( $args['phone'], $state ) ) ) {
            $errors[] = $error;
        }
        if ( strlen( $full_name ) > 255 || strlen( $email ) > 255 ) {
            $errors[] = 'name_too_long: full_name and email are at most 255 characters';
        }
        $this->record( $args, $state );

        return $errors;
    }

    /**
     * @inheritDoc
     */
    public function preview( array $args, State $state )
    {
        $rows      = array();
        $full_name = trim( $args['full_name'] );
        $ref       = $this->record( $args, $state );
        foreach ( array( 'full_name', 'email', 'phone' ) as $field ) {
            if ( isset( $args[ $field ] ) && trim( $args[ $field ] ) !== '' ) {
                $rule   = Access::rule( 'field:staff.' . $field );
                $rows[] = array(
                    'label'  => StaffData::rowLabel( $ref, $state, Format::label( $rule, $field ), array( 'name' => $field === 'full_name' ), true ),
                    'before' => '—',
                    'after'  => Format::value( $rule, trim( $args[ $field ] ) ),
                    'link'   => Format::link( $rule ),
                );
            }
        }
        $rule   = Access::rule( 'field:staff.visibility' );
        $rows[] = array(
            'label'  => StaffData::rowLabel( $ref, $state, Format::label( $rule, 'visibility' ), array(), true ),
            'before' => '—',
            'after'  => Format::value( $rule, 'public' ),
            'link'   => Format::link( $rule ),
        );
        // Defaults New staff member gets by itself: the company's hours.
        $rule     = Access::rule( 'field:staff_schedule_item.start_time' );
        $schedule = StaffData::schedule( $ref, $state );
        foreach ( StaffData::week() as $key ) {
            $rows[] = array(
                'label'  => StaffData::rowLabel( $ref, $state, Format::label( $rule, 'Schedule' ), array( 'day' => $key ), true ),
                'before' => '—',
                'after'  => StaffData::dayText( $schedule[ $key ] ),
                'link'   => null,
            );
        }

        $warnings = StaffData::actionWarnings( 'create_staff', $state );
        $warnings[] = __( 'The new staff member gets the company\'s business hours and days off, a random color and no services: clients cannot book them until services are assigned.', 'bookly-responsive-appointment-booking-tool' );
        $same = StaffData::sameNameWarning( $full_name );
        if ( $same !== null ) {
            $warnings[] = $same;
        }

        return array( 'rows' => $rows, 'warnings' => array_values( array_unique( $warnings ) ) );
    }

    /**
     * @inheritDoc
     */
    public function apply( array $args )
    {
        $staff = new Lib\Entities\Staff();
        $staff
            ->setFullName( trim( $args['full_name'] ) )
            ->setEmail( trim( $args['email'] ) )
            ->setPhone( isset( $args['phone'] ) ? trim( $args['phone'] ) : '' )
            ->save();

        return array( 'created_id' => (int) $staff->getId() );
    }

    /**
     * @inheritDoc
     */
    public function undo( array $snapshot )
    {
        if ( empty( $snapshot['created_id'] ) ) {
            return;
        }
        $staff = Lib\Entities\Staff::find( $snapshot['created_id'], false );
        // A staff member with appointments is not deleted: deleting a staff member deletes them.
        if ( $staff && Lib\Entities\Appointment::query()->where( 'staff_id', $staff->getId() )->count() == 0 ) {
            $staff->delete();
        }
    }

    /**
     * Record the new staff member in the state; returns its "$N".
     *
     * @param array $args
     * @param State $state
     * @return string
     */
    protected function record( array $args, State $state )
    {
        $state->created( 'staff' );
        $ref = StaffData::newRef( $state );
        foreach ( array( 'full_name', 'email', 'phone' ) as $field ) {
            $state->set( StaffData::ref( $field, $ref ), isset( $args[ $field ] ) && is_string( $args[ $field ] ) ? trim( $args[ $field ] ) : '' );
        }
        $order = StaffData::order( $state );
        if ( ! in_array( $ref, $order, true ) ) {
            $order[] = $ref;
        }
        $state->set( 'staff:order', $order );

        return $ref;
    }

    /**
     * phone_format check, shared with set_staff.
     *
     * @param mixed $phone
     * @param State $state
     * @return string|null
     */
    public static function phoneError( $phone, State $state )
    {
        if ( ! is_string( $phone ) ) {
            return 'phone_format: phone is a string';
        }
        $phone = trim( $phone );
        if ( $phone === '' ) {
            return null;
        }
        if ( strlen( $phone ) > 32 || ! preg_match( '/^\+?[0-9 ()\-]+$/', $phone ) ) {
            return 'phone_format: ' . $phone . ' — digits, spaces, dashes, brackets and an optional leading "+", at most 32 characters';
        }
        if ( $state->get( 'option:bookly_cst_phone_default_country' ) !== 'disabled' && $phone[0] !== '+' ) {
            return 'phone_format: ' . $phone . ' — the full number with "+" and the country code';
        }

        return null;
    }
}
