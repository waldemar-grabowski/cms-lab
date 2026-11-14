<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Backend\Modules\Services\Proxy as ServicesProxy;
use Bookly\Lib;
use Bookly\Lib\AiAdmin\Format;
use Bookly\Lib\AiAdmin\Rules;
use Bookly\Lib\AiAdmin\Services\ServiceAction;
use Bookly\Lib\AiAdmin\Services\ServiceData;
use Bookly\Lib\AiAdmin\State;

/**
 * duplicate_service — a Private copy of a service with its Providers, steps and the data add-ons
 * copy, as the Duplicate… button does (assets/ai-setup/actions/duplicate_service.yaml).
 */
class DuplicateService extends ServiceAction implements ActionInterface
{
    protected function name()
    {
        return 'duplicate_service';
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
        if ( ServiceData::freeLimitReached( (int) $state->get( 'count:services_created' ) ) ) {
            return array( 'free_limit: without Pro a site has at most 5 services' );
        }
        $this->record( $id, $state );

        return array();
    }

    /**
     * @inheritDoc
     */
    public function preview( array $args, State $state )
    {
        $entries = Rules::entries();
        $id = self::id( $args['service_id'] );
        $ref = $this->record( $id, $state );
        $object = self::cardObject( $state, $ref, true );
        $type = self::current( $state, $ref, 'type' );
        $rows = array(
            array( 'label' => Format::rowLabel( $object, Format::label( $entries['field:service.title'], 'title' ), array( 'name' => true ) ), 'before' => '—', 'after' => self::current( $state, $ref, 'title' ) ),
            array( 'label' => Format::rowLabel( $object, __( 'Type', 'bookly-responsive-appointment-booking-tool' ) ), 'before' => '—', 'after' => Format::value( $entries['field:service.type'], $type ) ),
            array( 'label' => Format::rowLabel( $object, Format::label( $entries['field:service.visibility'], 'visibility' ) ), 'before' => '—', 'after' => Format::value( $entries['field:service.visibility'], 'private' ) ),
        );
        $staff_ids = self::current( $state, $ref, 'staff_ids' );
        if ( $staff_ids ) {
            $rows[] = array( 'label' => Format::rowLabel( $object, Format::label( $entries['field:service.staff_ids'], 'staff_ids' ) ), 'before' => '—', 'after' => self::staffNames( $staff_ids ) );
        }
        $steps = array();
        foreach ( self::current( $state, $ref, 'steps' ) as $i => $step ) {
            // Numbered, not joined with "→": on the card the arrow means "before → after".
            $steps[] = ( $i + 1 ) . '. ' . ( isset( $step['service_id'] ) ? self::serviceTitle( $state, $step['service_id'] ) : Lib\Utils\DateTime::secondsToInterval( $step['spare_time'] ) );
        }
        if ( $steps ) {
            $rows[] = array( 'label' => Format::rowLabel( $object, __( 'Services', 'bookly-responsive-appointment-booking-tool' ) ), 'before' => '—', 'after' => implode( ', ', $steps ) );
        }
        $warnings = $this->actionWarnings( $state );
        $warnings[] = __( 'Special prices by time, customer groups, coupons, discounts and gift cards of the original service are not copied.', 'bookly-responsive-appointment-booking-tool' );
        if ( ! Lib\Config::proActive() ) {
            $warnings[] = __( 'Without Pro the booking forms do not apply Private, so the copy can be booked there at once.', 'bookly-responsive-appointment-booking-tool' );
        }

        return array( 'rows' => $rows, 'warnings' => $warnings );
    }

    /**
     * Record the copy in the state; returns its "$N".
     *
     * @param int|string $id source service
     * @param State      $state
     * @return string
     */
    protected function record( $id, State $state )
    {
        $shape = array();
        foreach ( array_keys( ServiceData::fields() ) as $field ) {
            $shape[ $field ] = self::current( $state, $id, $field );
        }
        $shape['type'] = self::current( $state, $id, 'type' );
        $shape['title'] = sprintf( __( 'Copy of %s', 'bookly-responsive-appointment-booking-tool' ), self::current( $state, $id, 'title' ) );
        $shape['visibility'] = Lib\Entities\Service::VISIBILITY_PRIVATE;
        $lists = array();
        foreach ( array( 'staff_ids', 'steps', 'tags', 'schedule', 'special_days', 'preference_order' ) as $key ) {
            $lists[ $key ] = self::current( $state, $id, $key );
        }
        $ref = self::newRef( $state );
        $state->created( 'service' );
        self::setNew( $state, $ref, $shape, $lists );

        return $ref;
    }

    /**
     * @inheritDoc
     */
    public function apply( array $args )
    {
        $service = Lib\Entities\Service::find( $args['service_id'], false );
        $new_service = new Lib\Entities\Service( $service->getFields() );
        $new_service
            ->setId( null )
            ->setTitle( sprintf( __( 'Copy of %s', 'bookly-responsive-appointment-booking-tool' ), $new_service->getTitle() ) )
            ->setVisibility( Lib\Entities\Service::VISIBILITY_PRIVATE )
            ->save();

        foreach ( Lib\Entities\StaffService::query()->where( 'service_id', $service->getId() )->fetchArray() as $staff_service ) {
            $new_staff_service = new Lib\Entities\StaffService( $staff_service );
            $new_staff_service->setId( null )->setServiceId( $new_service->getId() )->save();
        }

        foreach ( Lib\Entities\SubService::query()->where( 'service_id', $service->getId() )->fetchArray() as $sub_service ) {
            $new_sub_service = new Lib\Entities\SubService( $sub_service );
            $new_sub_service->setId( null )->setServiceId( $new_service->getId() )->save();
        }

        ServicesProxy\Shared::duplicateService( $service->getId(), $new_service->getId() );

        return array( 'created_id' => (int) $new_service->getId() );
    }

    /**
     * @inheritDoc
     */
    public function undo( array $snapshot )
    {
        CreateService::deleteCreated( $snapshot['created_id'] );
    }
}
