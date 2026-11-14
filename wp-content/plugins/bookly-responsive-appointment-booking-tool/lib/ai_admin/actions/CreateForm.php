<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Backend\Modules\Appearance\ModernAppearance;
use Bookly\Lib;
use Bookly\Lib\AiAdmin\Format;
use Bookly\Lib\AiAdmin\Forms\FormAction;
use Bookly\Lib\AiAdmin\Forms\FormData;
use Bookly\Lib\AiAdmin\State;

/**
 * create_form — a new form of Appearance, as the tile "Add new form" and the first Save of the
 * editor (assets/ai-setup/actions/create_form.yaml). A booking form starts with the defaults of
 * its kind; an AI assistant form with a copy of the settings of the first AI form, or with the
 * defaults of the chat when there is none. The slug is made from the name by the first save.
 */
class CreateForm extends FormAction implements ActionInterface
{
    const DEFAULT_NAME = 'My form';

    protected function name()
    {
        return 'create_form';
    }

    /**
     * @inheritDoc
     */
    public function validate( array $args, State $state )
    {
        if ( $error = $this->forbidden() ) {
            return array( $error );
        }
        $errors = array();
        foreach ( array_keys( $args ) as $key ) {
            if ( in_array( $key, array( 'slug', 'token' ), true ) ) {
                $errors[] = 'slug_generated: the slug is made from the name, it is not an argument';
            } elseif ( ! in_array( $key, array( 'type', 'name' ), true ) ) {
                $errors[] = 'unknown_argument: ' . $key;
            }
        }
        $type = isset( $args['type'] ) && is_string( $args['type'] ) ? $args['type'] : '';
        $kinds = array_merge( FormData::bookingKinds(), array( Lib\Entities\Form::TYPE_AI_ASSISTANT ) );
        if ( ! in_array( $type, $kinds, true ) || ! in_array( $type, Lib\Entities\Form::getTypes(), true ) ) {
            $errors[] = 'form_kind_not_supported: type is one of ' . implode( ', ', $kinds ) . ' (checkout and events forms are made in their own areas)';
        } elseif ( $type !== Lib\Entities\Form::TYPE_AI_ASSISTANT && ! Lib\Config::proActive() ) {
            $errors[] = 'pro_inactive: modern booking forms need Bookly Pro; only an ai-assistant-form can be created';
        }
        if ( array_key_exists( 'name', $args ) ) {
            if ( ! is_string( $args['name'] ) || trim( $args['name'] ) === '' ) {
                $errors[] = 'name_length: the name is empty';
            } elseif ( mb_strlen( $args['name'] ) > 255 ) {
                $errors[] = 'name_length: at most 255 characters';
            }
        }
        if ( ! $errors ) {
            $this->record( $args, $state );
        }

        return $errors;
    }

    /**
     * Record the new form in the state; returns its "$N".
     *
     * @param array $args
     * @param State $state
     * @return string
     */
    protected function record( array $args, State $state )
    {
        $type = $args['type'];
        $name = isset( $args['name'] ) ? $args['name'] : self::DEFAULT_NAME;
        if ( $type === Lib\Entities\Form::TYPE_AI_ASSISTANT ) {
            $first = self::firstAi( $state );
            $source = $first === null ? null : self::current( $state, $first );
            $settings = FormData::full( $type, $source ? $source['settings'] : array() );
        } else {
            $settings = ModernAppearance::prepareAppearanceSettings( $type, null );
        }

        return self::recordNew( $state, $type, $name, $settings, '' );
    }

