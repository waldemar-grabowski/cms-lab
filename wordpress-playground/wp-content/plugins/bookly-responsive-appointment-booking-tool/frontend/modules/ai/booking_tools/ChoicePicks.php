<?php
namespace Bookly\Frontend\Modules\Ai\BookingTools;

use Bookly\Lib;

/**
 * Not a tool itself - the offer_choices cards in a conversation once the customer answers
 * them: the click noted for the model (notePick()), and the answered cards put back after a
 * page reload (answered()).
 */
class ChoicePicks
{
    // The event a click leaves before the customer's message, for the model (card id, label).
    const PICK_EVENT = 'Clicked on the offer_choices cards: id %s, "%s".';

    /**
     * Note which card the customer clicked, for the model to read with the click's label
     * (Ajax::buildCompletionPayload()): two cards may share a label. Only a card of the latest
     * offer counts - the one the widget leaves open. A typed answer, an id not in the offer,
     * notes nothing: the model reads the message as it is.
     *
     * @param Lib\Entities\AiConversation $conversation
     * @param string                      $id The clicked card's id
     */
    public static function notePick( Lib\Entities\AiConversation $conversation, $id )
    {
        if ( $id === '' || ! $conversation->getId() ) {
            return;
        }

        foreach ( self::cards( self::latestOffer( $conversation ) ) as $card ) {
            if ( (string) $card['id'] === $id ) {
                // The model reads the content only. tool_name marks the event as a click and
                // tool_call_id holds the card's id, for answered() to mark the card after a reload.
                $event = new Lib\Entities\AiMessage();
                $event
                    ->setConversationId( $conversation->getId() )
                    ->setRole( Lib\Entities\AiMessage::ROLE_EVENT )
                    ->setContent( sprintf( self::PICK_EVENT, $card['id'], $card['label'] ) )
                    ->setToolName( OfferChoices::NAME )
                    ->setToolCallId( (string) $card['id'] )
                    ->save();

                return;
            }
        }
    }

    /**
     * The cards the customer has answered, for the widget to put back after a page reload as
     * they were: closed, before the answer, the clicked one marked. As live, a turn's last
     * offer_choices call is what the customer saw, and the next customer message answers it.
     *
     * @param Lib\Entities\AiConversation $conversation
     * @return array[] [{before: the answer's message id, choices: ChoiceCards::present(), picked: card id|null}]
     */
    public static function answered( Lib\Entities\AiConversation $conversation )
    {
        /** @var Lib\Entities\AiMessage[] $messages */
        $messages = Lib\Entities\AiMessage::query()
            ->where( 'conversation_id', $conversation->getId() )
            ->whereIn( 'role', array( Lib\Entities\AiMessage::ROLE_TOOL, Lib\Entities\AiMessage::ROLE_EVENT, Lib\Entities\AiMessage::ROLE_USER ) )
            ->sortBy( 'id' )
            ->find();

        $answered = array();
        $offer    = null;
        $picked   = null;
        foreach ( $messages as $message ) {
            $role = $message->getRole();
            if ( $role === Lib\Entities\AiMessage::ROLE_TOOL && $message->getToolName() === OfferChoices::NAME ) {
                $offer  = $message->getContent();
                $picked = null;
            } elseif ( $role === Lib\Entities\AiMessage::ROLE_EVENT && $offer !== null && $message->getToolName() === OfferChoices::NAME ) {
                // A click (notePick()), right before the answer it goes with.
                $picked = $message->getToolCallId();
            } elseif ( $role === Lib\Entities\AiMessage::ROLE_USER ) {
                $choices = $offer !== null ? ChoiceCards::present( $offer ) : null;
                if ( $choices ) {
                    $answered[] = array( 'before' => (string) $message->getId(), 'choices' => $choices, 'picked' => $picked );
                }
                $offer  = null;
                $picked = null;
            }
        }

        return $answered;
    }

    /**
     * @param Lib\Entities\AiConversation $conversation
     * @return string|null The latest offer_choices result
     */
    private static function latestOffer( Lib\Entities\AiConversation $conversation )
    {
        /** @var Lib\Entities\AiMessage $offer */
        $offer = Lib\Entities\AiMessage::query()
            ->where( 'conversation_id', $conversation->getId() )
            ->where( 'role', Lib\Entities\AiMessage::ROLE_TOOL )
            ->where( 'tool_name', OfferChoices::NAME )
            ->sortBy( 'id' )
            ->order( Lib\Query::ORDER_DESCENDING )
            ->limit( 1 )
            ->findOne();

        return $offer ? $offer->getContent() : null;
    }

    /**
     * @param string|null $result An offer_choices result
     * @return array[] Its cards with id and label; none for an error
     */
    private static function cards( $result )
    {
        $data  = json_decode( (string) $result, true );
        $cards = array();
        foreach ( isset( $data['shown_as_buttons'] ) && is_array( $data['shown_as_buttons'] ) ? $data['shown_as_buttons'] : array() as $card ) {
            if ( isset( $card['id'], $card['label'] ) ) {
                $cards[] = $card;
            }
        }

        return $cards;
    }
}
