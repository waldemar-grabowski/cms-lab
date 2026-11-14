<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\Constraints;
use Bookly\Lib\AiAdmin\Format;
use Bookly\Lib\AiAdmin\Services\ServiceAction;
use Bookly\Lib\AiAdmin\State;

/**
 * set_tax — create a tax or change its title and rate (Taxes add-on,
 * assets/ai-setup/actions/set_tax.yaml).
 */
class SetTax extends ServiceAction implements ActionInterface
{
    protected function name()
    {
        return 'set_tax';
    }

    /**
     * Taxes after the earlier items: id|"$N" => array( title, rate, service_ids ).
     *
     * @param State $state
     * @return array
     */
    public static function taxes( State $state )
    {
        if ( $state->has( 'list:taxes' ) ) {
            return $state->get( 'list:taxes' );
        }
        $taxes = array();
        foreach ( (array) Lib\Proxy\Taxes::getTaxesList() as $tax ) {
            $taxes[ $tax['id'] ] = $tax;
        }

        return $taxes;
    }

    /**
     * @inheritDoc
     */
    public function validate( array $args, State $state )
    {
        if ( $error = $this->forbidden() ) {
            return array( $error );
        }
        if ( ! Lib\Config::taxesActive() ) {
            return array( 'addon_inactive: taxes need the taxes add-on' );
        }
        foreach ( array( 'title', 'rate' ) as $field ) {
            $rule = Access::rule( 'field:tax.' . $field );
            if ( array_key_exists( $field, $args ) && ( ! $rule || empty( $rule['edit'] ) || ! Access::allowed( $rule['edit'] ) ) ) {
                return array( 'forbidden: tax ' . $field . ' cannot be changed by the assistant' );
            }
        }
        $taxes = self::taxes( $state );
        $new = ! isset( $args['tax_id'] );
        if ( ! $new && ! isset( $taxes[ (int) $args['tax_id'] ] ) ) {
            return array( 'tax_not_found: ' . wp_json_encode( $args['tax_id'] ) );
        }
        $errors = array();
        if ( $new && ! array_key_exists( 'title', $args ) ) {
            $errors[] = 'title_required: a new tax needs a title';
        }
        if ( $new && ! array_key_exists( 'rate', $args ) ) {
            $errors[] = 'rate_required: a new tax needs a rate';
        }
        if ( array_key_exists( 'title', $args ) ) {
            $title = is_string( $args['title'] ) ? trim( $args['title'] ) : '';
            // The constraint of the entry has the id of the validation rule (title_required).
            foreach ( Constraints::failed( Access::rule( 'field:tax.title' ), $title, null, $state->reader() ) as $failed ) {
                $errors[] = $failed;
            }
            if ( mb_strlen( (string) $args['title'] ) > 255 ) {
                $errors[] = 'value_too_long: title is at most 255 characters';
            }
        }
        if ( array_key_exists( 'rate', $args ) ) {
            if ( ! is_numeric( $args['rate'] ) || $args['rate'] < 0 ) {
                $errors[] = 'rate_negative: the rate is a number, zero or more';
            } elseif ( $args['rate'] > 9999999.999 ) {
                $errors[] = 'value_too_long: the rate is at most 9999999.999';
            }
        }
        if ( ! $new && ! array_key_exists( 'title', $args ) && ! array_key_exists( 'rate', $args ) ) {
            $errors[] = 'nothing_to_change: give title or rate';
        }
        if ( ! $errors ) {
            $this->record( $args, $state );
        }

        return $errors;
    }

    /**
     * @param array $args
     * @param State $state
     */
    protected function record( array $args, State $state )
    {
        $taxes = self::taxes( $state );
        if ( isset( $args['tax_id'] ) ) {
            $id = (int) $args['tax_id'];
        } else {
            $id = self::newRef( $state );
            $state->created( 'tax' );
            $taxes[ $id ] = array( 'id' => $id, 'title' => '', 'rate' => 0.0, 'service_ids' => array() );
        }
        if ( array_key_exists( 'title', $args ) ) {
            $taxes[ $id ]['title'] = $args['title'];
        }
        if ( array_key_exists( 'rate', $args ) ) {
            $taxes[ $id ]['rate'] = round( (float) $args['rate'], 3 );
        }
        $state->set( 'list:taxes', $taxes );
    }

