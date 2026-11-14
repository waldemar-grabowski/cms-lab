<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Backend\Components\Dialogs\Service\Edit\Forms;
use Bookly\Backend\Modules\Services\Proxy as ServicesProxy;
use Bookly\Lib;
use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\Format;
use Bookly\Lib\AiAdmin\Rules;
use Bookly\Lib\AiAdmin\Services\ServiceAction;
use Bookly\Lib\AiAdmin\Services\ServiceData;
use Bookly\Lib\AiAdmin\State;

/**
 * create_service — a service with a title and a type and the defaults of the New service dialog
 * (assets/ai-setup/actions/create_service.yaml).
 */
class CreateService extends ServiceAction implements ActionInterface
{
    protected function name()
    {
        return 'create_service';
    }

    /**
     * @inheritDoc
     */
    public function validate( array $args, State $state )
    {
        if ( $error = $this->forbidden() ) {
            return array( $error );
        }
        $errors = array();
        $title = isset( $args['title'] ) && is_string( $args['title'] ) ? $args['title'] : '';
        $type = isset( $args['type'] ) ? (string) $args['type'] : '';
        if ( trim( $title ) === '' ) {
            $errors[] = 'title_empty: the title is empty';
        } elseif ( mb_strlen( $title ) > 255 ) {
            $errors[] = 'title_too_long: at most 255 characters';
        }
        if ( ! in_array( $type, array( 'simple', 'compound', 'collaborative', 'package' ), true ) ) {
            $errors[] = 'type_unavailable: type is one of simple, compound, collaborative, package';
        } elseif ( ( $addon = ServiceData::typeAddon( $type ) ) && ! Access::addonActive( $addon ) ) {
            $errors[] = 'type_unavailable: the ' . $type . ' type needs the ' . $addon . ' add-on';
        }
        if ( ServiceData::freeLimitReached( (int) $state->get( 'count:services_created' ) ) ) {
            $errors[] = 'free_limit: without Pro a site has at most 5 services';
        }
        if ( ! $errors ) {
            $this->record( $title, $type, $state );
        }

        return $errors;
    }

    /**
     * @inheritDoc
     */
    public function preview( array $args, State $state )
    {
        $entries = Rules::entries();
        $ref = $this->record( $args['title'], $args['type'], $state );
        $object = self::cardObject( $state, $ref, true );
        $rows = array(
            array( 'label' => Format::rowLabel( $object, Format::label( $entries['field:service.title'], 'title' ), array( 'name' => true ) ), 'before' => '—', 'after' => $args['title'] ),
            array( 'label' => Format::rowLabel( $object, __( 'Type', 'bookly-responsive-appointment-booking-tool' ) ), 'before' => '—', 'after' => Format::value( $entries['field:service.type'], $args['type'] ) ),
            array( 'label' => Format::rowLabel( $object, Format::label( $entries['field:service.price'], 'price' ) ), 'before' => '—', 'after' => Format::price( 0 ) ),
        );
        if ( $args['type'] === Lib\Entities\Service::TYPE_SIMPLE ) {
            $rows[] = array( 'label' => Format::rowLabel( $object, Format::label( $entries['field:service.duration'], 'duration' ) ), 'before' => '—', 'after' => Format::value( $entries['field:service.duration'], Lib\Config::getTimeSlotLength() ) );
        }
        if ( Lib\Config::proActive() ) {
            $rows[] = array( 'label' => Format::rowLabel( $object, Format::label( $entries['field:service.visibility'], 'visibility' ) ), 'before' => '—', 'after' => Format::value( $entries['field:service.visibility'], 'public' ) );
        }
        if ( Lib\Config::serviceScheduleActive() && $args['type'] !== Lib\Entities\Service::TYPE_PACKAGE ) {
            foreach ( self::current( $state, $ref, 'schedule' ) as $day => $hours ) {
                $rows[] = array( 'label' => Format::rowLabel( $object, __( 'Schedule', 'bookly-responsive-appointment-booking-tool' ), array( 'day' => $day ) ), 'before' => '—', 'after' => ServiceData::dayText( $hours ) );
            }
        }

        return array( 'rows' => $rows, 'warnings' => $this->actionWarnings( $state ) );
    }

    /**
     * Record the new service in the state; returns its "$N".
     *
     * @param string $title
     * @param string $type
     * @param State  $state
     * @return string
     */
    protected function record( $title, $type, State $state )
    {
        $ref = self::newRef( $state );
        $state->created( 'service' );
        $schedule = array();
        if ( $type !== Lib\Entities\Service::TYPE_PACKAGE ) {
            $rows = ServiceData::scheduleRows( null );
            foreach ( ServiceData::weekOrder() as $day_index ) {
                $day = $rows[ $day_index ];
                $schedule[ ServiceData::dayName( $day_index ) ] = $day['start_time'] === null
                    ? 'off'
                    : array( 'start' => ServiceData::hhmm( $day['start_time'] ), 'end' => ServiceData::hhmm( $day['end_time'] ), 'breaks' => array() );
            }
        }
        self::setNew( $state, $ref, ServiceData::newShape( $title, $type ), array(
            'staff_ids' => array(),
            'steps' => array(),
            'tags' => array(),
            'schedule' => $schedule,
            'special_days' => array(),
            'preference_order' => array(),
        ) );

        return $ref;
    }

    /**
     * @inheritDoc
     */
    public function apply( array $args )
    {
        $form = new Forms\Service();
        $form->bind( array( 'title' => $args['title'], 'type' => $args['type'] ) );
        $form->getObject()->setDuration( Lib\Config::getTimeSlotLength() );
        $service = $form->save();

        ServicesProxy\Shared::serviceCreated( $service );

        return array( 'created_id' => (int) $service->getId() );
    }

    /**
     * @inheritDoc
     */
    public function undo( array $snapshot )
    {
        self::deleteCreated( $snapshot['created_id'] );
    }

    /**
     * Delete a service the proposal created, unless it already has appointments, sold packages or
     * is a step of another service.
     *
     * @param int $service_id
     * @return bool
     */
    public static function deleteCreated( $service_id )
    {
        $service = Lib\Entities\Service::find( $service_id, false );
        if ( ! $service || ! ServiceData::deletable( $service_id ) ) {
            return false;
        }
        ServicesProxy\Shared::serviceDeleted( $service );
        $service->delete();

        return true;
    }
}
