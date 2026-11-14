<?php
namespace Bookly\Lib\AiAdmin;

/**
 * One propose call of the setup assistant: all changes of a request, checked in order (each
 * item sees the effect of the previous ones), shown as one card, applied and undone together.
 */
abstract class Proposal
{
    /**
     * Implemented actions: action name → class. Every class in lib/ai_admin/actions/ that
     * implements ActionInterface is one (SetStaffSchedule → set_staff_schedule).
     *
     * @return array
     */
    public static function actions()
    {
        static $actions;
        if ( $actions === null ) {
            $actions = array();
            foreach ( glob( __DIR__ . '/actions/*.php' ) ?: array() as $file ) {
                $class = basename( $file, '.php' );
                if ( $class === 'ActionInterface' ) {
                    continue;
                }
                $fqcn = '\Bookly\Lib\AiAdmin\Actions\\' . $class;
                if ( class_exists( $fqcn ) && in_array( 'Bookly\Lib\AiAdmin\Actions\ActionInterface', class_implements( $fqcn ), true ) ) {
                    $actions[ strtolower( preg_replace( '/([a-z])([A-Z])/', '$1_$2', $class ) ) ] = $fqcn;
                }
            }
        }

        return $actions;
    }

    /**
     * @param string $name
     * @return Actions\ActionInterface|null
     */
    public static function action( $name )
    {
        $actions = self::actions();
        if ( ! isset( $actions[ $name ] ) ) {
            return null;
        }
        $rules = Rules::actions();
        if ( isset( $rules[ $name ]['roles'] ) && ! Access::allowed( $rules[ $name ]['roles'] ) ) {
            return null;
        }

        return new $actions[ $name ]();
    }

    /**
     * Check a proposal and build its card.
     *
     * @param array $changes array( array( 'action' => …, 'args' => array( … ) ) )
     * @return array array( 'errors' => string[], 'items' => array( array( 'action', 'rows', 'warnings' ) ) )
     */
    public static function check( array $changes )
    {
        $errors  = array();
        $changes = array_values( $changes );
        $state   = new State();
        foreach ( $changes as $i => $item ) {
            $state->setItem( $i + 1 );
            $name   = isset( $item['action'] ) ? (string) $item['action'] : '';
            $action = self::action( $name );
            $args   = isset( $item['args'] ) && is_array( $item['args'] ) ? $item['args'] : array();
            if ( ! $action ) {
                $errors[] = sprintf( 'item %d: unknown or not permitted action "%s"', $i + 1, $name );
                continue;
            }
            foreach ( $action->validate( $args, $state ) as $error ) {
                $errors[] = sprintf( 'item %d (%s): %s', $i + 1, $name, $error );
            }
        }
        if ( $errors ) {
            $hint = self::refHint( $errors, $state->createdItems() );
            if ( $hint !== null ) {
                $errors[] = $hint;
            }

            return array( 'errors' => $errors, 'items' => array(), 'groups' => array() );
        }
        // Valid: build the card on a fresh state, again in order.
        $items = array();
        $state = new State();
        $shown = array();
        Format::useState( $state );
        foreach ( $changes as $i => $item ) {
            $state->setItem( $i + 1 );
            Format::takeRows();
            // preview() reads "before" from $state and records the item's effect itself.
            $preview = self::action( $item['action'] )->preview( $item['args'], $state );
            // What each row refers to, for the grouped card (groups()).
            $meta = Format::takeRows();
            foreach ( $preview['rows'] as &$row ) {
                if ( isset( $meta[ $row['label'] ] ) ) {
                    $row += $meta[ $row['label'] ];
                }
            }
            unset( $row );
            // One card: a warning several items share (three created services) is shown once.
            $warnings = array();
            foreach ( isset( $preview['warnings'] ) ? $preview['warnings'] : array() as $warning ) {
                if ( ! isset( $shown[ $warning ] ) ) {
                    $shown[ $warning ] = true;
                    $warnings[]        = $warning;
                }
            }
            $preview['warnings'] = $warnings;
            $items[] = array_merge( array( 'action' => $item['action'] ), $preview );
        }
        Format::useState( null );

        return array( 'errors' => array(), 'items' => $items, 'groups' => self::groups( $items ) );
    }

