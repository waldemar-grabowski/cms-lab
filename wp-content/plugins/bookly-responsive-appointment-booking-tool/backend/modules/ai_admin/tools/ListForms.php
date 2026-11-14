<?php
namespace Bookly\Backend\Modules\AiAdmin\Tools;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Actions\SetForm;
use Bookly\Lib\AiAdmin\Forms\FormData;

/**
 * list_forms({type?, form_id?}) — the saved forms of Appearance in the shape the form actions take:
 * settings of a booking form are the keys set_form accepts for its kind on this site, of an AI
 * assistant form the keys of set_ai_form; with form_id also all texts (l10n). Without Pro only the
 * AI assistant forms are listed.
 */
class ListForms extends Tool
{
    const NAME = 'list_forms';

    /** Keys of an AI assistant form in settings. */
    const AI_KEYS = 'main_color,question_bubble_color,answer_bubble_color,display_mode,show_avatars,assistant_knowledge,assistant_instructions';

    public function getName()
    {
        return self::NAME;
    }

    protected function description()
    {
        return 'The saved forms of Appearance: id, type, name, slug, shortcode, editable, theme and settings in the shape of set_form / set_ai_form; with form_id also the texts (l10n).';
    }

    protected function parameters()
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'type' => array(
                    'type' => 'string',
                    'enum' => array( 'search-form', 'services-form', 'staff-form', 'tags-form', 'cancellation-confirmation', 'checkout-form', 'events-form', 'ai-assistant-form' ),
                    'description' => 'Only forms of this kind.',
                ),
                'form_id' => array( 'type' => 'integer', 'description' => 'One form by id - then the entry also has `l10n`.' ),
            ),
        );
    }

    public function execute( array $arguments )
    {
        $ai = Lib\Entities\Form::TYPE_AI_ASSISTANT;
        $pro = Lib\Config::proActive();
        $type = isset( $arguments['type'] ) ? (string) $arguments['type'] : null;
        if ( ! $pro && $type !== null && $type !== $ai ) {
            return self::json( array( 'unavailable' => 'pro' ) );
        }
        $query = Lib\Entities\Form::query()->sortBy( 'id' );
        if ( isset( $arguments['form_id'] ) ) {
            $query->where( 'id', (int) $arguments['form_id'] );
        }
        if ( $type !== null ) {
            $query->where( 'type', $type );
        }
        if ( ! $pro ) {
            $query->where( 'type', $ai );
        }
        $rows = $query->fetchArray();
        if ( isset( $arguments['form_id'] ) && ! $rows ) {
            return self::json( ! $pro ? array( 'unavailable' => 'pro' ) : array( 'error' => 'form not found' ) );
        }
        if ( ! $pro && ! $rows ) {
            return self::json( array( 'unavailable' => 'pro' ) );
        }
        $first_ai = FormData::firstAiId();
        $result = array();
        foreach ( $rows as $row ) {
            $result[] = self::entry( $row, $first_ai, isset( $arguments['form_id'] ) );
        }

        return self::json( $result );
    }

    /**
     * @param array    $row
     * @param int|null $first_ai
     * @param bool     $texts with l10n
     * @return array
     */
    protected static function entry( array $row, $first_ai, $texts )
    {
        $type = $row['type'];
        $full = FormData::full( $type, FormData::saved( $row ) );
        $ai = $type === Lib\Entities\Form::TYPE_AI_ASSISTANT;
        $editable = $ai || in_array( $type, FormData::bookingKinds(), true );
        $entry = array(
            'id' => (int) $row['id'],
            'type' => $type,
            'name' => (string) $row['name'],
            'slug' => (string) $row['token'],
            'shortcode' => FormData::shortcode( $type, $row['token'] ),
            'editable' => $editable,
            'theme' => isset( $full['theme'] ) && is_string( $full['theme'] ) ? $full['theme'] : '',
        );
        if ( $ai ) {
            $entry['payment_texts_used'] = (int) $row['id'] === $first_ai;
            $settings = array();
            foreach ( explode( ',', self::AI_KEYS ) as $key ) {
                $settings[ $key ] = array_key_exists( $key, $full ) ? $full[ $key ] : null;
            }
            $entry['settings'] = $settings;
        } elseif ( $editable ) {
            $entry['settings'] = self::bookingSettings( $type, $full );
        }
        if ( $texts && isset( $full['l10n'] ) ) {
            $entry['l10n'] = $full['l10n'];
        }

        return $entry;
    }

    /**
     * Settings of a booking form: the keys set_form accepts for its kind on this site.
     *
     * @param string $type
     * @param array  $full
     * @return array
     */
    protected static function bookingSettings( $type, array $full )
    {
        $settings = array();
        foreach ( SetForm::keysOf( $type ) as $key => $meta ) {
            if ( in_array( $key, array( 'name', 'l10n' ), true ) ) {
                continue;
            }
            $value = array_key_exists( $key, $full ) ? $full[ $key ] : null;
            if ( $meta[0] === 'list' ) {
                $value = $value === null && $key !== 'hidden_services' ? null : array_map( 'intval', (array) $value );
            } elseif ( $meta[0] === 'object' ) {
                $value = $value === null || $value === '' ? null : (int) $value;
            } elseif ( $meta[0] === 'details_width' && is_array( $value ) ) {
                unset( $value['custom_fields'] );
            }
            $settings[ $key ] = $value;
        }

        return $settings;
    }
}
