<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\AllowedValues;
use Bookly\Lib\AiAdmin\Constraints;
use Bookly\Lib\AiAdmin\Format;
use Bookly\Lib\AiAdmin\Knowledge;
use Bookly\Lib\AiAdmin\Rules;
use Bookly\Lib\AiAdmin\StaffData;
use Bookly\Lib\AiAdmin\State;

/**
 * set_staff_services — changes to the services one staff member performs, Services tab of Edit
 * staff member (assets/ai-setup/actions/set_staff_services.yaml): "add" assigns services the staff
 * member does not perform yet, "set" changes only the given fields of rows they already have,
 * "remove" takes services off; rows not mentioned stay as they are.
 */
class SetStaffServices implements ActionInterface
{
    /**
     * @inheritDoc
     */
    public function validate( array $args, State $state )
    {
        $staff_id = isset( $args['staff_id'] ) ? $args['staff_id'] : null;
        if ( $error = StaffData::forbidden( 'field:staff_service.service_id' ) ) {
            return array( $error );
        }
        if ( ! StaffData::exists( $staff_id, $state ) ) {
            return array( 'staff_not_found: ' . wp_json_encode( $staff_id ) );
        }
        foreach ( array_keys( $args ) as $key ) {
            if ( ! in_array( $key, array( 'staff_id', 'add', 'set', 'remove' ), true ) ) {
                return array( 'arguments: unknown argument ' . $key . ' — the action takes staff_id, add, set, remove' );
            }
        }
        $add    = isset( $args['add'] ) ? $args['add'] : array();
        $set    = isset( $args['set'] ) ? $args['set'] : array();
        $remove = isset( $args['remove'] ) ? $args['remove'] : array();
        if ( ! is_array( $add ) || ! is_array( $set ) || ! is_array( $remove ) ) {
            return array( 'arguments: add and set are lists of {service_id, …}, remove a list of service ids' );
        }
        if ( ! $add && ! $set && ! $remove ) {
            return array( 'nothing_to_change: add, set and remove are all empty' );
        }
        $errors  = array();
        $entries = Rules::entries();
        $allowed = AllowedValues::of( $entries['field:staff_service.service_id'] );
        $catalog = self::catalog();
        $group   = Lib\Config::groupBookingActive();
        $own     = StaffData::ownLocations( $staff_id, 'services' );
        $seen    = array();
        $items   = array();
        foreach ( $add as $item ) {
            $items[] = array( 'add', $item );
        }
        foreach ( $set as $item ) {
            $items[] = array( 'set', $item );
        }
        foreach ( $items as $pair ) {
            list ( $list, $item ) = $pair;
            if ( ! is_array( $item ) ) {
                $errors[] = 'arguments: an item of ' . $list . ' is {service_id, price?, capacity_min?, capacity_max?, location_id?}';
                continue;
            }
            foreach ( array_keys( $item ) as $key ) {
                if ( ! in_array( $key, array( 'service_id', 'price', 'capacity_min', 'capacity_max', 'location_id' ), true ) ) {
                    $errors[] = 'arguments: unknown field ' . $key . ' in ' . $list;
                }
            }
            $service_id  = isset( $item['service_id'] ) ? $item['service_id'] : null;
            $location_id = isset( $item['location_id'] ) ? $item['location_id'] : null;
            $new         = $state->isNew( $service_id ) === 'service';
            if ( ! $new && ( ! is_numeric( $service_id ) || ! AllowedValues::contains( (int) $service_id, $allowed ) ) ) {
                $errors[] = 'service_not_listed: ' . wp_json_encode( $service_id ) . ' — ids from get_staff.allowed.services';
                continue;
            }
            if ( $location_id !== null && ( ! is_numeric( $location_id ) || ! in_array( (int) $location_id, $own, true ) ) ) {
                $errors[] = 'location_not_custom: ' . wp_json_encode( $location_id ) . ' — only a location with its own services (get_staff.location_services); omit location_id for the general settings';
                continue;
            }
            $key = $service_id . '@' . ( $location_id ? (int) $location_id : '' );
            if ( isset( $seen[ $key ] ) ) {
                $errors[] = 'duplicate_service: ' . $service_id . ' appears twice in add and set';
                continue;
            }
            $seen[ $key ] = true;
            // Assigning a service is never a side effect of changing a price: add names it.
            $performed = self::byId( StaffData::services( $staff_id, $state, $location_id ? (int) $location_id : null ) );
            $has       = ! $new && isset( $performed[ (int) $service_id ] );
            if ( $list === 'set' && ! $has ) {
                $errors[] = 'service_not_performed: ' . wp_json_encode( $service_id ) . ' — set changes only services the staff member performs (get_staff.services); to make them perform it, put it in add';
                continue;
            }
            if ( $list === 'add' && $has ) {
                $errors[] = 'already_performed: ' . $service_id . ' — the staff member performs it already; change its price or capacity in set';
                continue;
            }
            if ( array_key_exists( 'price', $item ) && ( ! is_numeric( $item['price'] ) || $item['price'] < 0 || round( $item['price'], 2 ) != $item['price'] ) ) {
                $errors[] = 'price_invalid: service ' . $service_id . ' — a number >= 0 with at most 2 decimals';
            }
            $capacity = array_key_exists( 'capacity_min', $item ) || array_key_exists( 'capacity_max', $item );
            if ( $capacity && ! $group ) {
                $errors[] = 'capacity_without_group_booking: service ' . $service_id . ' — capacity needs the group-booking add-on';
            } elseif ( $capacity && ! $new && $catalog[ (int) $service_id ]['type'] === Lib\Entities\Service::TYPE_PACKAGE ) {
                $errors[] = 'package_capacity_readonly: service ' . $service_id . ' — a package takes the capacity of its simple service';
            }
        }
        $current = self::byId( StaffData::services( $staff_id, $state ) );
        foreach ( $remove as $service_id ) {
            $key = is_numeric( $service_id ) ? (int) $service_id : $service_id;
            if ( ! isset( $current[ $key ] ) ) {
                $errors[] = 'service_not_performed: ' . wp_json_encode( $service_id ) . ' — remove takes services of get_staff.services';
            } elseif ( isset( $seen[ $key . '@' ] ) ) {
                $errors[] = 'duplicate_service: ' . $service_id . ' is both in set and in remove';
            }
        }
        if ( preg_grep( '/^(arguments|service_not_listed|location_not_custom|duplicate_service|price_invalid|service_not_performed|already_performed):/', $errors ) ) {
            // The rows after the change cannot be built.
            return $errors;
        }
        $set = array_merge( $add, $set );
        $changed = false;
        foreach ( self::groups( $set ) as $location_id => $items ) {
            $before = StaffData::services( $staff_id, $state, $location_id ?: null );
            $after  = self::merge( $before, $items, $location_id ? array() : $remove, $state );
            $ids    = array();
            foreach ( $after as $row ) {
                $ids[] = (string) $row['service_id'];
            }
            foreach ( self::touched( $after, $items ) as $row ) {
                $service_id = $row['service_id'];
                if ( ! $state->isNew( $service_id ) && $catalog[ $service_id ]['type'] === Lib\Entities\Service::TYPE_PACKAGE ) {
                    $simple = $catalog[ $service_id ]['package_of'];
                    if ( $simple && ! in_array( (string) $simple, $ids, true ) ) {
                        $errors[] = 'package_without_simple: package ' . $service_id . ' needs its simple service ' . $simple . ' (put it in add)';
                    }
                }
                $values = array(
                    'field:staff_service.capacity_min' => $row['capacity_min'],
                    'field:staff_service.capacity_max' => $row['capacity_max'],
                );
                $reader = function ( $ref ) use ( $values ) {
                    return isset( $values[ $ref ] ) ? $values[ $ref ] : null;
                };
                $failed = array_merge(
                    Constraints::failed( $entries['field:staff_service.capacity_min'], $row['capacity_min'], null, $reader ),
                    Constraints::failed( $entries['field:staff_service.capacity_max'], $row['capacity_max'], null, $reader )
                );
                if ( $failed ) {
                    $errors[] = 'capacity_range: service ' . $service_id . ' — 1 <= capacity_min <= capacity_max (' . $row['capacity_min'] . '…' . $row['capacity_max'] . ')';
                }
            }
            if ( ! $location_id ) {
                // A simple service removed while its package stays.
                foreach ( $after as $row ) {
                    $id = $row['service_id'];
                    if ( isset( $catalog[ $id ] ) && $catalog[ $id ]['type'] === Lib\Entities\Service::TYPE_PACKAGE
                        && in_array( $catalog[ $id ]['package_of'], array_map( 'intval', $remove ), true ) ) {
                        $errors[] = 'package_without_simple: removing service ' . $catalog[ $id ]['package_of'] . ' leaves its package ' . $id . ' — remove the package too';
                    }
                }
            }
            if ( wp_json_encode( $after ) !== wp_json_encode( $before ) ) {
                $changed = true;
            }
            $state->set( StaffData::servicesRef( $staff_id, $location_id ?: null ), $after );
        }
        if ( ! $errors && ! $changed ) {
            $errors[] = 'nothing_to_change: the rows already have these values';
        }

        return $errors;
    }

