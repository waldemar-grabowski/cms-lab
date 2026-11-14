<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\Format;
use Bookly\Lib\AiAdmin\Services\ServiceAction;
use Bookly\Lib\AiAdmin\Services\ServiceData;
use Bookly\Lib\AiAdmin\State;

/**
 * set_service_staff — add Providers to a simple or package service or remove them; the others stay
 * (assets/ai-setup/actions/set_service_staff.yaml). Removed staff members lose their rows of the
 * service (all locations), added ones get the service price and capacity; archived staff members
 * are not touched.
 */
class SetServiceStaff extends ServiceAction implements ActionInterface
{
    protected function name()
    {
        return 'set_service_staff';
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
        $rule = Access::rule( 'field:service.staff_ids' );
        if ( ! $rule || empty( $rule['edit'] ) || ! Access::allowed( $rule['edit'] ) ) {
            return array( 'forbidden: Providers cannot be changed by the assistant' );
        }
        $type = self::current( $state, $id, 'type' );
        if ( ! in_array( $type, array( 'simple', 'package' ), true ) ) {
            return array( 'type_without_providers: a ' . $type . ' service has no Providers' );
        }
        foreach ( array_keys( $args ) as $key ) {
            if ( ! in_array( $key, array( 'service_id', 'add', 'remove' ), true ) ) {
                return array( 'arguments: unknown argument ' . $key . ' — the action takes service_id, add, remove' );
            }
        }
        $add = isset( $args['add'] ) ? $args['add'] : array();
        $remove = isset( $args['remove'] ) ? $args['remove'] : array();
        if ( ! is_array( $add ) || ! is_array( $remove ) ) {
            return array( 'arguments: add and remove are lists of staff ids' );
        }
        if ( ! $add && ! $remove ) {
            return array( 'nothing_to_change: add and remove are both empty' );
        }
        $errors = array();
        $staff = ServiceData::staff();
        $current = self::current( $state, $id, 'staff_ids' );
        foreach ( $add as $staff_id ) {
            if ( $state->isNew( $staff_id ) === 'staff' ) {
                $errors[] = 'staff_not_found: ' . wp_json_encode( $staff_id ) . ' is created in this proposal and cannot be added here — give it the service with set_staff_services {staff_id: ' . wp_json_encode( $staff_id ) . ', add: [{service_id: …}]}';
            } elseif ( ! is_numeric( $staff_id ) || ! isset( $staff[ (int) $staff_id ] ) ) {
                $errors[] = 'staff_not_found: ' . wp_json_encode( $staff_id );
            } elseif ( $staff[ (int) $staff_id ]['visibility'] === 'archive' ) {
                $errors[] = 'staff_archived: ' . $staff[ (int) $staff_id ]['full_name'] . ' is archived';
            } elseif ( in_array( (int) $staff_id, $current, true ) ) {
                $errors[] = 'already_provider: ' . $staff[ (int) $staff_id ]['full_name'] . ' already performs the service';
            }
        }
        foreach ( $remove as $staff_id ) {
            if ( ! is_numeric( $staff_id ) || ! in_array( (int) $staff_id, $current, true ) ) {
                $errors[] = 'not_provider: ' . wp_json_encode( $staff_id ) . ' is not a Provider of the service (get_service.staff_ids)';
            } elseif ( in_array( (int) $staff_id, array_map( 'intval', $add ), true ) ) {
                $errors[] = 'not_provider: ' . $staff_id . ' is both in add and in remove';
            }
        }
        if ( count( array_unique( array_map( 'intval', $add ) ) ) !== count( $add ) || count( array_unique( array_map( 'intval', $remove ) ) ) !== count( $remove ) ) {
            $errors[] = 'arguments: a staff id appears twice';
        }
        if ( $errors ) {
            return $errors;
        }
        $new = $this->changed( $current, $args );
        if ( $type === Lib\Entities\Service::TYPE_PACKAGE ) {
            if ( ! Lib\Config::packagesActive() && array_diff( $new, $current ) ) {
                return array( 'package_addon_inactive: without the packages add-on a package gets no new Providers, only removals' );
            }
            if ( Lib\Config::packagesActive() && ! self::current( $state, $id, 'steps' ) ) {
                return array( 'package_without_service: choose the package\'s service first (set_service_sub_services)' );
            }
        }
        $this->record( $id, $type, $current, $this->ordered( $new ), $state );

        return array();
    }

    /**
     * Providers after the change: the current ones plus add minus remove, in the order of the
     * staff list.
     *
     * @param int[] $current
     * @param array $args
     * @return int[]
     */
    protected function changed( array $current, array $args )
    {
        $add = isset( $args['add'] ) ? array_map( 'intval', $args['add'] ) : array();
        $remove = isset( $args['remove'] ) ? array_map( 'intval', $args['remove'] ) : array();

        return $this->ordered( array_values( array_diff( array_unique( array_merge( $current, $add ) ), $remove ) ) );
    }

