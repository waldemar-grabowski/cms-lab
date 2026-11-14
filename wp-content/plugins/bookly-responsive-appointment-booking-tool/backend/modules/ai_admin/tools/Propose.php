<?php
namespace Bookly\Backend\Modules\AiAdmin\Tools;

use Bookly\Lib;
use Bookly\Lib\AiAdmin\Proposal;
use Bookly\Lib\AiAdmin\Rules;

/**
 * propose({changes, reason}) — the only write tool: every change of a request in one call. The
 * plugin checks the items in order; a valid proposal becomes a card the owner applies or not,
 * an invalid one goes back to the model as errors. Nothing is saved here.
 */
class Propose extends Tool
{
    const NAME = 'propose';

    /** Tool name of the event message that holds the card (see Ajax::aiAdminPoll()). */
    const CARD = 'ai_admin_card';

    public function getName()
    {
        return self::NAME;
    }

    protected function description()
    {
        return 'Propose ALL changes the request needs, in order, in one call, with a short reason in the user\'s language. The plugin checks them and shows the user a card with Apply; nothing is saved until the user applies it.';
    }

    protected function parameters()
    {
        return array(
            'type'       => 'object',
            'properties' => array(
                'changes' => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'action' => array( 'type' => 'string', 'enum' => array_keys( Rules::actions() ) ),
                            'args'   => array( 'type' => 'object', 'description' => 'Arguments of the action — see actions/<action>.md.' ),
                        ),
                        'required'   => array( 'action', 'args' ),
                    ),
                ),
                'reason'    => array( 'type' => 'string', 'description' => 'Why these changes, in the user\'s language, 1-3 sentences.' ),
                'continues' => array( 'type' => 'boolean', 'description' => 'true when this card is one part of the request and its next part can be proposed only after Apply: once the user applies the card, your next turn starts by itself.' ),
            ),
            'required'   => array( 'changes', 'reason' ),
        );
    }

    public function execute( array $arguments )
    {
        $changes   = isset( $arguments['changes'] ) && is_array( $arguments['changes'] ) ? array_values( $arguments['changes'] ) : array();
        $reason    = isset( $arguments['reason'] ) ? (string) $arguments['reason'] : '';
        $continues = ! empty( $arguments['continues'] );
        foreach ( $changes as $i => $item ) {
            if ( ! is_array( $item ) ) {
                $changes[ $i ] = array();
            }
        }
        $implemented = Proposal::actions();
        $described   = Rules::actions();
        foreach ( $changes as $i => $item ) {
            if ( isset( $item['action'] ) && ! isset( $implemented[ $item['action'] ] ) && isset( $described[ $item['action'] ] ) ) {
                return self::json( array( 'status' => 'rejected', 'errors' => array(
                    sprintf( 'item %d: the action %s is not available yet — tell the user where to change it by hand.', $i + 1, $item['action'] ),
                ) ) );
            }
        }
        $check = Proposal::check( $changes );
        if ( $check['errors'] ) {
            return self::json( array( 'status' => 'rejected', 'errors' => $check['errors'] ) );
        }

        // A new card replaces the ones not applied yet: their Apply would save what the owner no
        // longer asks for (the new card is made from the newer request).
        /** @var Lib\Entities\AiMessage[] $earlier */
        $earlier = Lib\Entities\AiMessage::query()
            ->where( 'conversation_id', $this->conversation->getId() )
            ->where( 'role', Lib\Entities\AiMessage::ROLE_EVENT )
            ->where( 'tool_name', self::CARD )
            ->find();
        foreach ( $earlier as $old ) {
            $data = json_decode( $old->getContent(), true );
            if ( $data['status'] === 'pending' ) {
                $data['status'] = 'replaced';
                $old->setContent( wp_json_encode( $data ) )->save();
            }
        }

        $card = new Lib\Entities\AiMessage();
        $card
            ->setConversationId( $this->conversation->getId() )
            ->setRole( Lib\Entities\AiMessage::ROLE_EVENT )
            ->setToolName( self::CARD )
            ->setContent( wp_json_encode( array(
                'status'    => 'pending',
                'changes'   => $changes,
                'reason'    => $reason,
                'items'     => $check['items'],
                'groups'    => $check['groups'],
                'continues' => $continues,
            ) ) )
            ->save();

        // The model gets the card as the owner reads it, nothing more; the warnings are for the owner.
        return self::json( array(
            'status' => 'shown',
            'card'   => Proposal::groupsText( $check['groups'] ),
            'note'   => $continues
                ? 'The card is on the user\'s screen with Apply. Do not write anything else in this turn; after Apply your next turn starts by itself.'
                : 'The card is on the user\'s screen with Apply. Do not write anything else in this turn.',
        ) );
    }
}
