<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Execution;

use OCA\Mcp\Platform\FileAccess\FileAccessMessages;

use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Platform\FileAccess\Locks\LockAwareWrite;
use OCA\Mcp\Platform\FileAccess\Sharing\SharedWriteGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\File;
use OCP\Files\Node;
class NoteWriteAccess
{

    public function __construct(
        private SharedWriteGuard $guard,
        private ?LockAwareWrite $locks = null,
        private ?VisibilityGuard $visibilityGuard = null,
    )
    {
    }

    /**
     * The checks every note write shares, before anything is asked of the user.
     *
     * @param File $note the note about to change
     * @param array{etag?:string} $arguments validated tool arguments
     * @throws ToolFailure when the note cannot be updated or changed since the caller read it
     */

    public function assertWritable(File $note, array $arguments): void
    {
        if (!$note->isUpdateable()) {
            throw new ToolFailure(FileAccessMessages::forbidden());
        }
        NodeAccess::checkEtag($note, $arguments['etag'] ?? null);
    }

    /**
     * Runs a write on a note, explaining a lock refusal of the storage when the lock appeared after the check.
     *
     * @template T
     * @param callable():T $write the write
     * @param File $note the note it changes
     * @param string $userId authenticated user
     * @param bool $untouched false when an earlier write of the same call already changed the note
     * @return T what the write returned
     */

    public function mutate(callable $write, File $note, string $userId, bool $untouched = true): mixed
    {
        return NodeAccess::mutate(fn() => $this->locks === null ? $write() : $this->locks->run($write, $note, $userId, null, true, $untouched), $this->visibilityGuard);
    }

    public function assertUnlocked(File $note, string $userId): void
    {
        $this->locks?->assertWritable($note, $userId);
    }

    public function confirmation(Node $note, string $userId, string $resource, bool $confirmed): ?array
    {
        return $this->guard->guard($note, $userId, $resource, $confirmed);
    }
}
