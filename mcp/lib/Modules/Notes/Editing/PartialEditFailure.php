<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Editing;

use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Platform\FileAccess\Locks\LockWriteFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
class PartialEditFailure
{

    /**
     * The refusal of a rename that came after the new content of the same edit was saved, saying so: a client-safe
     * failure keeps its type and lock data and gains the sentence; a Nextcloud file exception is first mapped as
     * everywhere else. Anything else is unexpected and stays as it is.
     *
     * @param \Throwable $e what refused the rename
     * @return \Throwable the failure to throw
     */

    public static function map(\Throwable $e): \Throwable
    {
        try {
            NodeAccess::run(static fn() => throw $e);
        } catch (ToolFailure $mapped) {
            return $mapped instanceof LockWriteFailure ? $mapped->withNote(\OCA\Mcp\Modules\Notes\Execution\WriteMessages::contentSavedTitleNot()) : new ToolFailure($mapped->getMessage() . ' ' . \OCA\Mcp\Modules\Notes\Execution\WriteMessages::contentSavedTitleNot(), 0, $mapped);
        } catch (\Throwable) {
        }
        return $e;
    }
}
