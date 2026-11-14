<?php
namespace Bookly\Backend\Modules\AiAdmin\Tools;

/**
 * offer_choices({question, choices}) — a question with 2–4 short options. The question is the
 * only text the user reads on that turn; a pick comes back as the label in the next message.
 * The turn ends right after it (Ajax::aiAdminWorker()).
 */
class OfferChoices extends Tool
{
    const NAME = 'offer_choices';

    public function getName()
    {
        return self::NAME;
    }

    protected function description()
    {
        return 'Ask the user a question with 2-4 options, then stop. The question is the only text the user reads on this turn.';
    }

    protected function parameters()
    {
        return array(
            'type'       => 'object',
            'properties' => array(
                'question' => array( 'type' => 'string' ),
                'choices'  => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'label'  => array( 'type' => 'string' ),
                            'effect' => array( 'type' => 'string', 'description' => 'What else this choice changes.' ),
                        ),
                        'required'   => array( 'label' ),
                    ),
                ),
            ),
            'required'   => array( 'question', 'choices' ),
        );
    }

    public function execute( array $arguments )
    {
        $choices = array();
        foreach ( isset( $arguments['choices'] ) ? (array) $arguments['choices'] : array() as $choice ) {
            if ( is_array( $choice ) && isset( $choice['label'] ) && trim( $choice['label'] ) !== '' ) {
                $choices[] = array(
                    'label'  => (string) $choice['label'],
                    'effect' => isset( $choice['effect'] ) ? (string) $choice['effect'] : '',
                );
            }
        }

        return self::json( array(
            'question' => isset( $arguments['question'] ) ? (string) $arguments['question'] : '',
            'choices'  => array_slice( $choices, 0, 4 ),
        ) );
    }
}