    /**
     * @inheritDoc
     */
    public function preview( array $args, State $state )
    {
        $staff_id = $args['staff_id'];
        $set      = self::items( $args );
        $remove   = isset( $args['remove'] ) ? $args['remove'] : array();
        $catalog  = self::catalog();
        $name     = StaffData::name( $staff_id, $state );
        $link     = Format::link( Access::rule( 'field:staff_service.service_id' ) );
        $rows     = array();
        $warnings = array();
        $differs  = array();
        $added    = array();
        foreach ( self::groups( $set ) as $location_id => $items ) {
            $before = self::byId( StaffData::services( $staff_id, $state, $location_id ?: null ) );
            $after  = self::byId( self::merge( array_values( $before ), $items, $location_id ? array() : $remove, $state ) );
            $label  = Knowledge::label( 'Services' );
            if ( $location_id ) {
                $location = Lib\Proxy\Locations::findById( $location_id );
                $label   .= ' (' . ( $location ? $location->getName() : '#' . $location_id ) . ')';
            }
            foreach ( $after as $id => $row ) {
                $title  = self::title( $id, $catalog, $state );
                $b_text = isset( $before[ $id ] ) ? self::rowText( $before[ $id ] ) : '—';
                $a_text = self::rowText( $row );
                if ( $b_text !== $a_text ) {
                    $rows[] = array( 'label' => StaffData::rowLabel( $staff_id, $state, $label, array( 'item' => $title ) ), 'before' => $b_text, 'after' => $a_text, 'link' => $link );
                    if ( ! isset( $before[ $id ] ) ) {
                        $added[] = $title;
                    }
                    if ( isset( $catalog[ $id ] ) && (float) $catalog[ $id ]['price'] !== (float) $row['price'] ) {
                        $differs[] = $title;
                    }
                }
            }
            foreach ( $before as $id => $row ) {
                if ( ! isset( $after[ $id ] ) ) {
                    $rows[] = array( 'label' => StaffData::rowLabel( $staff_id, $state, $label, array( 'item' => self::title( $id, $catalog, $state ) ) ), 'before' => self::rowText( $row ), 'after' => '—', 'link' => $link );
                }
            }
            $state->set( StaffData::servicesRef( $staff_id, $location_id ?: null ), array_values( $after ) );
        }
        if ( $remove ) {
            $warnings[] = __( 'A service removed from the staff member loses their own price, capacity and deposit for it; adding it back starts from the service values, the deposit from 100%.', 'bookly-responsive-appointment-booking-tool' );
            if ( ! StaffData::isNew( $staff_id, $state ) ) {
                $count = Lib\Entities\Appointment::query( 'a' )
                    ->where( 'a.staff_id', (int) $staff_id )
                    ->whereIn( 'a.service_id', array_map( 'intval', $remove ) )
                    ->whereGt( 'a.start_date', current_time( 'mysql' ) )
                    ->count();
                if ( $count ) {
                    $warnings[] = sprintf( _n( '%d upcoming appointment of a removed service stays in the calendar; new bookings of it with this staff member are no longer possible.', '%d upcoming appointments of removed services stay in the calendar; new bookings of them with this staff member are no longer possible.', $count, 'bookly-responsive-appointment-booking-tool' ), $count );
                }
            }
        }
        if ( $added ) {
            $warnings[] = sprintf( __( '%1$s will now perform: %2$s — clients can book them with this staff member.', 'bookly-responsive-appointment-booking-tool' ), $name, implode( ', ', array_unique( $added ) ) );
        }
        if ( $differs ) {
            $warnings[] = sprintf( __( 'Clients pay the staff member\'s own price, not the service price: %s.', 'bookly-responsive-appointment-booking-tool' ), implode( ', ', $differs ) );
        }
        if ( ! StaffData::isNew( $staff_id, $state ) ) {
            $locations = array_diff( StaffData::ownLocations( (int) $staff_id, 'services' ), array_keys( self::groups( $set ) ) );
            $warnings  = array_merge( $warnings, StaffData::notAffected( $locations ) );
        }

        return array( 'rows' => $rows, 'warnings' => array_values( array_unique( $warnings ) ) );
    }

