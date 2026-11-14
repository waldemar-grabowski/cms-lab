<?php
namespace Bookly\Lib\AiAdmin\Forms;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Access;
use Bookly\Lib\AiAdmin\Format;
use Bookly\Lib\AiAdmin\Rules;
use Bookly\Lib\AiAdmin\State;

/**
 * Common part of the form actions (create_form, duplicate_form, set_form, set_ai_form): the form an
 * item refers to (an id or "$N" of a form created earlier in the same proposal) and its row after
 * the earlier items.
 *
 * A form lives in State under "form:<id>" as array( type, name, slug, numbered, settings,
 * custom_css ): settings as the row holds them (decoded), slug — the saved one or, for a form the
 * proposal creates, the one the first save will make (numbered: a number will be added to it).
 */
abstract class FormAction
{
    /**
     * Name of the action (assets/ai-setup/actions/<name>.yaml).
     *
     * @return string
     */
    abstract protected function name();

    /**
     * "forbidden" error when the current user may not run the action, or null.
     *
     * @return string|null
     */
    protected function forbidden()
    {
        $actions = Rules::actions();
        $roles = isset( $actions[ $this->name() ]['roles'] ) ? $actions[ $this->name() ]['roles'] : array( 'admin' );

        return Access::allowed( $roles ) ? null : 'forbidden: the current user is not a Bookly administrator';
    }

    /**
     * Normalised id: int for a saved form, "$N" as is.
     *
     * @param mixed $id
     * @return int|string
     */
    public static function id( $id )
    {
        return is_string( $id ) && $id !== '' && $id[0] === '$' ? $id : (int) $id;
    }

    /**
     * Whether a value is an id of a saved form or "$N" of a form created earlier.
     *
     * @param mixed $id
     * @param State $state
     * @return bool
     */
    public static function exists( $id, State $state )
    {
        if ( $state->isNew( $id ) === 'form' ) {
            return true;
        }
        if ( is_int( $id ) || ( is_string( $id ) && ctype_digit( $id ) ) ) {
            return self::current( $state, (int) $id ) !== null;
        }

        return false;
    }