    /**
     * The card as the owner reads it: rows grouped by object, each field once with its value
     * after the whole proposal. An object the card creates shows only the result — its name in
     * the header, short values in one line, the rest as "field: value"; an existing one shows
     * "before → after" of what changes. Days of a weekly schedule with the same hours are joined.
     * The rows of the items stay as they are: Apply compares them (stale_preview).
     *
     * @param array $items
     * @return array list of array( title, rows: list of array( label, before|null, after, link ) )
     */
    public static function groups( array $items )
    {
        $groups = array();
        foreach ( $items as $item ) {
            foreach ( $item['rows'] as $row ) {
                if ( isset( $row['object'] ) ) {
                    $object = $row['object'];
                    $field  = $row['field'];
                } else {
                    // A row without an object (settings, taxes, categories): "<group> › <field>".
                    $parts  = explode( ' › ', $row['label'], 2 );
                    $object = count( $parts ) === 2
                        ? Format::object( 'label:' . $parts[0], $parts[0], '', false )
                        : Format::object( 'label:', '', '', false );
                    $field  = count( $parts ) === 2 ? $parts[1] : $row['label'];
                }
                $key = $object['key'];
                if ( ! isset( $groups[ $key ] ) ) {
                    $groups[ $key ] = array( 'object' => $object, 'fields' => array() );
                } else {
                    // The latest name (a rename later in the card); "new" once any row says so.
                    $groups[ $key ]['object']['title'] = $object['title'];
                    $groups[ $key ]['object']['new']   = $groups[ $key ]['object']['new'] || $object['new'];
                }
                $fields = &$groups[ $key ]['fields'];
                $day    = isset( $row['day'] ) ? $row['day'] : null;
                $item   = isset( $row['item'] ) ? $row['item'] : null;
                $id     = $field . ( $item !== null ? "\n" . $item : '' );
                if ( ! isset( $fields[ $id ] ) ) {
                    $fields[ $id ] = array(
                        'field' => $field,
                        'item' => $item,
                        'name' => ! empty( $row['name'] ),
                        'days' => $day !== null ? array() : null,
                        'before' => $row['before'],
                        'after' => $row['after'],
                        'link' => isset( $row['link'] ) ? $row['link'] : null,
                    );
                }
                if ( $day !== null ) {
                    if ( ! isset( $fields[ $id ]['days'][ $day ] ) ) {
                        $fields[ $id ]['days'][ $day ] = array( 'before' => $row['before'], 'after' => $row['after'] );
                    }
                    $fields[ $id ]['days'][ $day ]['after'] = $row['after'];
                } else {
                    $fields[ $id ]['after'] = $row['after'];
                }
                if ( ! empty( $row['link'] ) ) {
                    $fields[ $id ]['link'] = $row['link'];
                }
                unset( $fields );
            }
        }

        $out = array();
        foreach ( $groups as $group ) {
            $object = $group['object'];
            $new    = $object['new'];
            $rows   = array();
            $short  = array();
            $lists  = array();
            foreach ( $group['fields'] as $f ) {
                // The field without its place in the interface: "General › Price" → "Price".
                $parts = explode( ' › ', $f['field'] );
                $label = end( $parts );
                if ( $f['days'] !== null ) {
                    $rows = array_merge( $rows, self::dayRows( $label, $f['days'], $new, $f['link'] ) );
                } elseif ( $new ) {
                    if ( $f['name'] || $f['after'] === '—' || $f['after'] === '' ) {
                        continue;
                    }
                    if ( $f['item'] !== null ) {
                        // "Services › Haircut: $30" of a new object → "Services: Haircut — $30; …".
                        $lists[ $label ][] = $f['item'] . ' — ' . $f['after'];
                    } elseif ( self::length( $f['after'] ) <= 40 && strpos( $f['after'], ';' ) === false ) {
                        $short[] = $f['after'];
                    } else {
                        $rows[] = array( 'label' => $label, 'before' => null, 'after' => $f['after'], 'link' => $f['link'] );
                    }
                } elseif ( $f['before'] !== $f['after'] ) {
                    $rows[] = array(
                        'label' => $label . ( $f['item'] !== null ? ' › ' . $f['item'] : '' ),
                        'before' => $f['before'],
                        'after' => $f['after'],
                        'link' => $f['link'],
                    );
                }
            }
            foreach ( $lists as $label => $list ) {
                $rows[] = array( 'label' => $label, 'before' => null, 'after' => implode( '; ', $list ), 'link' => null );
            }
            if ( $short ) {
                array_unshift( $rows, array( 'label' => '', 'before' => null, 'after' => implode( ' · ', $short ), 'link' => null ) );
            }
            if ( $new && $object['kind'] !== '' ) {
                $title = $object['kind'] . ' «' . $object['title'] . '»';
            } else {
                $title = $object['title'];
            }
            if ( $rows || $new ) {
                $out[] = array( 'title' => $title, 'rows' => $rows );
            }
        }

        return $out;
    }