    /**
     * @inheritDoc
     */
    public function apply( array $args )
    {
        $staff_id = (int) $args['staff_id'];
        $set      = self::items( $args );
        $remove   = isset( $args['remove'] ) ? array_map( 'intval', $args['remove'] ) : array();
        $snapshot = array( 'staff_id' => $staff_id, 'deleted' => array(), 'changed' => array(), 'created' => array() );
        if ( $remove ) {
            $query = Lib\Entities\StaffService::query()->where( 'staff_id', $staff_id )->where( 'location_id', null )->whereIn( 'service_id', $remove );
            $snapshot['deleted'] = $query->fetchArray();
            Lib\Entities\StaffService::query()->delete()->where( 'staff_id', $staff_id )->where( 'location_id', null )->whereIn( 'service_id', $remove )->execute();
        }
        foreach ( self::groups( $set ) as $location_id => $items ) {
            $location_id = $location_id ? (int) $location_id : null;
            $after       = self::byId( self::merge( StaffData::services( $staff_id, null, $location_id ), $items, array(), null ) );
            foreach ( $items as $item ) {
                $service_id = (int) $item['service_id'];
                $row        = $after[ $service_id ];
                $existing   = Lib\Entities\StaffService::query()
                    ->where( 'staff_id', $staff_id )
                    ->where( 'service_id', $service_id )
                    ->where( 'location_id', $location_id )
                    ->find();
                if ( ! $existing ) {
                    $staff_service = new Lib\Entities\StaffService();
                    $staff_service
                        ->setStaffId( $staff_id )
                        ->setServiceId( $service_id )
                        ->setLocationId( $location_id )
                        ->setPrice( $row['price'] )
                        ->setCapacityMin( $row['capacity_min'] )
                        ->setCapacityMax( $row['capacity_max'] )
                        ->save();
                    $snapshot['created'][] = (int) $staff_service->getId();
                    continue;
                }
                // Duplicated rows of one service are changed alike; only the given fields.
                foreach ( $existing as $staff_service ) {
                    /** @var Lib\Entities\StaffService $staff_service */
                    $old = $staff_service->getFields();
                    $staff_service
                        ->setPrice( $row['price'] )
                        ->setCapacityMin( $row['capacity_min'] )
                        ->setCapacityMax( $row['capacity_max'] );
                    if ( (float) $old['price'] != (float) $row['price'] || (int) $old['capacity_min'] !== $row['capacity_min'] || (int) $old['capacity_max'] !== $row['capacity_max'] ) {
                        $staff_service->save();
                        $snapshot['changed'][] = $old;
                    }
                }
            }
        }

        return $snapshot;
    }

