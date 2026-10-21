<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Moving;

use OCA\Mcp\Platform\FileAccess\FileAccessMessages;

use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;
class NoteMover
{

    public function __construct(
        private \OCA\Mcp\Modules\Notes\Storage\NotesFolders $folders,
        private \OCA\Mcp\Modules\Notes\Storage\NoteLookup $lookup,
        private \OCA\Mcp\Modules\Notes\Execution\NoteWriteAccess $writeAccess,
        private \OCA\Mcp\Modules\Notes\Execution\NoteSnapshot $snapshot,
        private NodeAccessInfo $accessInfo,
        private \OCA\Mcp\Modules\Notes\Execution\NotePlanNotice $notice,
        private \OCA\Mcp\Modules\Notes\Storage\NoteMetadata $metadata,
        private ?VisibilityGuard $visibilityGuard = null,
    )
    {
    }

    /**
     * @param array{id:int, category:string, etag?:string, confirm_shared?:bool} $arguments
     * @return array<string, mixed> the note, or the shared-write confirmation without moving
     */

    public function execute(array $arguments, string $userId): array
    {
        $root = $this->folders->folder($userId, false);
        $note = $this->lookup->find($root, $arguments['id']);
        $this->writeAccess->assertWritable($note, $arguments);
        $this->writeAccess->assertUnlocked($note, $userId);
        if (($payload = $this->writeAccess->confirmation($note, $userId, (string) $arguments['id'], (bool) ($arguments['confirm_shared'] ?? false))) !== null) {
            return $payload;
        }
        $note = $this->lookup->find($root, $arguments['id']);
        $target = $this->folders->category($root, $arguments['category']);
        if ($target->getPath() !== $note->getParent()->getPath()) {
            if ($target->nodeExists($note->getName())) {
                throw new ToolFailure(\OCA\Mcp\Modules\Notes\Storage\NamingMessages::titleExistsInTargetCategory());
            }
            $this->writeAccess->mutate(fn() => $note->move($target->getPath() . '/' . $note->getName()), $note, $userId);
        }
        return $this->described($root, $userId, $arguments);
    }

    /**
     * @param string $userId authenticated user
     * @param array{id:int, category:string, etag?:string, confirm_shared?:bool} $arguments
     * @return array<string, mixed> where the note goes
     */

    public function preview(array $arguments, string $userId): array
    {
        $root = $this->folders->folder($userId, false);
        $note = $this->lookup->find($root, $arguments['id']);
        NodeAccess::checkEtag($note, $arguments['etag'] ?? null);
        if (!$note->isUpdateable()) {
            throw new ToolFailure(FileAccessMessages::forbidden());
        }
        $category = (string) $arguments['category'];
        if ($root !== null) {
            // The same refusal the write gives: a target below a hidden ancestor is a target that does not
            // exist, so the user is never asked to approve a move the server would not perform.
            $this->folders->assertCategoryVisible($root, $category);
        }
        $folder = $this->folders->existingCategory($root, $category);
        return ['action' => 'notes_move', 'note' => $this->snapshot->snapshot($root, $note), 'from' => $this->snapshot->snapshot($root, $note)['category'], 'to' => trim($category, '/'), 'categoryCreated' => $folder === null && trim($category, '/') !== '', 'titleTaken' => (function () use ($folder, $note): bool {
            if ($folder === null || !$folder->nodeExists($note->getName())) {
                return false;
            }
            $existing = $folder->get($note->getName());
            return $this->visibilityGuard === null || $this->visibilityGuard->isVisible($existing);
        })(), 'access' => $this->accessInfo->describe($note, $userId), 'shared' => $this->notice->shared($note, $userId), 'recoverable' => true, 'message' => \OCA\Mcp\Modules\Notes\Presentation\NotesPlanMessages::planMove()] + $this->notice->lock($note, $userId);
    }

    /**
     * @param Folder|null $root notes folder
     * @param string $userId authenticated user
     * @param array{id:int} $arguments validated tool arguments
     * @return array<string, mixed> the note as the other write tools return it
     */

    private function described(?Folder $root, string $userId, array $arguments): array
    {
        $note = $this->lookup->find($root, $arguments['id']);
        return $this->metadata->info($note, $root) + ['access' => $this->accessInfo->describe($note, $userId)];
    }
}
