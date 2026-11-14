<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Format;
use Bookly\Lib\AiAdmin\Forms\FormAction;
use Bookly\Lib\AiAdmin\Forms\FormData;
use Bookly\Lib\AiAdmin\State;

/**
 * set_ai_form — change the given settings of one AI assistant form (assets/ai-setup/actions/set_ai_form.yaml).
 * The given keys are merged into the saved settings (texts key by key); everything else of the
 * row — other keys, slug, type, custom CSS — stays as it is, and no defaults are written.
 */
class SetAiForm extends FormAction implements ActionInterface
{
    /** Texts edited in a one-line field of the editor. */
    const SINGLE_LINE = 'title,onlineStatus,greeting,placeholder,openChat,closeChat,resetChat,send,paymentTitle,paymentTotal,paymentDueNow';
    /** Payment result texts the chat reads from the first AI form; the codes work only in the first three. */
    const RESULT_TEXTS = 'paymentCompleted,paymentPending,paymentAwaiting,paymentFailed,paymentSlotTaken';
    const CODE_TEXTS = 'paymentCompleted,paymentPending,paymentAwaiting';
    const CODES = '{service_name},{staff_name},{appointment_date},{appointment_time}';

    protected function name()
    {
        return 'set_ai_form';
    }

    /**
     * Keys of the action with their kind.
     *
     * @return array
     */
    protected static function keys()
    {
        return array(
            'name' => 'name',
            'main_color' => 'color',
            'question_bubble_color' => 'color',
            'answer_bubble_color' => 'color',
            'display_mode' => 'display_mode',
            'show_avatars' => 'bool',
            'assistant_knowledge' => 'text',
            'assistant_instructions' => 'text',
            'l10n' => 'l10n',
        );
    }

    /**
     * @inheritDoc
     */
    public function validate( array $args, State $state )
    {
        if ( $error = $this->forbidden() ) {
            return array( $error );
        }
        $keys = self::keys();
        $errors = array();
        foreach ( array_keys( $args ) as $key ) {
            if ( $key === 'form_id' || isset( $keys[ $key ] ) ) {
                continue;
            }
            if ( in_array( $key, array( 'slug', 'token' ), true ) ) {
                $errors[] = 'slug_not_editable: the slug is changed only by the owner in the form\'s Settings';
            } elseif ( $key === 'custom_css' ) {
                $errors[] = 'custom_css_not_editable: custom CSS is changed only by the owner';
            } else {
                $errors[] = 'unknown_argument: ' . $key;
            }
        }
        $id = isset( $args['form_id'] ) ? self::id( $args['form_id'] ) : 0;
        if ( ! self::exists( $id, $state ) ) {
            return array_merge( $errors, array( 'form_not_found: ' . wp_json_encode( isset( $args['form_id'] ) ? $args['form_id'] : null ) ) );
        }
        $form = self::current( $state, $id );
        if ( $form['type'] !== Lib\Entities\Form::TYPE_AI_ASSISTANT ) {
            return array_merge( $errors, array( 'form_kind_not_ai: the form is a ' . $form['type'] . '; booking forms are changed by set_form' ) );
        }
        $full = FormData::full( $form['type'], $form['settings'] );
        $changed = false;
        foreach ( $args as $key => $value ) {
            if ( ! isset( $keys[ $key ] ) ) {
                continue;
            }
            switch ( $keys[ $key ] ) {
                case 'name':
                    if ( ! is_string( $value ) || trim( $value ) === '' ) {
                        $errors[] = 'name_length: the name is empty';
                    } elseif ( mb_strlen( $value ) > 255 ) {
                        $errors[] = 'name_length: at most 255 characters';
                    } elseif ( $value !== $form['name'] ) {
                        $changed = true;
                    }
                    break;
                case 'color':
                    if ( ! is_string( $value ) || ! preg_match( '/^#[0-9a-fA-F]{6}$/', $value ) ) {
                        $errors[] = 'color_hex: ' . $key . ' is "#rrggbb"';
                    } elseif ( $value !== $full[ $key ] ) {
                        $changed = true;
                    }
                    break;
                case 'display_mode':
                    if ( ! in_array( $value, array( 'floating', 'embedded' ), true ) ) {
                        $errors[] = 'display_mode_values: display_mode is floating or embedded';
                    } elseif ( $value !== $full[ $key ] ) {
                        $changed = true;
                    }
                    break;
                case 'bool':
                    if ( ! is_bool( $value ) ) {
                        $errors[] = 'value_not_allowed: ' . $key . ' is true or false';
                    } elseif ( $value !== (bool) $full[ $key ] ) {
                        $changed = true;
                    }
                    break;
                case 'text':
                    if ( ! is_string( $value ) ) {
                        $errors[] = 'value_not_allowed: ' . $key . ' is a text';
                    } elseif ( $value !== (string) $full[ $key ] ) {
                        $changed = true;
                    }
                    break;
                case 'l10n':
                    if ( ! is_array( $value ) || ! $value ) {
                        $errors[] = 'text_key_unknown: l10n is an object of texts by key';
                        break;
                    }
                    foreach ( $value as $text_key => $text ) {
                        $error = $this->checkText( (string) $text_key, $text, $full['l10n'] );
                        if ( $error ) {
                            $errors[] = $error;
                        } elseif ( $text !== $full['l10n'][ $text_key ] ) {
                            $changed = true;
                        }
                    }
                    break;
            }
        }
        if ( ! $errors && ! $changed ) {
            $errors[] = 'nothing_to_change: every given key already has this value';
        }
        if ( ! $errors ) {
            $this->record( $id, $args, $state );
        }

        return $errors;
    }

