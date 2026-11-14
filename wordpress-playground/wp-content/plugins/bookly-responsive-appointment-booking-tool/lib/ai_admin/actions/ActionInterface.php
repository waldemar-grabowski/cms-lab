<?php
namespace Bookly\Lib\AiAdmin\Actions;

use Bookly\Lib\AiAdmin\State;

/**
 * A write action of the setup assistant (assets/ai-setup/actions/<name>.yaml): the plugin checks
 * an item of propose, builds its preview, applies it after the owner presses Apply and undoes it.
 * Every class in this folder implementing this interface is an action, named after the class
 * (SetStaffSchedule → set_staff_schedule) — see Proposal::actions().
 *
 * Both validate() and preview() record the item's effect in $state (State::set() for values
 * later items compare with, State::created() for a create_* action) — they run on separate
 * passes over the same proposal. An id argument may be "$N": State::isNew() says it is the
 * object item N creates; apply() gets the real id instead.
 */
interface ActionInterface
{
    /**
     * Check the item against the action's validation rules (actions/<name>.yaml → validation,
     * the error starts with the rule id).
     *
     * @param array $args
     * @param State $state
     * @return string[] errors for the model ("<rule id>: <what is wrong>"), empty when valid
     */
    public function validate( array $args, State $state );

    /**
     * Preview of a valid item for the card.
     *
     * @param array $args
     * @param State $state
     * @return array array( 'rows' => array( array( 'label', 'before', 'after', 'link'? ) ), 'warnings' => string[] )
     */
    public function preview( array $args, State $state );

    /**
     * Apply a checked item with the same code the interface uses to save it.
     *
     * @param array $args "$N" already replaced with real ids
     * @return array snapshot that undo() restores; 'created_id' => id for a create_* action
     */
    public function apply( array $args );

    /**
     * @param array $snapshot
     */
    public function undo( array $snapshot );
}
