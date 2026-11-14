<?php
namespace Bookly\Lib\AiAdmin;

use Bookly\Lib;

/**
 * What the proposal card shows: labels and values in the admin's language, warnings whose
 * "when" matches before → after and whose "while" holds.
 */
abstract class Format
{
    /** @var State|null state of the proposal whose card is being built (see Proposal::check()) */
    protected static $state;
    /** @var array row label => object, field and day of the row, of the item being previewed */
    protected static $rows = array();

    /**
     * Object a card row belongs to.
     *
     * @param string $key   entity:id, the same for every row of the object ("service:$2")
     * @param string $title name of the object as of this row
     * @param string $kind  "New service", "New staff member"… — shown when the card creates it
     * @param bool   $new   the card creates the object
     * @return array
     */
    public static function object( $key, $title, $kind, $new )
    {
        return compact( 'key', 'title', 'kind', 'new' );
    }

    /**
     * Label "<object> › <field>[ › <item or day>]" of a card row; remembers what it refers to, so
     * the card can show the rows grouped by object (Proposal::groups()).
     *
     * @param array  $object  see object()
     * @param string $field   label of the field, with its path in the interface ("General › Price")
     * @param array  $options day  — mon…sun: the row is a day of the weekly schedule $field;
     *                        item — the row is an entry of the list $field (a service of a staff
     *                               member);
     *                        name — true: the row sets the object's name, the card shows it in the
     *                               header
     * @return string
     */
    public static function rowLabel( array $object, $field, array $options = array() )
    {
        $options += array( 'day' => null, 'item' => null, 'name' => false );
        $label    = $object['title'] . ' › ' . $field;
        if ( $options['day'] !== null ) {
            $label .= ' › ' . StaffData::dayName( $options['day'] );
        } elseif ( $options['item'] !== null ) {
            $label .= ' › ' . $options['item'];
        }
        self::$rows[ $label ] = array( 'object' => $object, 'field' => $field ) + $options;

        return $label;
    }

    /**
     * What rowLabel() remembered since the last call.
     *
     * @return array
     */
    public static function takeRows()
    {
        $rows       = self::$rows;
        self::$rows = array();

        return $rows;
    }

    /**
     * Make price() read the currency and price format from the proposal being shown, so an item
     * after a currency change in the same card shows the new currency.
     *
     * @param State|null $state null when the card is built
     */
    public static function useState( $state )
    {
        self::$state = $state;
    }

    /**
     * Price as the interface shows it, in the currency and format after the earlier items.
     *
     * @param float $price
     * @return string
     */
    public static function price( $price )
    {
        if ( self::$state ) {
            return Lib\Utils\Price::format( $price, self::$state->get( 'option:bookly_pmt_currency' ), self::$state->get( 'option:bookly_pmt_price_format' ) );
        }

        return Lib\Utils\Price::format( $price );
    }

    /**
     * @param array  $rule
     * @param string $fallback
     * @return string
     */
    public static function label( $rule, $fallback )
    {
        if ( ! empty( $rule['label'] ) ) {
            $label = Knowledge::label( $rule['label'] );
            if ( ! empty( $rule['path'] ) && end( $rule['path'] ) !== $rule['label'] ) {
                $label = Knowledge::label( end( $rule['path'] ) ) . ' › ' . $label;
            }

            return $label;
        }

        return $fallback;
    }

    /**
     * Value as the interface shows it: the label of a listed value, "on/off", times, or as is.
     *
     * @param array $rule
     * @param mixed $value
     * @return string
     */
    public static function value( $rule, $value )
    {
        $pairs = array();
        if ( isset( $rule['values']['pairs'] ) ) {
            $pairs = $rule['values']['pairs'];
        } elseif ( isset( $rule['values']['special'] ) ) {
            $pairs = $rule['values']['special'];
        }
        foreach ( $pairs as $pair ) {
            if ( (string) $pair['value'] === (string) $value && ! empty( $pair['label'] ) ) {
                return Knowledge::label( $pair['label'] );
            }
        }
        $type = isset( $rule['type'] ) ? $rule['type'] : null;
        if ( $type === 'bool' ) {
            return $value ? __( 'Enabled', 'bookly-responsive-appointment-booking-tool' ) : __( 'Disabled', 'bookly-responsive-appointment-booking-tool' );
        }
        if ( $type === 'time' && AllowedValues::seconds( $value ) !== null ) {
            return Lib\Utils\DateTime::formatTime( AllowedValues::seconds( $value ) );
        }
        if ( is_array( $value ) ) {
            return implode( ', ', array_map( 'strval', $value ) );
        }
        if ( $value === '' || $value === null || $value === false ) {
            return '—';
        }
        $unit = isset( $rule['unit'] ) ? $rule['unit'] : null;
        if ( $unit === 'seconds' && is_numeric( $value ) ) {
            return Lib\Utils\DateTime::secondsToInterval( (int) $value );
        }

        return (string) $value . ( $unit ? ' ' . $unit : '' );
    }

    /**
     * Link to the screen where the owner changes the value, or null.
     *
     * @param array $rule
     * @return string|null
     */
    public static function link( $rule )
    {
        if ( empty( $rule['page'] ) ) {
            return null;
        }
        $args = array( 'page' => 'bookly-' . $rule['page'] );
        if ( ! empty( $rule['tab'] ) ) {
            $args['tab'] = $rule['tab'];
        }

        return add_query_arg( $args, admin_url( 'admin.php' ) );
    }

    /**
     * Warnings of an entry for this change.
     *
     * @param array $rule
     * @param mixed $before
     * @param mixed $after
     * @param State $state
     * @return string[]
     */
    public static function warnings( $rule, $before, $after, State $state )
    {
        $out = array();
        foreach ( isset( $rule['warnings'] ) ? $rule['warnings'] : array() as $w ) {
            if ( ! self::when( $w['when'], $before, $after ) ) {
                continue;
            }
            if ( isset( $w['while'] ) && ! Constraints::holds( $w['while'], $state->reader() ) ) {
                continue;
            }
            $out[] = $w['text'];
        }

        return $out;
    }

    /**
     * @param string|array $when always | enable | disable | increase | decrease | array( to | from => value )
     * @param mixed        $before
     * @param mixed        $after
     * @return bool
     */
    protected static function when( $when, $before, $after )
    {
        if ( is_array( $when ) ) {
            if ( array_key_exists( 'to', $when ) && (string) $when['to'] !== (string) $after ) {
                return false;
            }
            if ( array_key_exists( 'from', $when ) && (string) $when['from'] !== (string) $before ) {
                return false;
            }

            return (string) $before !== (string) $after;
        }
        $empty = function ( $v ) {
            return $v === '' || $v === null || $v === false || $v === '0' || $v === 0;
        };
        switch ( $when ) {
            case 'always':   return true;
            case 'enable':   return $empty( $before ) && ! $empty( $after );
            case 'disable':  return ! $empty( $before ) && $empty( $after );
            case 'increase': return is_numeric( $before ) && is_numeric( $after ) && $after > $before;
            case 'decrease': return is_numeric( $before ) && is_numeric( $after ) && $after < $before;
        }

        return false;
    }
}
