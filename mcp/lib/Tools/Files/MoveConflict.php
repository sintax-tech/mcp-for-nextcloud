<?php
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use OCA\Mcp\Tools\ToolFailure;

/**
 * A move that cannot happen because of where it wants to land: something is already there, the folder would
 * land inside itself, or the destination is on another storage.
 *
 * It is a ToolFailure so a single move answers the same way it always did, and a subclass so a batch can tell
 * "fix the destination" from "you may not do this" and put the item in the right list of the plan.
 */
final class MoveConflict extends ToolFailure {}