    /**
     * Error of one text of l10n, or null.
     *
     * @param string $key
     * @param mixed  $text
     * @param array  $l10n texts of the form
     * @return string|null
     */
    protected function checkText( $key, $text, array $l10n )
    {
        if ( ! array_key_exists( $key, $l10n ) || is_array( $l10n[ $key ] ) ) {
            return 'text_key_unknown: l10n.' . $key . ' is not a text of this form (list_forms with form_id shows them)';
        }
        if ( ! is_string( $text ) ) {
            return 'text_key_unknown: l10n.' . $key . ' is a string';
        }
        if ( in_array( $key, explode( ',', self::SINGLE_LINE ), true ) && preg_match( '/[\r\n]/', $text ) ) {
            return 'single_line_texts: l10n.' . $key . ' is one line';
        }
        if ( strpos( $key, 'payment_system_' ) === 0 && ! in_array( substr( $key, 15 ), FormData::editorPaymentSystems(), true ) ) {
            return 'payment_system_in_editor: the editor offers no button for the method ' . substr( $key, 15 );
        }
        if ( $key === 'paymentDueNow' && ! Lib\Config::depositPaymentsActive() ) {
            return 'due_now_needs_deposit: l10n.paymentDueNow needs the Deposit Payments add-on';
        }
        if ( ! in_array( $key, explode( ',', self::CODE_TEXTS ), true ) ) {
            foreach ( explode( ',', self::CODES ) as $code ) {
                if ( strpos( $text, $code ) !== false ) {
                    return 'codes_only_in_result_texts: ' . $code . ' works only in paymentCompleted, paymentPending and paymentAwaiting';
                }
            }
        }

        return null;
    }

    /**
     * Record the change in the state.
     *
     * @param int|string $id
     * @param array      $args
     * @param State      $state
     */
    protected function record( $id, array $args, State $state )
    {
        $form = self::current( $state, $id );
        $form['settings'] = self::merge( $form['settings'], $args );
        if ( isset( $args['name'] ) ) {
            $form['name'] = $args['name'];
        }
        self::setCurrent( $state, $id, $form );
    }

    /**
     * Saved settings with the given keys: values replaced, texts merged key by key.
     *
     * @param array $settings
     * @param array $args
     * @return array
     */
    protected static function merge( array $settings, array $args )
    {
        foreach ( self::keys() as $key => $kind ) {
            if ( $key === 'name' || ! array_key_exists( $key, $args ) ) {
                continue;
            }
            if ( $kind === 'l10n' ) {
                $l10n = isset( $settings['l10n'] ) && is_array( $settings['l10n'] ) ? $settings['l10n'] : array();
                $settings['l10n'] = array_merge( $l10n, $args['l10n'] );
            } else {
                $settings[ $key ] = $args[ $key ];
            }
        }

        return $settings;
    }

    /**
     * @param string|null $color
     * @return string
     */
    protected static function color( $color )
    {
        return $color === null || $color === '' ? __( 'as Main color', 'bookly-responsive-appointment-booking-tool' ) : (string) $color;
    }