    /**
     * @inheritDoc
     */
    public function preview( array $args, State $state )
    {
        $type = $args['type'];
        $first = $type === Lib\Entities\Form::TYPE_AI_ASSISTANT ? self::firstAi( $state ) : null;
        $source = $first === null ? null : self::current( $state, $first );
        $ref = $this->record( $args, $state );
        $form = self::current( $state, $ref );
        $object = self::cardObject( $state, $ref, true );
        $kind = FormData::kindTitle( $type );
        $slug = self::slugText( $form );

        $rows = array(
            array( 'label' => Format::rowLabel( $object, self::label( 'field:form.name', 'Form title' ), array( 'name' => true ) ), 'before' => '—', 'after' => $form['name'] ),
            array( 'label' => Format::rowLabel( $object, self::label( 'field:form.token', 'Slug' ) ), 'before' => '—', 'after' => $form['numbered']
                ? sprintf( __( '%1$s (the slug %2$s is used by another form)', 'bookly-responsive-appointment-booking-tool' ), $slug, $form['slug'] )
                : $slug ),
            array( 'label' => Format::rowLabel( $object, __( 'Shortcode', 'bookly-responsive-appointment-booking-tool' ) ), 'before' => '—', 'after' => FormData::shortcode( $type, $slug ) ),
            array( 'label' => Format::rowLabel( $object, __( 'Pages', 'bookly-responsive-appointment-booking-tool' ) ), 'before' => '—', 'after' => sprintf(
                __( 'The form is not on any page: put this shortcode on a page (the block "Bookly - %s" with this form chosen, the editor button or the shortcode itself). Bookly does not edit pages.', 'bookly-responsive-appointment-booking-tool' ),
                $kind
            ) ),
        );
        $warnings = array();
        if ( $type === Lib\Entities\Form::TYPE_AI_ASSISTANT ) {
            if ( $source ) {
                $rows[] = array( 'label' => Format::rowLabel( $object, __( 'Starts as', 'bookly-responsive-appointment-booking-tool' ) ), 'before' => '—', 'after' => sprintf(
                    __( 'a copy of the form «%s» (the first AI form): the owner\'s text, colours, display mode, avatars and chat texts', 'bookly-responsive-appointment-booking-tool' ),
                    $source['name']
                ) );
                foreach ( array( 'assistant_knowledge' => 'Assistant knowledge', 'assistant_instructions' => 'Assistant instructions' ) as $key => $label ) {
                    $rows[] = array(
                        'label' => Format::rowLabel( $object, self::label( 'field:form.settings.' . $key, $label ) ),
                        'before' => '—',
                        'after' => self::text( isset( $form['settings'][ $key ] ) ? $form['settings'][ $key ] : '' ),
                    );
                }
                $rows[] = array( 'label' => Format::rowLabel( $object, __( 'Payment texts', 'bookly-responsive-appointment-booking-tool' ) ), 'before' => '—', 'after' => sprintf(
                    __( 'The chat reads the payment button captions and the messages after paying from the first AI form «%s» only; the copies in this form do nothing.', 'bookly-responsive-appointment-booking-tool' ),
                    $source['name']
                ) );
            } else {
                $rows[] = array( 'label' => Format::rowLabel( $object, __( 'Starts as', 'bookly-responsive-appointment-booking-tool' ) ), 'before' => '—', 'after' =>
                    __( 'the chat\'s defaults; this will be the first AI form — its payment captions and messages after paying are used by every AI chat of the site', 'bookly-responsive-appointment-booking-tool' ),
                );
            }
            if ( ! Lib\Cloud\API::getInstance()->account->productActive( Lib\Cloud\Account::PRODUCT_AI ) ) {
                $rows[] = array( 'label' => Format::rowLabel( $object, __( 'AI product', 'bookly-responsive-appointment-booking-tool' ) ), 'before' => '—', 'after' =>
                    __( 'The chat shows nothing on pages until the AI product is turned on in Bookly Cloud → Products (the owner\'s step).', 'bookly-responsive-appointment-booking-tool' ),
                );
            }
        } else {
            $rows[] = array( 'label' => Format::rowLabel( $object, __( 'Starts as', 'bookly-responsive-appointment-booking-tool' ) ), 'before' => '—', 'after' =>
                __( 'the default steps, look and texts of this kind; the texts are in the site language of now', 'bookly-responsive-appointment-booking-tool' ),
            );
        }
        $warnings[] = __( 'Existing forms and pages are not changed; the classic [bookly-form], the modern forms and the AI chat are separate.', 'bookly-responsive-appointment-booking-tool' );

        return array( 'rows' => $rows, 'warnings' => $warnings );
    }

    /**
     * @inheritDoc
     */
    public function apply( array $args )
    {
        $type = $args['type'];
        $name = isset( $args['name'] ) ? $args['name'] : self::DEFAULT_NAME;
        $settings = $type === Lib\Entities\Form::TYPE_AI_ASSISTANT
            ? FormData::aiCopy()
            : ModernAppearance::prepareAppearanceSettings( $type, null );
        unset( $settings['custom_css'], $settings['token'] );

        $form = new Lib\Entities\Form();
        $form
            ->setType( $type )
            ->setName( $name )
            ->setCustomCss( '' )
            ->setSettings( json_encode( $settings ) )
            ->save();
        if ( isset( $settings['l10n'] ) && is_array( $settings['l10n'] ) ) {
            ModernAppearance::registerWpmlStrings( $settings['l10n'] );
        }

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
