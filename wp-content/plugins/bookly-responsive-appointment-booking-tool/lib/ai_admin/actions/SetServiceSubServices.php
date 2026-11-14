<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\Format;
use Bookly\Lib\AiAdmin\Services\ServiceAction;
use Bookly\Lib\AiAdmin\Services\ServiceData;
use Bookly\Lib\AiAdmin\State;

/**
 * set_service_sub_services — steps of a compound / collaborative service or the service of a
 * package, the full ordered list (assets/ai-setup/actions/set_service_sub_services.yaml).
 */
class SetServiceSubServices extends ServiceAction implements ActionInterface
{
    /** Fields a package copies from its simple service. */
    const PACKAGE_COPY = 'category_id,duration,capacity_min,capacity_max,padding_left,padding_right';

    protected function name()
    {
        return 'set_service_sub_services';
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
        $type = self::current( $state, $id, 'type' );
        if ( ! in_array( $type, array( 'compound', 'collaborative', 'package' ), true ) ) {
            return array( 'type_without_steps: a ' . $type . ' service has no steps' );
        }
        $field = $type === Lib\Entities\Service::TYPE_PACKAGE ? 'package_service' : 'sub_services';
        $rule = Access::rule( 'field:service.' . $field );
        if ( ! $rule || empty( $rule['edit'] ) || ! Access::allowed( $rule['edit'] ) ) {
            return array( 'forbidden: ' . $field . ' cannot be changed by the assistant' );
        }
        $addon = ServiceData::typeAddon( $type );
        if ( ! Access::addonActive( $addon ) ) {
            return array( 'addon_inactive: steps of a ' . $type . ' service need the ' . $addon . ' add-on' );
        }
        $steps = isset( $args['steps'] ) && is_array( $args['steps'] ) ? array_values( $args['steps'] ) : array();
        if ( ! $steps ) {
            return array( 'empty_steps: give at least one step' );
        }
        if ( $type === Lib\Entities\Service::TYPE_PACKAGE && ( count( $steps ) !== 1 || ! isset( $steps[0]['service_id'] ) ) ) {
            return array( 'package_one_service: a package has exactly one step {service_id}' );
        }
        $stored_spare = array();
        foreach ( self::current( $state, $id, 'steps' ) as $step ) {
            if ( isset( $step['spare_time'] ) ) {
                $stored_spare[] = (int) $step['spare_time'];
            }
        }
        $errors = array();
        foreach ( $steps as $i => $step ) {
            if ( is_array( $step ) && array_key_exists( 'service_id', $step ) && count( $step ) === 1 ) {
                $sub_id = self::id( $step['service_id'] );
                if ( $sub_id === $id || ! self::exists( $sub_id, $state ) || self::current( $state, $sub_id, 'type' ) !== Lib\Entities\Service::TYPE_SIMPLE ) {
                    $errors[] = sprintf( 'step_not_simple: step %d — %s is not a simple service other than this one', $i + 1, wp_json_encode( $step['service_id'] ) );
                }
            } elseif ( is_array( $step ) && array_key_exists( 'spare_time', $step ) && count( $step ) === 1 ) {
                if ( $type !== Lib\Entities\Service::TYPE_COMPOUND ) {
                    $errors[] = sprintf( 'spare_time_not_compound: step %d — spare time exists only in a compound service', $i + 1 );
                } elseif ( ! ServiceData::spareTimeAllowed( $step['spare_time'], $stored_spare ) ) {
                    $errors[] = sprintf( 'spare_time_not_in_list: step %d — %s, see get_service.allowed.spare_time', $i + 1, wp_json_encode( $step['spare_time'] ) );
                }
            } else {
                $errors[] = sprintf( 'step_not_simple: step %d is {service_id} or {spare_time}', $i + 1 );
            }
        }
        if ( ! $errors ) {
            $this->record( $id, $type, $this->normalize( $steps ), $state );
        }

        return $errors;
    }

    /**
     * @param array $steps
     * @return array
     */
    protected function normalize( array $steps )
    {
        $result = array();
        foreach ( array_values( $steps ) as $step ) {
            $result[] = isset( $step['service_id'] )
                ? array( 'service_id' => self::id( $step['service_id'] ) )
                : array( 'spare_time' => (int) $step['spare_time'] );
        }

        return $result;
    }

    /**
     * @param int|string $id
     * @param string     $type
     * @param array      $steps
     * @param State      $state
     */
    protected function record( $id, $type, array $steps, State $state )
    {
        self::setCurrent( $state, $id, 'steps', $steps );
        if ( $type === Lib\Entities\Service::TYPE_PACKAGE ) {
            $simple_id = $steps[0]['service_id'];
            foreach ( explode( ',', self::PACKAGE_COPY ) as $field ) {
                self::setCurrent( $state, $id, $field, self::current( $state, $simple_id, $field ) );
            }
            $providers = self::current( $state, $id, 'staff_ids' );
            $simple_staff = self::current( $state, $simple_id, 'staff_ids' );
            if ( array_diff( $providers, $simple_staff ) ) {
                $all = array_unique( array_merge( $simple_staff, $providers ) );
                $ordered = array();
                foreach ( array_keys( ServiceData::staff() ) as $staff_id ) {
                    if ( in_array( $staff_id, $all ) ) {
                        $ordered[] = $staff_id;
                    }
                }
                self::setCurrent( $state, $simple_id, 'staff_ids', $ordered );
            }
        }
    }

