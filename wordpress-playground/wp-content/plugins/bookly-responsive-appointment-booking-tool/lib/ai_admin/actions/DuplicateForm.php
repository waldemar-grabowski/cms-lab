<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Format;
use Bookly\Lib\AiAdmin\Forms\FormAction;
use Bookly\Lib\AiAdmin\Forms\FormData;
use Bookly\Lib\AiAdmin\State;

/**
 * duplicate_form — a copy of a modern booking form under the name "<name> clone" with its own
 * slug, as Clone form in the list of forms (assets/ai-setup/actions/duplicate_form.yaml).
 * Settings, texts, theme and custom CSS are copied as they are.
 */
class DuplicateForm extends FormAction implements ActionInterface
{
    protected function name()
    {
        return 'duplicate_form';
    }

    /**
     * @inheritDoc
     */
    public function validate( array $args, State $state )
    {
        if ( $error = $this->forbidden() ) {
            return array( $error );
        }
        foreach ( array_keys( $args ) as $key ) {
            if ( $key !== 'form_id' ) {
                return array( 'unknown_argument: ' . $key );
            }
        }
        if ( ! Lib\Config::proActive() ) {
            return array( 'pro_inactive: modern booking forms need Bookly Pro' );
        }
        $id = isset( $args['form_id'] ) ? $args['form_id'] : null;
        if ( ! ( is_int( $id ) || ( is_string( $id ) && ctype_digit( $id ) ) ) || ! self::exists( (int) $id, $state ) ) {
            return array( 'form_not_found: ' . wp_json_encode( $id ) );
        }
        $form = self::current( $state, (int) $id );
        if ( ! in_array( $form['type'], FormData::bookingKinds(), true ) ) {
            return array( 'form_kind_not_supported: a ' . $form['type'] . ' is not copied by this action; it copies ' . implode( ', ', FormData::bookingKinds() ) );
        }
        if ( mb_strlen( $form['name'] . ' clone' ) > 255 ) {
            return array( 'name_length: the copy would be named "<name> clone", longer than 255 characters' );
        }
        $this->record( (int) $id, $state );

        return array();
    }

    /**
     * Record the copy in the state; returns its "$N".
     *
     * @param int   $id
     * @param State $state
     * @return string
     */
    protected function record( $id, State $state )
    {
        $form = self::current( $state, $id );

        return self::recordNew( $state, $form['type'], $form['name'] . ' clone', $form['settings'], $form['custom_css'] );
    }

    /**
     * @inheritDoc
     */
    public function preview( array $args, State $state )
    {
        $id = (int) $args['form_id'];
        $original = self::current( $state, $id );
        $ref = $this->record( $id, $state );
        $copy = self::current( $state, $ref );
        $object = self::cardObject( $state, $ref, true );
        $slug = self::slugText( $copy );
        $theme = FormData::themeName( isset( $copy['settings']['theme'] ) ? $copy['settings']['theme'] : '' );

        $rows = array(
            array( 'label' => Format::rowLabel( $object, self::label( 'field:form.name', 'Form title' ), array( 'name' => true ) ), 'before' => '—', 'after' => $copy['name'] ),
            array( 'label' => Format::rowLabel( $object, __( 'Copy of', 'bookly-responsive-appointment-booking-tool' ) ), 'before' => '—', 'after' => sprintf( '«%s» %s', $original['name'], FormData::shortcode( $original['type'], $original['slug'] ) ) ),
            array( 'label' => Format::rowLabel( $object, self::label( 'field:form.token', 'Slug' ) ), 'before' => '—', 'after' => $copy['numbered']
                ? sprintf( __( '%1$s (the slug %2$s is used by another form)', 'bookly-responsive-appointment-booking-tool' ), $slug, $copy['slug'] )
                : $slug ),
            array( 'label' => Format::rowLabel( $object, __( 'Shortcode', 'bookly-responsive-appointment-booking-tool' ) ), 'before' => '—', 'after' => FormData::shortcode( $copy['type'], $slug ) ),
            array( 'label' => Format::rowLabel( $object, __( 'Copied', 'bookly-responsive-appointment-booking-tool' ) ), 'before' => '—', 'after' => sprintf(
                __( 'all settings and texts of the form, its custom CSS and its theme (%s); the copy is on no page — the original\'s shortcode keeps showing the original', 'bookly-responsive-appointment-booking-tool' ),
                $theme === '' ? __( 'Default', 'bookly-responsive-appointment-booking-tool' ) : $theme
            ) ),
        );
        $warnings = array(
            __( 'The original form and the pages that use it are not changed; WPML translations of the original\'s texts apply to the copy as long as the texts are identical.', 'bookly-responsive-appointment-booking-tool' ),
        );

        return array( 'rows' => $rows, 'warnings' => $warnings );
    }

    /**
     * @inheritDoc
     */
    public function apply( array $args )
    {
        $form = Lib\Entities\Form::find( $args['form_id'], false );
        $form
            ->setId( null )
            ->setName( $form->getName() . ' clone' )
            ->setToken( null )
            ->save();

        return array(
            'created_id' => (int) $form->getId(),
            'token' => $form->getToken(),
            'after' => self::rowState( $form->getId() ),
        );
    }

    /**
     * @inheritDoc
     */
    public function undo( array $snapshot )
    {
        self::deleteCreated( $snapshot );
    }
}
