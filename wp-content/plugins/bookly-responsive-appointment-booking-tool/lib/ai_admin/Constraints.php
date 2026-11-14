<?php
namespace Bookly\Lib\AiAdmin;

/**
 * Checks of an entry's constraints (Rules::entries()[ref]['constraints']): comparisons with
 * other values (gt, gte, lt, lte, with "plus"), format, length, in / not_in, "unchanged" under
 * "when". Values of other entries come from $value_of — the state after the earlier items of
 * the same proposal.
 */
abstract class Constraints
{
    /**
     * Failed constraints as "id: message" strings; empty when all hold.
     *
     * @param array    $rule     Rules::entries() item
     * @param mixed    $value    new value
     * @param mixed    $before   value before the proposal
     * @param callable $value_of function( string $ref ) → current value of another entry
     * @return string[]
     */
    public static function failed( array $rule, $value, $before, $value_of )
    {
        $errors = array();
        foreach ( isset( $rule['constraints'] ) ? $rule['constraints'] : array() as $c ) {
            if ( isset( $c['when'] ) && ! self::holds( $c['when'], $value_of ) ) {
                continue;
            }
            if ( ! self::check( $c, $value, $before, $value_of, isset( $rule['type'] ) ? $rule['type'] : null ) ) {
                $errors[] = $c['id'] . ': ' . ( isset( $c['message'] ) ? $c['message'] : '' );
            }
        }

        return $errors;
    }

    /**
     * Whether the entry's control is in the form under the given settings (ui.visible_when:
     * one condition or a list that must all hold).
     *
     * @param array    $rule
     * @param callable $value_of
     * @return bool
     */
    public static function visible( array $rule, $value_of )
    {
        if ( empty( $rule['visible_when'] ) ) {
            return true;
        }
        $conds = isset( $rule['visible_when']['ref'] ) ? array( $rule['visible_when'] ) : $rule['visible_when'];
        foreach ( $conds as $cond ) {
            if ( ! self::holds( $cond, $value_of ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a condition {ref, is | in | not} holds.
     *
     * @param array    $cond
     * @param callable $value_of
     * @return bool
     */
    public static function holds( array $cond, $value_of )
    {
        $current = call_user_func( $value_of, $cond['ref'] );
        if ( array_key_exists( 'in', $cond ) ) {
            foreach ( (array) $cond['in'] as $v ) {
                if ( (string) $v === (string) $current ) {
                    return true;
                }
            }

            return false;
        }
        if ( array_key_exists( 'not', $cond ) ) {
            return (string) $cond['not'] !== (string) $current;
        }

        return (string) $cond['is'] === (string) $current;
    }

    /**
     * @param array    $c
     * @param mixed    $value
     * @param mixed    $before
     * @param callable $value_of
     * @param string   $type
     * @return bool
     */
    protected static function check( array $c, $value, $before, $value_of, $type )
    {
        if ( ! empty( $c['unchanged'] ) && (string) $value !== (string) $before ) {
            return false;
        }
        foreach ( array( 'gt', 'gte', 'lt', 'lte' ) as $op ) {
            if ( ! isset( $c[ $op ] ) ) {
                continue;
            }
            $other = $c[ $op ];
            $plus  = 0;
            if ( is_array( $other ) ) {
                $plus  = isset( $other['plus'] ) ? $other['plus'] : 0;
                $other = call_user_func( $value_of, $other['ref'] );
            } elseif ( is_string( $other ) && preg_match( '/^(option|field):/', $other ) ) {
                $other = call_user_func( $value_of, $other );
            }
            $a = self::number( $value, $type );
            $b = self::number( $other, $type );
            if ( $a === null || $b === null ) {
                // Empty on either side (a day OFF) — nothing to compare with.
                continue;
            }
            $b += $plus;
            $ok = $op === 'gt' ? $a > $b : ( $op === 'gte' ? $a >= $b : ( $op === 'lt' ? $a < $b : $a <= $b ) );
            if ( ! $ok ) {
                return false;
            }
        }
        if ( isset( $c['format'] ) && (string) $value !== '' && ! self::format( $c['format'], $value ) ) {
            return false;
        }
        $length = is_array( $value ) ? count( $value ) : strlen( (string) $value );
        // An empty value counts: "required" is min_length 1. A value that may be empty or of some
        // length expresses the empty case with "when".
        if ( isset( $c['min_length'] ) && $length < $c['min_length'] ) {
            return false;
        }
        if ( isset( $c['max_length'] ) && $length > $c['max_length'] ) {
            return false;
        }
        if ( isset( $c['multiple_of'] ) && $c['multiple_of'] && fmod( (float) $value, $c['multiple_of'] ) != 0 ) {
            return false;
        }
        if ( isset( $c['in'] ) ) {
            foreach ( (array) $value as $v ) {
                if ( ! in_array( (string) $v, array_map( 'strval', (array) $c['in'] ), true ) ) {
                    return false;
                }
            }
        }
        if ( isset( $c['not_in'] ) ) {
            foreach ( (array) $value as $v ) {
                if ( in_array( (string) $v, array_map( 'strval', (array) $c['not_in'] ), true ) ) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Number to compare: seconds from midnight for a time, the number itself otherwise.
     *
     * @param mixed  $value
     * @param string $type
     * @return float|null
     */
    protected static function number( $value, $type )
    {
        if ( $value === null || $value === '' ) {
            return null;
        }
        if ( is_numeric( $value ) ) {
            return (float) $value;
        }
        $s = AllowedValues::seconds( $value );

        return $s === null ? null : (float) $s;
    }

    /**
     * @param string $format
     * @param mixed  $value
     * @return bool
     */
    protected static function format( $format, $value )
    {
        switch ( $format ) {
            case 'email':     return is_email( $value ) !== false;
            case 'digits':    return (bool) preg_match( '/^\d+$/', (string) $value );
            case 'iso2':      return (bool) preg_match( '/^[a-z]{2}$/', (string) $value );
            case 'url_http':  return (bool) preg_match( '~^https?://~i', (string) $value );
            case 'color_hex': return (bool) preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $value );
            case 'time_hhmm': return AllowedValues::seconds( $value ) !== null;
            case 'slug_list': return is_array( $value );
        }

        return true;
    }
}