    /**
     * Steps as text, numbered: titles with durations, spare time as length. Not joined with "→":
     * on the card the arrow means "before → after".
     *
     * @param State $state
     * @param array $steps
     * @return string
     */
    protected function stepsText( State $state, array $steps )
    {
        $texts = array();
        foreach ( $steps as $i => $step ) {
            $texts[] = ( $i + 1 ) . '. ' . ( isset( $step['service_id'] )
                ? self::serviceTitle( $state, $step['service_id'] ) . ' (' . Lib\Utils\DateTime::secondsToInterval( (int) self::current( $state, self::id( $step['service_id'] ), 'duration' ) ) . ')'
                : __( 'Spare time', 'bookly-responsive-appointment-booking-tool' ) . ' ' . Lib\Utils\DateTime::secondsToInterval( $step['spare_time'] ) );
        }

        return $texts ? implode( ', ', $texts ) : '—';
    }

    /**
     * Total length of the steps: the sum for compound, the longest step for collaborative.
     *
     * @param State  $state
     * @param string $type
     * @param array  $steps
     * @return int
     */
    protected function total( State $state, $type, array $steps )
    {
        $lengths = array();
        foreach ( $steps as $step ) {
            $lengths[] = isset( $step['service_id'] ) ? (int) self::current( $state, self::id( $step['service_id'] ), 'duration' ) : (int) $step['spare_time'];
        }
        if ( ! $lengths ) {
            return 0;
        }

        return $type === Lib\Entities\Service::TYPE_COMPOUND ? array_sum( $lengths ) : max( $lengths );
    }

    /**
     * @inheritDoc
     */
    public function preview( array $args, State $state )
    {
        $id = self::id( $args['service_id'] );
        $type = self::current( $state, $id, 'type' );
        $steps = $this->normalize( $args['steps'] );
        $before = self::current( $state, $id, 'steps' );
        $field = $type === Lib\Entities\Service::TYPE_PACKAGE ? 'package_service' : 'sub_services';
        $rule = Access::rule( 'field:service.' . $field );
        $rows = array( array(
            'label' => self::rowLabel( $state, $id, Format::label( $rule, $field ) ),
            'before' => $this->stepsText( $state, $before ),
            'after' => $this->stepsText( $state, $steps ),
            'link' => Format::link( $rule ),
        ) );
        if ( $type === Lib\Entities\Service::TYPE_PACKAGE ) {
            $simple_id = $steps[0]['service_id'];
            foreach ( explode( ',', self::PACKAGE_COPY ) as $copy ) {
                $old = self::current( $state, $id, $copy );
                $new = self::current( $state, $simple_id, $copy );
                if ( $old !== $new ) {
                    $copy_rule = Access::rule( 'field:service.' . $copy );
                    $rows[] = array(
                        'label' => self::rowLabel( $state, $id, Format::label( $copy_rule, $copy ) ),
                        'before' => $this->copyText( $copy, $old ),
                        'after' => $this->copyText( $copy, $new ),
                    );
                }
            }
            $added = array_diff( self::current( $state, $id, 'staff_ids' ), self::current( $state, $simple_id, 'staff_ids' ) );
            if ( $added ) {
                $rows[] = array(
                    'label' => self::rowLabel( $state, $simple_id, __( 'Added', 'bookly-responsive-appointment-booking-tool' ) ),
                    'before' => '—',
                    'after' => self::staffNames( $added ) . ' (' . Format::price( self::current( $state, $simple_id, 'price' ) ) . ')',
                );
            }
        } else {
            $total_before = $this->total( $state, $type, $before );
            $rows[] = array(
                'label' => self::rowLabel( $state, $id, __( 'Duration', 'bookly-responsive-appointment-booking-tool' ) ),
                'before' => $total_before ? Lib\Utils\DateTime::secondsToInterval( $total_before ) : '—',
                'after' => Lib\Utils\DateTime::secondsToInterval( $this->total( $state, $type, $steps ) ),
            );
        }
        $this->record( $id, $type, $steps, $state );

        return array( 'rows' => $rows, 'warnings' => array() );
    }