    /**
     * @inheritDoc
     */
    public function undo( array $snapshot )
    {
        if ( empty( $snapshot['staff_id'] ) ) {
            return;
        }
        if ( $snapshot['created'] ) {
            Lib\Entities\StaffService::query()->delete()->whereIn( 'id', $snapshot['created'] )->execute();
        }
        foreach ( $snapshot['changed'] as $old ) {
            // The row may have been deleted and inserted again by a later undo: find it by its keys then.
            $staff_service = Lib\Entities\StaffService::find( $old['id'], false );
            if ( ! $staff_service ) {
                $staff_service = new Lib\Entities\StaffService();
                if ( ! $staff_service->loadBy( array( 'staff_id' => $old['staff_id'], 'service_id' => $old['service_id'], 'location_id' => $old['location_id'] ) ) ) {
                    continue;
                }
                unset( $old['id'] );
            }
            $staff_service->setFields( $old )->save();
        }
        foreach ( $snapshot['deleted'] as $old ) {
            unset( $old['id'] );
            $staff_service = new Lib\Entities\StaffService( $old );
            $staff_service->save();
        }
    }

    /**
     * Items of add and set in one list: both build the rows after the change the same way.
     *
     * @param array $args
     * @return array
     */
    protected static function items( array $args )
    {
        return array_merge(
            isset( $args['add'] ) ? $args['add'] : array(),
            isset( $args['set'] ) ? $args['set'] : array()
        );
    }

    /**
     * Items of set grouped by location (0 — general settings); general settings always present,
     * so that remove is applied there.
     *
     * @param array $set
     * @return array
     */
    protected static function groups( array $set )
    {
        $groups = array( 0 => array() );
        foreach ( $set as $item ) {
            $location_id = isset( $item['location_id'] ) && $item['location_id'] ? (int) $item['location_id'] : 0;
            $groups[ $location_id ][] = $item;
        }

        return $groups;
    }

