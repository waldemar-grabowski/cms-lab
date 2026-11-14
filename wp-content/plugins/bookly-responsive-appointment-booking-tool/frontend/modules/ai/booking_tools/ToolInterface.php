<?php
namespace Bookly\Frontend\Modules\Ai\BookingTools;

/**
 * Contract every real booking tool implements — same shape as
 * Ai\CalcTools\ToolInterface (deliberately provider-agnostic: a plain name +
 * JSON Schema + a PHP callable)
 * One tool = one file = one class implementing this, registered by name in
 * Tools::all() (../Tools.php).
 */
interface ToolInterface
{
    /**
     * Tool name as the model will call it by — must match getSchema()['name'].
     *
     * @return string
     */
    public function getName();

    /**
     * JSON Schema tool definition (name/description/parameters), sent to
     * Cloud's /complete "tools" field as-is.
     *
     * @return array
     */
    public function getSchema();

    /**
     * Run the tool. $arguments is the decoded "arguments" object from the
     * model's tool_call. Cloud/the provider only guarantees the shape
     * matches getSchema()'s "required"/"type" loosely (providers vary in
     * how strictly they enforce JSON Schema) — implementations should stay
     * defensive (isset()/cast) rather than trust it blindly.
     *
     * @param array $arguments
     * @return string|int|float|bool
     */
    public function execute( array $arguments );
}