    /**
     * Staff ids in the order of the staff list.
     *
     * @param int[] $ids
     * @return int[]
     */
    protected function ordered( array $ids )
    {
        $ordered = array();
        foreach ( array_keys( ServiceData::staff() ) as $staff_id ) {
            if ( in_array( $staff_id, $ids, true ) ) {
                $ordered[] = $staff_id;
            }
        }

        return $ordered;
    }

    /**
     * @param int|string $id
     * @param string     $type
     * @param int[]      $current
     * @param int[]      $new
     * @param State      $state
     */
    protected function record( $id, $type, array $current, array $new, State $state )
    {
        self::setCurrent( $state, $id, 'staff_ids', $new );
        // A package's new Providers also start performing its simple service.
        if ( $type === Lib\Entities\Service::TYPE_PACKAGE ) {
            $steps = self::current( $state, $id, 'steps' );
            if ( $steps && isset( $steps[0]['service_id'] ) ) {
                $simple_id = self::id( $steps[0]['service_id'] );
                $simple_staff = self::current( $state, $simple_id, 'staff_ids' );
                $added = array_diff( $new, $current, $simple_staff );
                if ( $added ) {
                    self::setCurrent( $state, $simple_id, 'staff_ids', $this->ordered( array_merge( $simple_staff, $added ) ) );
                }
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function preview( array $args, State $state )
    {
        $id = self::id( $args['service_id'] );
        $type = self::current( $state, $id, 'type' );
        $current = self::current( $state, $id, 'staff_ids' );
        $new = $this->changed( $current, $args );
        $added = array_values( array_diff( $new, $current ) );
        $removed = array_values( array_diff( $current, $new ) );
        $rule = Access::rule( 'field:service.staff_ids' );
        $rows = array( array(
            'label' => self::rowLabel( $state, $id, Format::label( $rule, 'staff_ids' ) ),
            'before' => self::staffNames( $current ),
            'after' => self::staffNames( $new ),
            'link' => Format::link( $rule ),
        ) );
        $warnings = $this->actionWarnings( $state );
        if ( $removed ) {
            $warnings[] = __( 'A removed staff member\'s own price, capacity and deposit for this service are deleted; adding them back uses the service values, the deposit 100%.', 'bookly-responsive-appointment-booking-tool' );
        }
        $price = self::current( $state, $id, 'price' );
        if ( $added ) {
            $capacity = $type === Lib\Entities\Service::TYPE_SIMPLE
                ? ', ' . self::current( $state, $id, 'capacity_min' ) . '–' . self::current( $state, $id, 'capacity_max' )
                : '';
            $rows[] = array(
                'label' => self::rowLabel( $state, $id, __( 'Added', 'bookly-responsive-appointment-booking-tool' ) ),
                'before' => '—',
                'after' => self::staffNames( $added ) . ' (' . Format::price( $price ) . $capacity . ')',
            );
            if ( $type === Lib\Entities\Service::TYPE_PACKAGE ) {
                $steps = self::current( $state, $id, 'steps' );
                if ( $steps && isset( $steps[0]['service_id'] ) ) {
                    $simple_id = self::id( $steps[0]['service_id'] );
                    $also = array_diff( $added, self::current( $state, $simple_id, 'staff_ids' ) );
                    if ( $also ) {
                        $rows[] = array(
                            'label' => self::rowLabel( $state, $simple_id, __( 'Added', 'bookly-responsive-appointment-booking-tool' ) ),
                            'before' => '—',
                            'after' => self::staffNames( $also ) . ' (' . Format::price( self::current( $state, $simple_id, 'price' ) ) . ')',
                        );
                    }
                }
            }
        }
        if ( $removed && is_int( $id ) ) {
            $own = array();
            foreach ( Lib\Entities\StaffService::query()->where( 'service_id', $id )->whereIn( 'staff_id', $removed )->fetchArray() as $row ) {
                $own[] = self::staffNames( array( (int) $row['staff_id'] ) ) . ' ' . Format::price( $row['price'] )
                    . ( $row['location_id'] ? ' (' . __( 'Location', 'bookly-responsive-appointment-booking-tool' ) . ' #' . $row['location_id'] . ')' : '' );
            }
            $rows[] = array(
                'label' => self::rowLabel( $state, $id, __( 'Removed', 'bookly-responsive-appointment-booking-tool' ) ),
                'before' => implode( ', ', $own ),
                'after' => '—',
            );
            if ( $type === Lib\Entities\Service::TYPE_SIMPLE ) {
                $packages = Lib\Entities\StaffService::query( 'ss' )
                    ->select( 'DISTINCT s.title' )
                    ->innerJoin( 'Service', 's', 's.id = ss.service_id' )
                    ->innerJoin( 'SubService', 'sub', 'sub.service_id = s.id' )
                    ->where( 's.type', Lib\Entities\Service::TYPE_PACKAGE )
                    ->where( 'sub.sub_service_id', $id )
                    ->whereIn( 'ss.staff_id', $removed )
                    ->fetchCol( 'DISTINCT s.title' );
                if ( $packages ) {
                    $warnings[] = sprintf( __( 'They are also removed from the packages of this service: %s', 'bookly-responsive-appointment-booking-tool' ), implode( ', ', $packages ) );
                }
            }
            $appointments = self::futureAppointments( $id, $removed );
            if ( $appointments ) {
                $warnings[] = sprintf(
                    __( '%d future appointments of the removed staff members stay in the calendar: %s', 'bookly-responsive-appointment-booking-tool' ),
                    count( $appointments ),
                    self::appointmentsText( $appointments )
                );
            }
        }
        if ( is_int( $id ) && ServiceData::staffIds( $id, true ) ) {
            $warnings[] = sprintf(
                __( 'Archived staff members keep their link to the service: %s', 'bookly-responsive-appointment-booking-tool' ),
                self::staffNames( ServiceData::staffIds( $id, true ) )
            );
        }
        $this->record( $id, $type, $current, $new, $state );

        return array( 'rows' => $rows, 'warnings' => $warnings );
    }

    /**
     * @inheritDoc
     */
    public function apply( array $args )
    {
        $service = Lib\Entities\Service::find( $args['service_id'], false );
        $service_id = (int) $service->getId();
        $current = ServiceData::staffIds( $service_id );
        $new = $this->changed( $current, $args );
        $removed = array_values( array_diff( $current, $new ) );
        $added = array_values( array_diff( $new, $current ) );
        $snapshot = array( 'deleted' => array(), 'created' => array() );

        // Rows of removed staff members: the service and, for a simple service, its packages.
        $services = array( $service_id );
        if ( $service->getType() === Lib\Entities\Service::TYPE_SIMPLE ) {
            $services = array_merge( $services, array_map( 'intval', Lib\Entities\SubService::query( 'ss' )
                ->innerJoin( 'Service', 's', 's.id = ss.service_id' )
                ->where( 's.type', Lib\Entities\Service::TYPE_PACKAGE )
                ->where( 'ss.sub_service_id', $service_id )
                ->fetchCol( 'ss.service_id' ) ) );
        }
        if ( $removed ) {
            $rows = Lib\Entities\StaffService::query()->whereIn( 'service_id', $services )->whereIn( 'staff_id', $removed )->fetchArray();
            foreach ( $rows as $row ) {
                $snapshot['deleted'][] = $row;
            }
            Lib\Entities\StaffService::query()->delete()->whereIn( 'service_id', $services )->whereIn( 'staff_id', $removed )->execute();
        }

        if ( $service->getType() === Lib\Entities\Service::TYPE_SIMPLE ) {
            foreach ( $added as $staff_id ) {
                $snapshot['created'][] = self::createRow( $staff_id, $service_id, $service->getPrice(), $service->getCapacityMin(), $service->getCapacityMax() );
            }
        } elseif ( $added && Lib\Config::packagesActive() ) {
            $sub_service = current( $service->getSubServices() );
            if ( $sub_service ) {
                $simple_staff = array_map( 'intval', Lib\Entities\StaffService::query()
                    ->where( 'service_id', $sub_service->getId() )
                    ->fetchCol( 'staff_id' ) );
                foreach ( $added as $staff_id ) {
                    $staff_sub_service = new Lib\Entities\StaffService();
                    $staff_sub_service->loadBy( array( 'service_id' => $sub_service->getId(), 'staff_id' => $staff_id ) );
                    $snapshot['created'][] = self::createRow(
                        $staff_id,
                        $service_id,
                        $service->getPrice(),
                        $staff_sub_service->isLoaded() ? $staff_sub_service->getCapacityMin() : $sub_service->getCapacityMin(),
                        $staff_sub_service->isLoaded() ? $staff_sub_service->getCapacityMax() : $sub_service->getCapacityMax()
                    );
                    if ( ! in_array( $staff_id, $simple_staff, true ) ) {
                        $snapshot['created'][] = self::createRow( $staff_id, $sub_service->getId(), $sub_service->getPrice(), $sub_service->getCapacityMin(), $sub_service->getCapacityMax() );
                    }
                }
            }
        }

        return $snapshot;
    }

    /**
     * New staff row of a service (location_id NULL); returns its id.
     *
     * @param int   $staff_id
     * @param int   $service_id
     * @param float $price
     * @param int   $capacity_min
     * @param int   $capacity_max
     * @return int
     */
    protected static function createRow( $staff_id, $service_id, $price, $capacity_min, $capacity_max )
    {
        $staff_service = new Lib\Entities\StaffService();
        $staff_service
            ->setStaffId( $staff_id )
            ->setServiceId( $service_id )
            ->setPrice( $price )
            ->setCapacityMin( $capacity_min )
            ->setCapacityMax( $capacity_max )
            ->save();

        return (int) $staff_service->getId();
    }

    /**
     * @inheritDoc
     */
    public function undo( array $snapshot )
    {
        if ( $snapshot['created'] ) {
            Lib\Entities\StaffService::query()->delete()->whereIn( 'id', $snapshot['created'] )->execute();
        }
        foreach ( $snapshot['deleted'] as $row ) {
            self::insertRow( '\Bookly\Lib\Entities\StaffService', $row );
        }
    }
}