    /**
     * Rows after the change: current rows in their order without the removed ones; a service of
     * set keeps its row with only the given fields changed, a new one starts from the service
     * values (a new package — with the capacity of its simple service).
     *
     * @param array      $current StaffData::services()
     * @param array      $set
     * @param array      $remove
     * @param State|null $state
     * @return array
     */
    protected static function merge( array $current, array $set, array $remove, $state )
    {
        $after   = self::byId( $current );
        $catalog = self::catalog();
        foreach ( $remove as $id ) {
            unset( $after[ is_numeric( $id ) ? (int) $id : $id ] );
        }
        $added = array();
        foreach ( $set as $item ) {
            $id  = $item['service_id'];
            $key = is_numeric( $id ) ? (int) $id : $id;
            if ( isset( $after[ $key ] ) ) {
                $row = $after[ $key ];
            } elseif ( isset( $catalog[ $key ] ) ) {
                $row = array(
                    'service_id'   => $key,
                    'price'        => (float) $catalog[ $key ]['price'],
                    'capacity_min' => (int) $catalog[ $key ]['capacity_min'],
                    'capacity_max' => (int) $catalog[ $key ]['capacity_max'],
                );
                $added[] = $key;
            } else {
                // A service created earlier in the proposal.
                $price   = $state ? $state->get( 'field:service.price#' . $key ) : null;
                $row     = array( 'service_id' => $key, 'price' => (float) $price, 'capacity_min' => 1, 'capacity_max' => 1 );
                $added[] = $key;
            }
            foreach ( array( 'price', 'capacity_min', 'capacity_max' ) as $field ) {
                if ( array_key_exists( $field, $item ) ) {
                    $row[ $field ] = $field === 'price' ? (float) $item[ $field ] : (int) $item[ $field ];
                }
            }
            $after[ $key ] = $row;
        }
        // A package ticked in the tab copies the capacity of its simple service.
        foreach ( $added as $key ) {
            if ( isset( $catalog[ $key ] ) && $catalog[ $key ]['type'] === Lib\Entities\Service::TYPE_PACKAGE ) {
                $simple = $catalog[ $key ]['package_of'];
                if ( $simple && isset( $after[ $simple ] ) ) {
                    $after[ $key ]['capacity_min'] = $after[ $simple ]['capacity_min'];
                    $after[ $key ]['capacity_max'] = $after[ $simple ]['capacity_max'];
                }
            }
        }

        return array_values( $after );
    }

    /**
     * Rows of the list that set mentions.
     *
     * @param array $after
     * @param array $set
     * @return array
     */
    protected static function touched( array $after, array $set )
    {
        $ids = array();
        foreach ( $set as $item ) {
            $ids[] = (string) $item['service_id'];
        }
        $rows = array();
        foreach ( $after as $row ) {
            if ( in_array( (string) $row['service_id'], $ids, true ) ) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Services of the tab by id: type, title, price, capacity, package_of.
     *
     * @return array
     */
    protected static function catalog()
    {
        $catalog = array();
        foreach ( StaffData::allowedServices() as $service ) {
            $catalog[ $service['id'] ] = $service;
        }
        $rows = Lib\Entities\Service::query()->select( 'id, capacity_min, capacity_max' )->whereIn( 'id', array_keys( $catalog ) ?: array( 0 ) )->fetchArray();
        foreach ( $rows as $row ) {
            $catalog[ $row['id'] ]['capacity_min'] = (int) $row['capacity_min'];
            $catalog[ $row['id'] ]['capacity_max'] = (int) $row['capacity_max'];
        }

        return $catalog;
    }

    /**
     * @param array $list
     * @return array
     */
    protected static function byId( array $list )
    {
        $by_id = array();
        foreach ( $list as $row ) {
            $by_id[ $row['service_id'] ] = $row;
        }

        return $by_id;
    }

    /**
     * @param mixed      $id
     * @param array      $catalog
     * @param State|null $state
     * @return string
     */
    protected static function title( $id, array $catalog, $state )
    {
        if ( isset( $catalog[ $id ] ) ) {
            return $catalog[ $id ]['title'];
        }
        $title = $state ? $state->get( 'field:service.title#' . $id ) : null;

        return $title ? $title : '#' . $id;
    }

    /**
     * Price and capacity as the card shows them.
     *
     * @param array $row
     * @return string
     */
    protected static function rowText( array $row )
    {
        $text = Format::price( $row['price'] );
        if ( Lib\Config::groupBookingActive() ) {
            $text .= ', ' . sprintf( __( 'capacity %d–%d', 'bookly-responsive-appointment-booking-tool' ), $row['capacity_min'], $row['capacity_max'] );
        }

        return $text;
    }
}
