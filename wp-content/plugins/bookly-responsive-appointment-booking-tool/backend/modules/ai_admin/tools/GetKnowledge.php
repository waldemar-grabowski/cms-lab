<?php
namespace Bookly\Backend\Modules\AiAdmin\Tools;

use Bookly\Lib\AiAdmin\Knowledge;

/**
 * get_knowledge(path) — a file of the built knowledge: an index, a card, a goal or an action; a
 * card ends with the current values of its options on this site. The result keeps only a
 * reference to the file, Cloud puts the text in (see Lib\AiAdmin\Knowledge::find()).
 */
class GetKnowledge extends Tool
{
    const NAME = 'get_knowledge';
    /** An allowed list longer than this stays with get_settings. */
    const LONG_LIST = 40;

    public function getName()
    {
        return self::NAME;
    }

    protected function description()
    {
        return 'Load a knowledge file by its path: index/<section>.md, index/goals.md, cards/<key>.md, goals/<key>.md, actions/<action>.md.';
    }

    protected function parameters()
    {
        return array(
            'type'       => 'object',
            'properties' => array(
                'path' => array( 'type' => 'string', 'description' => 'Path of the file, e.g. "index/general.md" or "cards/option.bookly_gen_time_slot_length.md".' ),
            ),
            'required'   => array( 'path' ),
        );
    }

    public function execute( array $arguments )
    {
        $path  = isset( $arguments['path'] ) ? (string) $arguments['path'] : '';
        $found = Knowledge::find( $path );
        if ( $found['path'] === null ) {
            $error = 'Error: no knowledge file "' . $path . '" (or the knowledge service did not answer).';
            if ( $found['suggestions'] ) {
                $error .= ' Nearest files: ' . implode( ', ', $found['suggestions'] ) . '.';
            }

            return $error . ' Paths are listed in the indexes (index/<section>.md); any option opens as cards/option.<option name>.md.';
        }
        // The file itself is not here: Cloud puts its text in place of this line when the
        // result goes to the model, so the base stays Cloud's.
        $result = '[knowledge:' . $found['path'] . ']';
        if ( $found['path'] !== ltrim( trim( $path ), '/' ) ) {
            // The model wrote the path a little wrong; it learns the right one for next time.
            $result = '(This is ' . $found['path'] . ".)\n\n" . $result;
        }
        $settings = self::currentSettings( $found['options'] );

        return $settings
            ? $result . "\n\nOn this site now (what get_settings returns for these options):\n" . self::json( $settings ) . "\n"
            : $result;
    }

    /**
     * Current values of the options the file describes, as get_settings returns them, so the
     * model needs no separate call. A long allowed list (time zones) is left to get_settings: it
     * would only weigh the card down.
     *
     * @param string[] $names
     * @return array
     */
    private static function currentSettings( array $names )
    {
        $settings = GetSettings::settings( array_unique( $names ) );
        foreach ( $settings as $name => $item ) {
            if ( isset( $item['error'] ) ) {
                unset( $settings[ $name ] );
            } elseif ( isset( $item['allowed'] ) && is_array( $item['allowed'] ) && count( $item['allowed'] ) > self::LONG_LIST ) {
                $settings[ $name ]['allowed'] = 'long list — call get_settings for it';
            }
        }

        return $settings;
    }
}