    /**
     * @inheritDoc
     */
    public function preview( array $args, State $state )
    {
        $id = self::id( $args['form_id'] );
        $before = self::current( $state, $id );
        $full = FormData::full( $before['type'], $before['settings'] );
        $first = self::firstAi( $state );
        $is_first = (string) $first === (string) $id;
        $this->record( $id, $args, $state );
        // The card names the form as it is after the item.
        $object = self::cardObject( $state, $id );

        $rows = array();
        if ( isset( $args['name'] ) ) {
            $rows[] = array( 'label' => Format::rowLabel( $object, self::label( 'field:form.name', 'Form title' ), array( 'name' => true ) ), 'before' => $before['name'], 'after' => $args['name'] );
        }
        foreach ( array( 'main_color', 'question_bubble_color', 'answer_bubble_color' ) as $key ) {
            if ( isset( $args[ $key ] ) ) {
                $rows[] = array( 'label' => Format::rowLabel( $object, self::label( 'field:form.settings.' . $key, $key ) ), 'before' => self::color( $full[ $key ] ), 'after' => self::color( $args[ $key ] ) );
            }
        }
        foreach ( array( 'display_mode', 'show_avatars' ) as $key ) {
            if ( isset( $args[ $key ] ) ) {
                $rows[] = array(
                    'label' => Format::rowLabel( $object, self::label( 'field:form.settings.' . $key, $key ) ),
                    'before' => self::value( 'field:form.settings.' . $key, $full[ $key ] ),
                    'after' => self::value( 'field:form.settings.' . $key, $args[ $key ] ),
                );
            }
        }
        $warnings = array();
        if ( isset( $args['assistant_knowledge'] ) ) {
            $rows[] = array(
                'label' => Format::rowLabel( $object, self::label( 'field:form.settings.assistant_knowledge', 'Assistant knowledge' ) ),
                'before' => self::text( $full['assistant_knowledge'] ),
                'after' => self::text( $args['assistant_knowledge'] ),
            );
            $warnings[] = __( 'The chat takes this text as information about the business, not as instructions; it does not change prices, services or the booking rules, and it is one language for this form.', 'bookly-responsive-appointment-booking-tool' );
        }
        if ( isset( $args['assistant_instructions'] ) ) {
            $rows[] = array(
                'label' => Format::rowLabel( $object, self::label( 'field:form.settings.assistant_instructions', 'Assistant instructions' ) ),
                'before' => self::text( $full['assistant_instructions'] ),
                'after' => self::text( $args['assistant_instructions'] ),
            );
            $warnings[] = __( 'The chat follows these instructions within the rules of Bookly Cloud: they do not change prices, free times, payment or what the chat can do, and they are one language for this form.', 'bookly-responsive-appointment-booking-tool' );
        }
        $payment_changed = false;
        if ( isset( $args['l10n'] ) ) {
            foreach ( $args['l10n'] as $key => $text ) {
                if ( $text === $full['l10n'][ $key ] ) {
                    continue;
                }
                $rows[] = array(
                    'label' => Format::rowLabel( $object, sprintf( __( 'Text «%s»', 'bookly-responsive-appointment-booking-tool' ), $key ) ),
                    'before' => self::text( $full['l10n'][ $key ] ),
                    'after' => self::text( $text ),
                );
                if ( strpos( $key, 'payment_system_' ) === 0 || in_array( $key, explode( ',', self::RESULT_TEXTS ), true ) ) {
                    $payment_changed = true;
                }
            }
            if ( defined( 'WPML_ST_VERSION' ) ) {
                $warnings[] = __( 'Translations of these texts in other languages are lost — WPML ties a translation to the exact text, so each changed text must be translated again; identical texts of other forms are unaffected.', 'bookly-responsive-appointment-booking-tool' );
            }
        }
        if ( $payment_changed ) {
            if ( $is_first ) {
                $note = __( 'This is the first AI form: the chat uses this change.', 'bookly-responsive-appointment-booking-tool' );
            } else {
                $first_form = $first === null ? null : self::current( $state, $first );
                $note = sprintf(
                    __( 'The chat reads these captions and messages from the first AI form «%s» — this change will NOT show in the chat; change them in that form.', 'bookly-responsive-appointment-booking-tool' ),
                    $first_form ? $first_form['name'] : ''
                );
            }
            $rows[] = array( 'label' => Format::rowLabel( $object, __( 'Payment texts', 'bookly-responsive-appointment-booking-tool' ) ), 'before' => null, 'after' => $note );
        }
        if ( isset( $args['main_color'] ) && ! array_key_exists( 'question_bubble_color', $args )
            && isset( $before['settings']['question_bubble_color'] ) && $before['settings']['question_bubble_color'] !== '' ) {
            $rows[] = array( 'label' => Format::rowLabel( $object, __( 'Client\'s bubble', 'bookly-responsive-appointment-booking-tool' ) ), 'before' => null, 'after' => sprintf(
                __( 'The client\'s bubble keeps its colour %s — set question_bubble_color too to change it.', 'bookly-responsive-appointment-booking-tool' ),
                $before['settings']['question_bubble_color']
            ) );
        }
        $warnings[] = $is_first
            ? __( 'This is the first AI form of the site.', 'bookly-responsive-appointment-booking-tool' )
            : __( 'This is not the first AI form of the site.', 'bookly-responsive-appointment-booking-tool' );
        if ( ! Lib\Cloud\API::getInstance()->account->productActive( Lib\Cloud\Account::PRODUCT_AI ) ) {
            $warnings[] = __( 'The chat is not shown on pages until the AI product is turned on (Cloud → Products).', 'bookly-responsive-appointment-booking-tool' );
        }
        $warnings[] = __( 'Pages with the shortcode show the change at once; the Admin assistant and the classic [bookly-form] do not read these settings; other AI forms are not changed.', 'bookly-responsive-appointment-booking-tool' );

        return array( 'rows' => $rows, 'warnings' => $warnings );
    }

    /**
     * @inheritDoc
     */
    public function apply( array $args )
    {
        $row = FormData::row( $args['form_id'] );
        $settings = self::merge( FormData::saved( $row ), $args );

        return self::save( (int) $row['id'], isset( $args['name'] ) ? $args['name'] : $row['name'], $settings, isset( $args['l10n'] ) ? $args['l10n'] : array() );
    }

    /**
     * @inheritDoc
     */
    public function undo( array $snapshot )
    {
        self::restore( $snapshot );
    }
}
