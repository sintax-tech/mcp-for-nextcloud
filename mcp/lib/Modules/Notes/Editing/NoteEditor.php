<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Editing;

use OCA\Mcp\Platform\FileAccess\FileAccessMessages;

use InvalidArgumentException;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;
class NoteEditor
{

    public function __construct(
        private \OCA\Mcp\Modules\Notes\Storage\NotesFolders $folders,
        private \OCA\Mcp\Modules\Notes\Storage\NoteLookup $lookup,
        private \OCA\Mcp\Modules\Notes\Execution\NoteWriteAccess $writeAccess,
        private \OCA\Mcp\Modules\Notes\Content\NoteContent $content,
        private \OCA\Mcp\Modules\Notes\Editing\RenameTarget $renameTarget,
        private \OCA\Mcp\Modules\Notes\Execution\NoteSnapshot $snapshot,
        private \OCA\Mcp\Modules\Notes\Storage\NoteNames $names,
        private NodeAccessInfo $accessInfo,
        private \OCA\Mcp\Modules\Notes\Execution\NotePlanNotice $notice,
        private \OCA\Mcp\Modules\Notes\Storage\NoteMetadata $metadata,
    )
    {
    }

    /**
     * @param array{id:int, content?:string, title?:string, etag?:string, confirm_shared?:bool} $arguments
     * @return array<string, mixed> the note, or the shared-write confirmation without writing
     */

    public function execute(array $arguments, string $userId): array
    {
        $root = $this->folders->folder($userId, false);
        if (!isset($arguments['content']) && !isset($arguments['title'])) {
            throw new InvalidArgumentException('Missing argument: content');
        }
        $note = $this->lookup->find($root, $arguments['id']);
        $this->writeAccess->assertWritable($note, $arguments);
        $this->writeAccess->assertUnlocked($note, $userId);
        // Everything that can refuse is checked before the first write: the content has no backup, so
        // a refusal after it would leave the note overwritten by a call that reports failure. It is also
        // checked before the shared-write confirmation, so the user is never asked to approve a call
        // the server would refuse.
        if (isset($arguments['content'])) {
            $this->content->assertSize($arguments['content']);
        }
        $rename = isset($arguments['title']) ? $this->renameTarget->resolve($note, $arguments['title']) : null;
        if (($payload = $this->writeAccess->confirmation($note, $userId, (string) $arguments['id'], (bool) ($arguments['confirm_shared'] ?? false))) !== null) {
            return $payload;
        }
        // Two writes, each checked right before it. Whatever refuses the rename once the content is saved (a lock the
        // check observes, the storage refusing it, a transactional lock, a permission), the answer says that the
        // content did change.
        $saved = false;
        if (isset($arguments['content'])) {
            $this->writeAccess->mutate(fn() => $note->putContent($arguments['content']), $note, $userId);
            $saved = true;
        }
        if ($rename !== null) {
            try {
                $this->writeAccess->mutate(fn() => $note->move($rename), $note, $userId, !$saved);
            } catch (\Throwable $e) {
                throw $saved ? PartialEditFailure::map($e) : $e;
            }
        }
        return $this->described($root, $userId, $arguments);
    }

    /**
     * @param string $userId authenticated user
     * @param array{id:int, content?:string, title?:string, etag?:string, confirm_shared?:bool} $arguments
     * @return array<string, mixed> the note before and after the change
     */

    public function preview(array $arguments, string $userId): array
    {
        $root = $this->folders->folder($userId, false);
        if (!isset($arguments['content']) && !isset($arguments['title'])) {
            throw new InvalidArgumentException('Missing argument: content');
        }
        $note = $this->lookup->find($root, $arguments['id']);
        if (isset($arguments['content'])) {
            $this->content->assertSize($arguments['content']);
        }
        NodeAccess::checkEtag($note, $arguments['etag'] ?? null);
        if (!$note->isUpdateable()) {
            throw new ToolFailure(FileAccessMessages::forbidden());
        }
        // The same refusal the write gives, so the user is never asked to approve what would fail.
        if (isset($arguments['title'])) {
            $this->renameTarget->resolve($note, $arguments['title']);
        }
        $before = $this->snapshot->snapshot($root, $note);
        $after = $before;
        $after['title'] = isset($arguments['title']) ? pathinfo($this->names->renamedName($note, $arguments['title']), PATHINFO_FILENAME) : $before['title'];
        // `changed` compares the whole content: the excerpts a plan shows are cut and can be equal for
        // two different notes.
        $contentChanged = false;
        if (isset($arguments['content'])) {
            $after['content'] = $this->snapshot->excerpt($arguments['content']);
            $after['bytes'] = strlen($arguments['content']);
            $contentChanged = $arguments['content'] !== (string) $note->getContent();
        }
        return ['action' => 'notes_edit', 'note' => ['id' => (int) $note->getId(), 'before' => $before, 'after' => $after], 'changed' => array_values(array_filter(['title', 'content'], static fn(string $field): bool => $field === 'title' ? $before['title'] !== $after['title'] : $contentChanged)), 'access' => $this->accessInfo->describe($note, $userId), 'shared' => $this->notice->shared($note, $userId), 'recoverable' => true, 'message' => \OCA\Mcp\Modules\Notes\Presentation\NotesPlanMessages::planEdit()] + $this->notice->lock($note, $userId);
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