    /**
     * The form after the earlier items, or null.
     *
     * @param State      $state
     * @param int|string $id
     * @return array|null array( type, name, slug, numbered, settings, custom_css )
     */
    public static function current( State $state, $id )
    {
        if ( $state->has( 'form:' . $id ) ) {
            return $state->get( 'form:' . $id );
        }
        if ( ! is_int( $id ) ) {
            return null;
        }
        $row = FormData::row( $id );
        if ( ! $row ) {
            return null;
        }

        return array(
            'type' => $row['type'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['token'],
            'numbered' => false,
            'settings' => FormData::saved( $row ),
            'custom_css' => (string) $row['custom_css'],
        );
    }

    /**
     * @param State      $state
     * @param int|string $id
     * @param array      $form
     */
    public static function setCurrent( State $state, $id, array $form )
    {
        $state->set( 'form:' . $id, $form );
    }

    /**
     * Record a form the current item creates; returns its "$N". The slug is the one the first save
     * makes from the name; when it is taken (by a saved form or an earlier new one) a number is added.
     *
     * @param State  $state
     * @param string $type
     * @param string $name
     * @param array  $settings
     * @param string $custom_css
     * @return string
     */
    public static function recordNew( State $state, $type, $name, array $settings, $custom_css )
    {
        $ref = '$' . $state->item();
        $state->created( 'form' );
        $slug = FormData::slugBase( $name );
        $planned = $state->has( 'list:new_forms' ) ? $state->get( 'list:new_forms' ) : array();
        $numbered = FormData::slugTaken( $slug );
        foreach ( $planned as $other ) {
            $form = $state->get( 'form:' . $other );
            if ( $form['slug'] === $slug ) {
                $numbered = true;
            }
        }
        self::setCurrent( $state, $ref, array(
            'type' => $type,
            'name' => $name,
            'slug' => $slug,
            'numbered' => $numbered,
            'settings' => $settings,
            'custom_css' => $custom_css,
        ) );
        $planned[] = $ref;
        $state->set( 'list:new_forms', $planned );

        return $ref;
    }

    /**
     * Id of the first AI assistant form after the earlier items: the saved one with the lowest id,
     * else the first created by the proposal.
     *
     * @param State $state
     * @return int|string|null
     */
    public static function firstAi( State $state )
    {
        $first = FormData::firstAiId();
        if ( $first ) {
            return $first;
        }
        foreach ( $state->has( 'list:new_forms' ) ? $state->get( 'list:new_forms' ) : array() as $ref ) {
            $form = $state->get( 'form:' . $ref );
            if ( $form['type'] === Lib\Entities\Form::TYPE_AI_ASSISTANT ) {
                return $ref;
            }
        }

        return null;
    }

    /**
     * Slug as the card shows it: "<slug>-<number>" when a number will be added.
     *
     * @param array $form
     * @return string
     */
    public static function slugText( array $form )
    {
        return $form['numbered'] ? $form['slug'] . '-<' . __( 'number', 'bookly-responsive-appointment-booking-tool' ) . '>' : $form['slug'];
    }

    /**
     * The form as an object of the card (see Format::object()).
     *
     * @param State      $state
     * @param int|string $id
     * @param bool       $new the current item creates it ($state->isNew() covers earlier items only)
     * @return array
     */
    public static function cardObject( State $state, $id, $new = false )
    {
        $form = self::current( $state, $id );
        $new = $new || $state->isNew( $id ) === 'form';
        $kind = FormData::kindTitle( $form['type'] );
        if ( $new ) {
            return Format::object( 'form:' . $id, $form['name'], sprintf( __( 'New form (%s)', 'bookly-responsive-appointment-booking-tool' ), $kind ), true );
        }

        return Format::object(
            'form:' . $id,
            sprintf( '%s «%s» %s', $kind, $form['name'], FormData::shortcode( $form['type'], $form['slug'] ) ),
            '',
            false
        );
    }

    /**
     * Label of a rule entry, e.g. field:form.settings.main_color.
     *
     * @param string $ref
     * @param string $fallback
     * @return string
     */
    public static function label( $ref, $fallback )
    {
        $entries = Rules::entries();

        return isset( $entries[ $ref ] ) ? Format::label( $entries[ $ref ], $fallback ) : $fallback;
    }

    /**
     * Value of a rule entry as the owner sees it.
     *
     * @param string $ref
     * @param mixed  $value
     * @return string
     */
    public static function value( $ref, $value )
    {
        $entries = Rules::entries();

        return Format::value( isset( $entries[ $ref ] ) ? $entries[ $ref ] : array(), $value );
    }

    /**
     * A text for the card: whole, "—" when empty.
     *
     * @param mixed $text
     * @return string
     */
    public static function text( $text )
    {
        return $text === null || $text === '' ? '—' : (string) $text;
    }

    /**
     * What undo compares: name, settings and custom CSS of the row now.
     *
     * @param int $id
     * @return array|null
     */
    public static function rowState( $id )
    {
        $row = FormData::row( $id );

        return $row ? array( 'name' => (string) $row['name'], 'settings' => (string) $row['settings'], 'custom_css' => (string) $row['custom_css'] ) : null;
    }

    /**
     * Delete a form the proposal created, unless it was changed after (by the owner); a change by
     * a later item of the same proposal is undone before.
     *
     * @param array $snapshot array( created_id, after )
     */
    public static function deleteCreated( array $snapshot )
    {
        if ( ! isset( $snapshot['created_id'] ) || self::rowState( $snapshot['created_id'] ) !== $snapshot['after'] ) {
            return;
        }
        $form = Lib\Entities\Form::find( $snapshot['created_id'], false );
        if ( $form ) {
            $form->delete();
        }
    }

    /**
     * Restore name and settings of a form changed by set_form / set_ai_form, unless the row changed
     * after the change.
     *
     * @param array $snapshot array( id, before, after )
     */
    public static function restore( array $snapshot )
    {
        if ( self::rowState( $snapshot['id'] ) !== $snapshot['after'] ) {
            return;
        }
        $form = Lib\Entities\Form::find( $snapshot['id'], false );
        $form->setName( $snapshot['before']['name'] )->setSettings( $snapshot['before']['settings'] )->save();
    }

    /**
     * Write name and settings of a form and register texts in WPML; returns the snapshot for
     * restore().
     *
     * @param int    $id
     * @param string $name
     * @param array  $settings
     * @param array  $texts texts to register (l10n or a part of it)
     * @return array
     */
    public static function save( $id, $name, array $settings, array $texts )
    {
        $before = self::rowState( $id );
        $form = Lib\Entities\Form::find( $id, false );
        $form->setName( $name )->setSettings( json_encode( $settings ) )->save();
        if ( $texts ) {
            \Bookly\Backend\Modules\Appearance\ModernAppearance::registerWpmlStrings( $texts );
        }

        return array( 'id' => (int) $id, 'before' => $before, 'after' => self::rowState( $id ) );
    }
}
