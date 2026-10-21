<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes\Creation;

use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCP\Files\Folder;
class NoteCreator
{

    public function __construct(
        private \OCA\Mcp\Modules\Notes\Content\NoteContent $content,
        private \OCA\Mcp\Modules\Notes\Storage\NotesFolders $folders,
        private \OCA\Mcp\Modules\Notes\Execution\NoteWriteAccess $writeAccess,
        private \OCA\Mcp\Modules\Notes\Storage\NoteMetadata $metadata,
        private \OCA\Mcp\Modules\Notes\Storage\NoteNames $names,
        private \OCA\Mcp\Modules\Notes\Execution\NotePlanNotice $notice,
        private \OCA\Mcp\Modules\Notes\Execution\NoteSnapshot $snapshot,
        private ?VisibilityGuard $visibilityGuard = null,
    )
    {
    }

    /**
     * @param array{title:string, content?:string, category?:string, confirm_shared?:bool} $arguments
     * @return array<string, mixed> the note, or the shared-write confirmation without creating anything
     */

    public function execute(array $arguments, string $userId): array
    {
        $content = (string) ($arguments['content'] ?? '');
        $category = (string) ($arguments['category'] ?? '');
        $this->content->assertSize($content);
        $target = $this->folders->existingAncestor($userId, $category);
        $resource = trim($category, '/') === '' ? '/' : trim($category, '/');
        if (($payload = $this->writeAccess->confirmation($target, $userId, $resource, (bool) ($arguments['confirm_shared'] ?? false))) !== null) {
            return $payload;
        }
        $root = $this->folders->folder($userId, true);
        $folder = $this->folders->category($root, $category);
        return $this->metadata->info(NodeAccess::mutate(fn() => $folder->newFile($this->names->freeName($folder, (string) $arguments['title']), $content), $this->visibilityGuard), $root);
    }

    /**
     * @param string $userId authenticated user
     * @param array{title:string, content?:string, category?:string} $arguments
     * @return array<string, mixed> the note the call would create
     */

    public function preview(array $arguments, string $userId): array
    {
        $root = $this->folders->folder($userId, false);
        $this->content->assertSize((string) ($arguments['content'] ?? ''));
        $category = (string) ($arguments['category'] ?? '');
        $shared = $this->notice->shared($this->folders->existingAncestor($userId, $category), $userId);
        if ($root !== null) {
            // A plan refuses what the write refuses: a new category below a hidden ancestor answers exactly
            // like one that does not exist, before anything is created.
            $this->folders->assertCategoryVisible($root, $category);
        }
        $folder = $this->folders->existingCategory($root, $category);
        if ($root === null || $folder === null) {
            // The category folder itself does not exist yet: the write creates it, and the plan says so
            // rather than creating anything now.
            return ['action' => 'notes_create', 'note' => ['title' => (string) $arguments['title'], 'category' => trim($category, '/'), 'categoryCreated' => $category !== ''], 'shared' => $shared, 'recoverable' => true, 'message' => \OCA\Mcp\Modules\Notes\Presentation\NotesPlanMessages::planCreate()];
        }
        return ['action' => 'notes_create', 'note' => ['title' => (string) $arguments['title'], 'fileName' => $this->names->freeName($folder, (string) $arguments['title']), 'category' => $this->folders->categoryName($root, $folder), 'categoryCreated' => false, 'content' => $this->snapshot->excerpt((string) ($arguments['content'] ?? '')), 'bytes' => strlen((string) ($arguments['content'] ?? ''))], 'shared' => $shared, 'recoverable' => true, 'message' => \OCA\Mcp\Modules\Notes\Presentation\NotesPlanMessages::planCreate()];
    }
}