    /**
     * Rows of a weekly schedule field: for a new object the whole week, days with the same hours
     * joined ("Mon, Wed, Fri 9:00 am – 6:00 pm; Tue, Thu OFF"); for an existing one the changed
     * days, joined by the same change.
     *
     * @param string      $field
     * @param array       $days day key => array( before, after )
     * @param bool        $new
     * @param string|null $link
     * @return array
     */
    protected static function dayRows( $field, array $days, $new, $link )
    {
        $joined = array();
        foreach ( StaffData::week() as $position => $key ) {
            if ( ! isset( $days[ $key ] ) ) {
                continue;
            }
            $day = $days[ $key ];
            if ( ! $new && $day['before'] === $day['after'] ) {
                continue;
            }
            $id = $new ? $day['after'] : $day['before'] . "\n" . $day['after'];
            if ( ! isset( $joined[ $id ] ) ) {
                $joined[ $id ] = array( 'days' => array(), 'before' => $day['before'], 'after' => $day['after'] );
            }
            $joined[ $id ]['days'][ $position ] = $key;
        }
        if ( $new ) {
            $parts = array();
            foreach ( $joined as $j ) {
                $parts[] = self::daysText( $j['days'] ) . ' ' . $j['after'];
            }

            return $parts ? array( array( 'label' => $field, 'before' => null, 'after' => implode( '; ', $parts ), 'link' => $link ) ) : array();
        }
        $rows = array();
        foreach ( $joined as $j ) {
            $rows[] = array( 'label' => $field . ' › ' . self::daysText( $j['days'] ), 'before' => $j['before'], 'after' => $j['after'], 'link' => $link );
        }

        return $rows;
    }

    /**
     * Short day names, three and more days in a row of the week as a range: "Mon–Fri, Sun".
     *
     * @param array $days position in the site's week => day key, in order
     * @return string
     */
    protected static function daysText( array $days )
    {
        $runs = array();
        $prev = null;
        foreach ( $days as $position => $key ) {
            if ( $prev !== null && $position === $prev + 1 ) {
                $runs[ count( $runs ) - 1 ][] = $key;
            } else {
                $runs[] = array( $key );
            }
            $prev = $position;
        }
        $parts = array();
        foreach ( $runs as $run ) {
            if ( count( $run ) >= 3 ) {
                $parts[] = StaffData::dayAbbrev( $run[0] ) . '–' . StaffData::dayAbbrev( end( $run ) );
            } else {
                foreach ( $run as $key ) {
                    $parts[] = StaffData::dayAbbrev( $key );
                }
            }
        }

        return implode( ', ', $parts );
    }

    /**
     * The grouped card as text, for the model: what the owner sees.
     *
     * @param array $groups see groups()
     * @return string[]
     */
    public static function groupsText( array $groups )
    {
        $lines = array();
        foreach ( $groups as $group ) {
            if ( $group['title'] !== '' ) {
                $lines[] = $group['title'];
            }
            foreach ( $group['rows'] as $row ) {
                $value   = $row['before'] === null ? $row['after'] : $row['before'] . ' → ' . $row['after'];
                $lines[] = '  ' . ( $row['label'] !== '' ? $row['label'] . ': ' : '' ) . $value;
            }
        }

        return $lines;
    }

    /**
     * @param string $text
     * @return int
     */
    protected static function length( $text )
    {
        return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
    }

