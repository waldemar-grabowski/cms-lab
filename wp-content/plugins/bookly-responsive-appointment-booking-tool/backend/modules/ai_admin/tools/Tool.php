<?php
namespace Bookly\Backend\Modules\AiAdmin\Tools;

use Bookly\Lib;

/**
 * A tool of the setup assistant. A read tool's description comes from the knowledge base
 * (assets/ai-setup/read_tools.yaml → Rules::readTools()) and is the only one the model gets;
 * plain JSON Schema, no provider-specific options — any model Cloud serves can call it.
 */
abstract class Tool
{
    /** @var Lib\Entities\AiConversation|null */
    protected $conversation;

    /**
     * @param Lib\Entities\AiConversation|null $conversation
     */
    public function __construct( $conversation = null )
    {
        $this->conversation = $conversation;
    }

    /**
     * @return string
     */
    abstract public function getName();

    /**
     * Whether the tool is offered on this site (e.g. list_taxes only with the Taxes add-on).
     * The set is stable within a site, so the cached prompt prefix stays stable too.
     *
     * @return bool
     */
    public function available()
    {
        return true;
    }

    /**
     * @return string
     */
    abstract protected function description();

    /**
     * JSON Schema of the arguments.
     *
     * @return array
     */
    abstract protected function parameters();

    /**
     * @param array $arguments
     * @return string the tool message content the model reads
     */
    abstract public function execute( array $arguments );

    /**
     * @return array
     */
    public function getSchema()
    {
        $parameters = $this->parameters();
        // An empty "properties" must stay an object in JSON: some providers reject [].
        if ( isset( $parameters['properties'] ) && $parameters['properties'] === array() ) {
            $parameters['properties'] = new \stdClass();
        }

        $described = Lib\AiAdmin\Rules::readTools();

        return array(
            'name'        => $this->getName(),
            'description' => isset( $described[ $this->getName() ] ) ? $described[ $this->getName() ] : $this->description(),
            'parameters'  => $parameters,
        );
    }

    /**
     * @param mixed $data
     * @return string
     */
    protected static function json( $data )
    {
        return wp_json_encode( $data );
    }
}
