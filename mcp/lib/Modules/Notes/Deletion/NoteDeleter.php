<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Deletion;

use OCA\Mcp\Platform\FileAccess\FileAccessMessages;

use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;
class NoteDeleter
{

    public function __construct(
        private \OCA\Mcp\Modules\Notes\Storage\NotesFolders $folders,
        private \OCA\Mcp\Modules\Notes\Deletion\TrashRecovery $recovery,
        private \OCA\Mcp\Modules\Notes\Storage\NoteLookup $lookup,
        private \OCA\Mcp\Modules\Notes\Execution\NoteWriteAccess $writeAccess,
        private \OCA\Mcp\Modules\Notes\Execution\NoteSnapshot $snapshot,
        private NodeAccessInfo $accessInfo,
        private \OCA\Mcp\Modules\Notes\Execution\NotePlanNotice $notice,
    )
    {
    }

    /**
     * @param array{id:int, confirm?:bool, etag?:string, confirm_shared?:bool} $arguments
     * @return array<string, mixed> the deletion receipt, or the shared-write confirmation without deleting
     */

    public function execute(array $arguments, string $userId): array
    {
        $root = $this->folders->folder($userId, false);
        $this->recovery->assertEnabled($userId);
        $note = $this->lookup->find($root, $arguments['id']);
        $this->writeAccess->assertWritable($note, $arguments);
        $this->writeAccess->assertUnlocked($note, $userId);
        if (($payload = $this->writeAccess->confirmation($note, $userId, (string) $arguments['id'], (bool) ($arguments['confirm_shared'] ?? false))) !== null) {
            return $payload;
        }
        $note = $this->lookup->find($root, $arguments['id']);
        if (!$note->isDeletable()) {
            throw new ToolFailure(FileAccessMessages::forbidden());
        }
        // An enabled app does not cover every mount: external or excluded storages delete permanently.
        $this->recovery->assertStorage($note);
        $this->writeAccess->mutate(fn() => $note->delete(), $note, $userId);
        return ['id' => $arguments['id'], 'deleted' => true, 'trash' => true];
    }

    /**
     * @param string $userId authenticated user
     * @param array{id:int, etag?:string, confirm_shared?:bool} $arguments
     * @return array<string, mixed> the note that would go to the trash bin
     */

    public function preview(array $arguments, string $userId): array
    {
        $root = $this->folders->folder($userId, false);
        $this->recovery->assertEnabled($userId);
        $note = $this->lookup->find($root, $arguments['id']);
        NodeAccess::checkEtag($note, $arguments['etag'] ?? null);
        if (!$note->isDeletable()) {
            throw new ToolFailure(FileAccessMessages::forbidden());
        }
        $this->recovery->assertStorage($note);
        return ['action' => 'notes_delete', 'note' => $this->snapshot->snapshot($root, $note), 'access' => $this->accessInfo->describe($note, $userId), 'shared' => $this->notice->shared($note, $userId), 'trash' => true, 'recoverable' => true, 'consequence' => \OCA\Mcp\Modules\Notes\Presentation\NotesPlanMessages::planDeleteConsequence(), 'message' => \OCA\Mcp\Modules\Notes\Presentation\NotesPlanMessages::planDelete()] + $this->notice->lock($note, $userId);
    }
}
