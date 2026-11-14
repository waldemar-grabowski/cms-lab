<?php
namespace Bookly\Frontend\Modules\Ai;

use Bookly\Backend\Modules\Appearance\ModernAppearance;
use Bookly\Lib;

class SystemPrompt
{
    /**
     * What Bookly Cloud puts into the prompt as the business's own section. Cloud's prompt
     * is one text for every site - the agent's rules and the concepts of booking, each saying
     * when it applies - so a change to it reaches every site without a plugin release. The
     * plugin sends only what is particular to this business:
     *
     *  - assistant_knowledge, assistant_instructions: what the owner wrote on this widget's
     *    appearance, under the same names - information about the business, and the owner's
     *    instructions for the assistant. Cloud (protocol 1.2) takes instructions only from
     *    the second, within its limits (they cannot change prices, available times or payment);
     *  - company: name and contacts from Settings > Company, so the assistant can give
     *    them whenever it suggests contacting the business;
     *  - facts: how the business is set up, as values grouped by what the customer sees -
     *    Cloud picks the parts of its prompt by them (Concepts::facts());
     *  - catalog: services, staff members, locations (Catalog::payload()).
     *
     * The owner's texts stay per-form (a site can publish several assistants, e.g. one per
     * location) and the token travels with the request instead of being stored on the
     * conversation - see Ajax::aiSendMessage()/spawnWorker().
     *
     * Everything here has to stay stable for the whole conversation: Bookly
     * Cloud marks the end of the system prompt as a prompt-cache breakpoint,
     * so any value that changes between two calls (a clock, above all) would
     * invalidate the cached prefix on every single request and quietly turn a
     * ~10% cache read back into a full-price charge. The current time is
     * therefore attached to the customer's message instead - see
     * ::dateLine() and Ajax::buildCompletionPayload().
     *
     * @param string|null $form_slug Token of the AI assistant appearance the widget was
     *                                rendered with; null falls back to the first one.
     * @return array
     */
    public static function site( $form_slug = null )
    {
        $appearance = ModernAppearance::getAppearance( Lib\Entities\Form::TYPE_AI_ASSISTANT, $form_slug ?: null );
        $text = function ( $key ) use ( $appearance ) {
            return isset( $appearance[ $key ] ) ? trim( (string) $appearance[ $key ] ) : '';
        };

        return array(
            'assistant_knowledge'    => $text( 'assistant_knowledge' ),
            'assistant_instructions' => $text( 'assistant_instructions' ),
            'company'       => self::company(),
            'facts'         => Concepts::forSite()->facts(),
            'catalog'       => Catalog::payload(),
        );
    }

    /**
     * Name and contacts from Settings > Company - the same values notifications use for
     * {company_name}, {company_phone} etc. Empty ones are left out.
     *
     * @return array
     */
    public static function company()
    {
        $company = array(
            'name'    => get_option( 'bookly_co_name' ),
            'address' => get_option( 'bookly_co_address' ),
            'phone'   => get_option( 'bookly_co_phone' ),
            'email'   => get_option( 'bookly_co_email' ),
            'website' => get_option( 'bookly_co_website' ),
        );

        return array_filter( array_map( function ( $value ) {
            return trim( (string) $value );
        }, $company ), 'strlen' );
    }

    /**
     * Whether customers can be told how to reach the business: the name alone is not a way.
     *
     * @return bool
     */
    public static function hasContacts()
    {
        return (bool) array_diff_key( self::company(), array( 'name' => true ) );
    }

    /**
     * The timestamp attached to a customer message: when it was actually
     * sent, never when this payload is built.
     *
     * That distinction matters twice: one customer turn is 3-5 model calls
     * (tool loop) and a conversation is many turns, so a fresh clock — or a
     * line that moves from message to message — would rewrite text the model
     * has already seen and defeat caching of the conversation prefix; and
     * the message is what the model is answering, so its own timestamp is
     * the honest reference point for "tomorrow" in it.
     *
     * The customer's own time zone is mentioned only when it actually differs
     * from the business's — by UTC offset at the moment of the message, not by
     * name: Europe/Kyiv and Europe/Helsinki are different names for the same
     * clock, and the model must not be left to make that comparison itself.
     * Cloud's booking prompt (v2+) stays silent about time zones unless this
     * line says they differ; older plugins never send it, so the bot never
     * brings the subject up on its own.
     *
     * @param string|null $created_at MySQL datetime of the message, in the business's time zone
     * @param string|null $customer_time_zone IANA name from the customer's browser, null when unknown
     * @return string
     */
    public static function dateLine( $created_at = null, $customer_time_zone = null )
    {
        $point = $created_at ? Lib\Slots\DatePoint::fromStr( $created_at ) : Lib\Slots\DatePoint::now();

        $customer = '';
        if ( $customer_time_zone ) {
            try {
                $customer_point = new \DateTime( '@' . $point->format( 'U' ) );
                $customer_point->setTimezone( new \DateTimeZone( $customer_time_zone ) );
                if ( $customer_point->format( 'Z' ) !== $point->format( 'Z' ) ) {
                    $customer = sprintf(
                        ' Customer\'s time zone differs: %s, UTC%s.',
                        $customer_time_zone,
                        $customer_point->format( 'P' )
                    );
                }
            } catch ( \Exception $e ) {
                // Unknown zone name — say nothing rather than guess.
            }
        }

        return sprintf(
            '(Date/time: %s, %s.%s)',
            $point->format( 'Y-m-d H:i:s' ),
            $point->format( 'l' ),
            $customer
        );
    }
}