    /**
     * @param string $field
     * @param mixed  $value
     * @return string
     */
    protected function copyText( $field, $value )
    {
        if ( $field === 'category_id' ) {
            $category = $value === null ? null : Lib\Entities\Category::find( $value );

            return $category ? $category->getName() : __( 'Uncategorized', 'bookly-responsive-appointment-booking-tool' );
        }
        if ( in_array( $field, array( 'duration', 'padding_left', 'padding_right' ), true ) ) {
            return (int) $value ? Lib\Utils\DateTime::secondsToInterval( (int) $value ) : __( 'OFF', 'bookly-responsive-appointment-booking-tool' );
        }

        return (string) $value;
    }

    /**
     * @inheritDoc
     */
    public function apply( array $args )
    {
        $service = Lib\Entities\Service::find( $args['service_id'], false );
        $service_id = (int) $service->getId();
        $steps = $this->normalize( $args['steps'] );
        $snapshot = array(
            'service_id' => $service_id,
            'rows' => Lib\Entities\SubService::query()->where( 'service_id', $service_id )->sortBy( 'position' )->fetchArray(),
            'fields' => array(),
            'created' => array(),
        );

        if ( $service->getType() === Lib\Entities\Service::TYPE_PACKAGE ) {
            $simple = Lib\Entities\Service::find( $steps[0]['service_id'], false );
            if ( count( $snapshot['rows'] ) !== 1 ) {
                Lib\Entities\SubService::query()->delete()->where( 'service_id', $service_id )->execute();
                $sub_service = new Lib\Entities\SubService();
                $sub_service
                    ->setType( Lib\Entities\SubService::TYPE_SERVICE )
                    ->setServiceId( $service_id )
                    ->setSubServiceId( $simple->getId() )
                    ->setPosition( 0 )
                    ->save();
            } elseif ( $snapshot['rows'][0]['sub_service_id'] != $simple->getId() ) {
                $sub_service = new Lib\Entities\SubService( $snapshot['rows'][0] );
                $sub_service->setSubServiceId( $simple->getId() )->save();
            }
            $copy = array();
            foreach ( explode( ',', self::PACKAGE_COPY ) as $field ) {
                $getter = 'get' . str_replace( ' ', '', ucwords( str_replace( '_', ' ', $field ) ) );
                $copy[ $field ] = $simple->$getter();
            }
            $snapshot['fields'] = array_intersect_key( $service->getFields(), $copy );
            $service->setFields( $copy )->save();
            // The package's Providers start performing its service too.
            $simple_staff = array_map( 'intval', Lib\Entities\StaffService::query()->where( 'service_id', $simple->getId() )->fetchCol( 'staff_id' ) );
            foreach ( array_diff( ServiceData::staffIds( $service_id ), $simple_staff ) as $staff_id ) {
                $staff_service = new Lib\Entities\StaffService();
                $staff_service
                    ->setStaffId( $staff_id )
                    ->setServiceId( $simple->getId() )
                    ->setPrice( $simple->getPrice() )
                    ->setCapacityMin( $simple->getCapacityMin() )
                    ->setCapacityMax( $simple->getCapacityMax() )
                    ->save();
                $snapshot['created'][] = (int) $staff_service->getId();
            }
        } else {
            /** @var Lib\Entities\SubService[] $existing */
            $existing = Lib\Entities\SubService::query()->where( 'service_id', $service_id )->sortBy( 'position' )->find();
            $ids = array();
            foreach ( $steps as $position => $step ) {
                $sub_service = array_shift( $existing );
                if ( ! $sub_service ) {
                    $sub_service = new Lib\Entities\SubService();
                    $sub_service->setServiceId( $service_id );
                }
                if ( isset( $step['spare_time'] ) ) {
                    $sub_service
                        ->setType( Lib\Entities\SubService::TYPE_SPARE_TIME )
                        ->setSubServiceId( null )
                        ->setDuration( $step['spare_time'] );
                } else {
                    $sub_service
                        ->setType( Lib\Entities\SubService::TYPE_SERVICE )
                        ->setSubServiceId( $step['service_id'] )
                        ->setDuration( null );
                }
                $sub_service->setPosition( $position )->save();
                $ids[] = $sub_service->getId();
            }
            Lib\Entities\SubService::query()->delete()->where( 'service_id', $service_id )->whereNotIn( 'id', $ids )->execute();
        }

        return $snapshot;
    }

    /**
     * @inheritDoc
     */
    public function undo( array $snapshot )
    {
        Lib\Entities\SubService::query()->delete()->where( 'service_id', $snapshot['service_id'] )->execute();
        foreach ( $snapshot['rows'] as $row ) {
            self::insertRow( '\Bookly\Lib\Entities\SubService', $row );
        }
        if ( $snapshot['fields'] ) {
            $service = Lib\Entities\Service::find( $snapshot['service_id'], false );
            if ( $service ) {
                $service->setFields( $snapshot['fields'] )->save();
            }
        }
        if ( $snapshot['created'] ) {
            Lib\Entities\StaffService::query()->delete()->whereIn( 'id', $snapshot['created'] )->execute();
        }
    }
}