    /**
     * When an error names a "$N" reference, how the references of this proposal are numbered:
     * N is the position of the create_* item in changes, not a count of created objects.
     *
     * @param string[] $errors
     * @param array    $created item number => entity
     * @return string|null
     */
    protected static function refHint( array $errors, array $created )
    {
        if ( ! preg_match( '/"\$\d+"/', implode( "\n", $errors ) ) ) {
            return null;
        }
        $refs = array();
        foreach ( $created as $item => $entity ) {
            $refs[] = sprintf( 'item %d creates a %s → "$%d"', $item, $entity === 'staff' ? 'staff member' : str_replace( '_', ' ', $entity ), $item );
        }

        return 'note: "$N" is the number of the item in changes that creates the object, counted over all items (from 1), not over created objects only. '
            . ( $refs
                ? 'In this proposal: ' . implode( '; ', $refs ) . '.'
                : 'No item of this proposal creates an object.' );
    }

    /**
     * Apply a checked proposal — checked again first: data may have changed since the card was
     * shown. "$N" in args becomes the id of the object item N created. Returns the snapshot for
     * undo, or errors.
     *
     * @param array      $changes
     * @param array|null $shown items of the card the owner applies: when "before" of a row differs
     *                          from the data now, the card is stale (stale_preview) — the owner
     *                          would apply something other than what they saw
     * @return array array( 'errors' => string[], 'snapshot' => array, 'notices' => string[] )
     */
    public static function apply( array $changes, $shown = null )
    {
        $changes = array_values( $changes );
        $check   = self::check( $changes );
        if ( $check['errors'] ) {
            return array( 'errors' => $check['errors'], 'snapshot' => array() );
        }
        if ( is_array( $shown ) && self::befores( $shown ) !== self::befores( $check['items'] ) ) {
            return array( 'errors' => array( 'stale_preview: the data changed after the card was shown' ), 'snapshot' => array() );
        }
        $ids      = array();
        $snapshot = array();
        $notices  = array();
        foreach ( $changes as $i => $item ) {
            $args   = self::resolve( $item['args'], $ids );
            $result = self::action( $item['action'] )->apply( $args );
            // An action that saves outside the site (Cloud) can be refused: nothing of the item
            // is applied, and the items before it are undone.
            if ( isset( $result['error'] ) ) {
                foreach ( array_reverse( $snapshot ) as $done ) {
                    self::action( $done['action'] )->undo( $done['snapshot'] );
                }

                return array( 'errors' => array( $result['error'] ), 'snapshot' => array() );
            }
            if ( isset( $result['created_id'] ) ) {
                $ids[ $i + 1 ] = $result['created_id'];
            }
            // Errors a handler reported while the item was saved anyway (e.g. a calendar refused
            // change notifications): the owner and the model should know.
            if ( ! empty( $result['notices'] ) ) {
                foreach ( $result['notices'] as $notice ) {
                    $notices[] = $notice;
                }
            }
            $snapshot[] = array( 'action' => $item['action'], 'snapshot' => $result );
        }

        return array( 'errors' => array(), 'snapshot' => $snapshot, 'notices' => array_values( array_unique( $notices ) ) );
    }

    /**
     * Undo an applied proposal, last item first.
     *
     * @param array $snapshot
     */
    public static function undo( array $snapshot )
    {
        foreach ( array_reverse( $snapshot ) as $item ) {
            $action = self::action( $item['action'] );
            if ( $action ) {
                $action->undo( $item['snapshot'] );
            }
        }
    }

    /**
     * "Before" values of the card's rows, to compare a shown card with the data now.
     *
     * @param array $items
     * @return array
     */
    protected static function befores( array $items )
    {
        $out = array();
        foreach ( $items as $item ) {
            foreach ( isset( $item['rows'] ) ? $item['rows'] : array() as $row ) {
                $out[] = isset( $row['label'] ) && array_key_exists( 'before', $row ) ? $row['label'] . '=' . $row['before'] : '';
            }
        }

        return $out;
    }

    /**
     * Replace "$N" strings with ids of created objects.
     *
     * @param mixed $value
     * @param array $ids
     * @return mixed
     */
    protected static function resolve( $value, array $ids )
    {
        if ( is_array( $value ) ) {
            foreach ( $value as $k => $v ) {
                $value[ $k ] = self::resolve( $v, $ids );
            }

            return $value;
        }
        if ( is_string( $value ) && preg_match( '/^\$(\d+)$/', $value, $m ) && isset( $ids[ (int) $m[1] ] ) ) {
            return $ids[ (int) $m[1] ];
        }

        return $value;
    }
}
