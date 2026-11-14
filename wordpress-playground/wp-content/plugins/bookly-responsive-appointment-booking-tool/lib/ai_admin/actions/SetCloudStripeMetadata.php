<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\State;

/**
 * set_cloud_stripe_metadata — replace the whole list of custom metadata pairs sent to Stripe with
 * Stripe Cloud payments (assets/ai-setup/actions/set_cloud_stripe_metadata.yaml). Validation ids
 * follow the action's validation list. The switches bookly_cloud_stripe_enabled and
 * bookly_cloud_stripe_custom_metadata are changed by set_option earlier in the same proposal.
 */
class SetCloudStripeMetadata implements ActionInterface
{
    const OPTION    = 'bookly_cloud_stripe_metadata';
    // Stripe takes 50 keys and the payment adds payment_id to the owner's pairs.
    const MAX_PAIRS = 49;
    const MAX_NAME  = 40;
    const MAX_VALUE = 500;

    /**
     * Whether the Stripe Cloud product is active (as Settings → Payments checks it).
     *
     * @return bool
     */
    public static function productActive()
    {
        return Lib\Cloud\API::getInstance()->account->productActive( Lib\Cloud\Account::PRODUCT_STRIPE );
    }

    /**
     * Codes a value may use: the codes shown under the list in the form (payment step).
     *
     * @return string[]
     */
    public static function allowedCodes()
    {
        $codes = json_decode( \Bookly\Backend\Modules\Appearance\Codes::getJson( '7' ), true );
        $names = array();
        if ( is_array( $codes ) ) {
            foreach ( $codes as $code => $data ) {
                if ( ! isset( $data['loop'] ) ) {
                    $names[] = (string) $code;
                }
            }
        }

        return $names;
    }

    /**
     * Stored list as array( array( 'name', 'value' ) ), whatever the option holds.
     *
     * @param mixed $stored
     * @return array
     */
    public static function normalize( $stored )
    {
        $list = array();
        if ( is_array( $stored ) ) {
            foreach ( $stored as $pair ) {
                if ( is_array( $pair ) && isset( $pair['name'] ) ) {
                    $list[] = array(
                        'name'  => (string) $pair['name'],
                        'value' => isset( $pair['value'] ) ? (string) $pair['value'] : '',
                    );
                }
            }
        }

        return $list;
    }

    /**
     * @inheritDoc
     */
    public function validate( array $args, State $state )
    {
        $errors = array();
        if ( ! Access::allowed( array( 'admin' ) ) ) {
            return array( 'forbidden: only a Bookly administrator can change Stripe Cloud metadata' );
        }
        if ( ! self::productActive() ) {
            return array( 'cloud_product_inactive: the Stripe Cloud product is not active, the list is not on the page' );
        }
        if ( ! $state->get( 'option:bookly_cloud_stripe_enabled' ) ) {
            $errors[] = 'stripe_cloud_off: turn Stripe Cloud on first (set_option bookly_cloud_stripe_enabled = 1 earlier in this proposal)';
        }
        if ( ! $state->get( 'option:bookly_cloud_stripe_custom_metadata' ) ) {
            $errors[] = 'custom_metadata_off: turn "Add custom metadata to payment" on first (set_option bookly_cloud_stripe_custom_metadata = 1 earlier in this proposal)';
        }
        if ( ! isset( $args['metadata'] ) || ! is_array( $args['metadata'] ) ) {
            $errors[] = 'metadata: a list of {name, value} is required (an empty list removes all pairs)';

            return $errors;
        }
        $pairs = array_values( $args['metadata'] );
        if ( count( $pairs ) > self::MAX_PAIRS ) {
            $errors[] = 'too_many_pairs: ' . count( $pairs ) . ' pairs, at most ' . self::MAX_PAIRS;
        }
        $allowed = self::allowedCodes();
        $seen    = array();
        foreach ( $pairs as $i => $pair ) {
            $n = '#' . ( $i + 1 );
            if ( ! is_array( $pair ) || ! array_key_exists( 'name', $pair ) || ! array_key_exists( 'value', $pair )
                || ! is_string( $pair['name'] ) || ! is_string( $pair['value'] ) ) {
                $errors[] = 'metadata: pair ' . $n . ' must be {name: string, value: string}';
                continue;
            }
            $name  = $pair['name'];
            $value = $pair['value'];
            if ( $name === '' ) {
                $errors[] = 'name_required: pair ' . $n . ' has an empty name';
            } else {
                if ( strlen( $name ) > self::MAX_NAME ) {
                    $errors[] = 'name_too_long: pair ' . $n . ' "' . $name . '" is longer than ' . self::MAX_NAME . ' characters';
                }
                if ( ! preg_match( '/^[A-Za-z0-9 ]+$/', $name ) ) {
                    $errors[] = 'name_chars: pair ' . $n . ' "' . $name . '" has characters other than letters A-Z a-z, digits and spaces';
                }
                if ( isset( $seen[ $name ] ) ) {
                    $errors[] = 'name_duplicate: name "' . $name . '" is used more than once';
                }
                $seen[ $name ] = true;
            }
            if ( strlen( $value ) > self::MAX_VALUE ) {
                $errors[] = 'value_too_long: pair ' . $n . ' value is longer than ' . self::MAX_VALUE . ' characters';
            }
            if ( strpos( $value, '"' ) !== false || preg_match( '/\$[$&`\']/', $value ) ) {
                $errors[] = 'value_unrenderable: pair ' . $n . ' value has a double quote or one of $$ $& $` $\' that the page cannot show back';
            }
            if ( preg_match_all( '/\{([^{}]+)\}/', $value, $m ) ) {
                foreach ( array_unique( $m[1] ) as $code ) {
                    if ( ! in_array( $code, $allowed, true ) ) {
                        $errors[] = 'unknown_code: pair ' . $n . ' uses {' . $code . '}; allowed: ' . implode( ', ', $allowed );
                    }
                }
            }
        }
        if ( ! $errors ) {
            $new = self::normalize( $pairs );
            if ( $new === self::normalize( $state->get( 'option:' . self::OPTION ) ) ) {
                return array( 'nothing_to_change: the new list is the same as the current one' );
            }
            $state->set( 'option:' . self::OPTION, $new );
        }

        return $errors;
    }