    /**
     * @inheritDoc
     */
    public function preview( array $args, State $state )
    {
        $taxes = self::taxes( $state );
        $new = ! isset( $args['tax_id'] );
        $tax = $new ? array( 'title' => '', 'rate' => null, 'service_ids' => array() ) : $taxes[ (int) $args['tax_id'] ];
        $object = Format::object(
            'tax:' . ( $new ? self::newRef( $state ) : (int) $args['tax_id'] ),
            $new ? ( isset( $args['title'] ) ? $args['title'] : '' ) : $tax['title'],
            __( 'New tax', 'bookly-responsive-appointment-booking-tool' ),
            $new
        );
        $rows = array();
        if ( array_key_exists( 'title', $args ) ) {
            $rule = Access::rule( 'field:tax.title' );
            $rows[] = array( 'label' => Format::rowLabel( $object, Format::label( $rule, 'title' ), array( 'name' => $new ) ), 'before' => $new ? '—' : $tax['title'], 'after' => $args['title'], 'link' => Format::link( $rule ) );
        }
        if ( array_key_exists( 'rate', $args ) ) {
            $rule = Access::rule( 'field:tax.rate' );
            $rows[] = array( 'label' => Format::rowLabel( $object, Format::label( $rule, 'rate' ) ), 'before' => $new ? '—' : $tax['rate'] . '%', 'after' => round( (float) $args['rate'], 3 ) . '%', 'link' => Format::link( $rule ) );
            // The tax on the price of each service the tax is applied to.
            if ( ! $new && (float) $args['rate'] != (float) $tax['rate'] ) {
                foreach ( $tax['service_ids'] as $service_id ) {
                    $price = (float) self::current( $state, $service_id, 'price' );
                    $rows[] = array(
                        'label' => self::rowLabel( $state, $service_id, __( 'Tax', 'bookly-responsive-appointment-booking-tool' ) . ' (' . $tax['title'] . ')' ),
                        'before' => Format::price( Lib\Proxy\Taxes::calculateTax( $price, (float) $tax['rate'] ) ),
                        'after' => Format::price( Lib\Proxy\Taxes::calculateTax( $price, (float) $args['rate'] ) ),
                    );
                }
            }
        }
        $this->record( $args, $state );

        return array( 'rows' => $rows, 'warnings' => $this->actionWarnings( $state ) );
    }

    /**
     * @inheritDoc
     */
    public function apply( array $args )
    {
        $fields = array();
        $snapshot = array( 'created' => null, 'before' => null, 'after' => null );
        if ( isset( $args['tax_id'] ) ) {
            $fields['id'] = (int) $args['tax_id'];
            foreach ( (array) Lib\Proxy\Taxes::getTaxesList() as $tax ) {
                if ( $tax['id'] === $fields['id'] ) {
                    $snapshot['before'] = array( 'id' => $tax['id'], 'title' => $tax['title'], 'rate' => $tax['rate'] );
                }
            }
        }
        if ( array_key_exists( 'title', $args ) ) {
            $fields['title'] = $args['title'];
        }
        if ( array_key_exists( 'rate', $args ) ) {
            $fields['rate'] = $args['rate'];
        }
        $id = Lib\Proxy\Taxes::saveTax( $fields );
        if ( ! isset( $args['tax_id'] ) ) {
            $snapshot['created'] = $id;
        }
        foreach ( (array) Lib\Proxy\Taxes::getTaxesList() as $tax ) {
            if ( $tax['id'] === $id ) {
                $snapshot['after'] = array( 'title' => $tax['title'], 'rate' => $tax['rate'] );
            }
        }
        $snapshot['created_id'] = $snapshot['created'];

        return $snapshot;
    }

    /**
     * @inheritDoc
     */
    public function undo( array $snapshot )
    {
        if ( $snapshot['created'] ) {
            Lib\Proxy\Taxes::deleteTax( $snapshot['created'] );

            return;
        }
        if ( $snapshot['before'] ) {
            foreach ( (array) Lib\Proxy\Taxes::getTaxesList() as $tax ) {
                // Restore only when the tax still has the values the assistant wrote.
                if ( $tax['id'] === $snapshot['before']['id'] && array( 'title' => $tax['title'], 'rate' => $tax['rate'] ) == $snapshot['after'] ) {
                    Lib\Proxy\Taxes::saveTax( $snapshot['before'] );
                }
            }
        }
    }
}