    /**
     * @inheritDoc
     */
    public function preview( array $args, State $state )
    {
        $ref    = 'option:' . self::OPTION;
        $before = self::normalize( $state->get( $ref ) );
        $after  = self::normalize( $args['metadata'] );
        $rows   = array();

        $old = array();
        foreach ( $before as $pair ) {
            $old[ $pair['name'] ] = $pair['value'];
        }
        $new = array();
        foreach ( $after as $pair ) {
            $new[ $pair['name'] ] = $pair['value'];
        }
        foreach ( $after as $pair ) {
            $name = $pair['name'];
            if ( ! array_key_exists( $name, $old ) ) {
                $rows[] = array( 'label' => $name, 'before' => '', 'after' => $pair['value'] );
            } elseif ( $old[ $name ] !== $pair['value'] ) {
                $rows[] = array( 'label' => $name, 'before' => $old[ $name ], 'after' => $pair['value'] );
            }
        }
        foreach ( $before as $pair ) {
            if ( ! array_key_exists( $pair['name'], $new ) ) {
                $rows[] = array( 'label' => $pair['name'], 'before' => $pair['value'], 'after' => '' );
            }
        }
        $order_before = array_values( array_intersect( array_keys( $old ), array_keys( $new ) ) );
        $order_after  = array_values( array_intersect( array_keys( $new ), array_keys( $old ) ) );
        if ( $order_before !== $order_after ) {
            $rows[] = array(
                'label'  => __( 'Order of pairs', 'bookly-responsive-appointment-booking-tool' ),
                'before' => implode( ', ', $order_before ),
                'after'  => implode( ', ', $order_after ),
            );
        }
        $codes = array();
        foreach ( $after as $pair ) {
            if ( preg_match_all( '/\{([^{}]+)\}/', $pair['value'], $m ) ) {
                $codes[] = $pair['name'] . ': {' . implode( '}, {', array_unique( $m[1] ) ) . '}';
            }
        }
        if ( $codes ) {
            $rows[] = array(
                'label'  => __( 'Codes in values', 'bookly-responsive-appointment-booking-tool' ),
                'before' => '',
                'after'  => implode( '; ', $codes ),
            );
        }
        $rows[] = array(
            'label'  => __( 'Keys Stripe receives', 'bookly-responsive-appointment-booking-tool' ),
            'before' => self::stripeKeys( $before ),
            'after'  => self::stripeKeys( $after ),
        );
        $state->set( $ref, $after );

        return array(
            'rows'     => $rows,
            'warnings' => array( __( 'Only new Stripe Cloud payments get the changed list; payments already made keep what was sent.', 'bookly-responsive-appointment-booking-tool' ) ),
        );
    }

    /**
     * @inheritDoc
     */
    public function apply( array $args )
    {
        $previous = get_option( self::OPTION, array() );
        $list     = self::normalize( $args['metadata'] );
        update_option( self::OPTION, $list );

        return array( 'previous' => $previous, 'applied' => $list );
    }

    /**
     * Restore the previous list unless the list was changed since apply().
     *
     * @inheritDoc
     */
    public function undo( array $snapshot )
    {
        if ( self::normalize( get_option( self::OPTION, array() ) ) === $snapshot['applied'] ) {
            update_option( self::OPTION, $snapshot['previous'] );
        }
    }

    /**
     * Key names as Stripe gets them (Lib\Payment\StripeCloudGateway::getInternalMetaData()), plus payment_id.
     *
     * @param array $list
     * @return string
     */
    protected static function stripeKeys( array $list )
    {
        $keys = array();
        foreach ( $list as $pair ) {
            $keys[ preg_replace( '/[^ \w]+/', '', $pair['name'] ) ] = true;
        }
        $keys['payment_id'] = true;

        return implode( ', ', array_keys( $keys ) );
    }
}
