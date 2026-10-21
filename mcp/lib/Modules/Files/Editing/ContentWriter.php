<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Editing;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Platform\FileAccess\Locks\LockAwareWrite;
use OCA\Mcp\Platform\FileAccess\Locks\LockWriteFailure;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Lock\LockedException;

final class ContentWriter {
    public function __construct(
        private FileBackup $backup,
        private NodeAccessInfo $accessInfo,
        private FilesAccessGuard $visibilityGuard,
        private ?LockAwareWrite $locks = null,
    ) {}

    /**
     * Backup, mutation-time checks, content write and receipt shared by text editing and replacement.
     *
     * @param Folder $root the user's folder
     * @param string $userId authenticated user
     * @param File $file file about to be overwritten
     * @param string $path normalized user-relative path of $file
     * @param string $content new content
     * @param string|null $etag ETag the caller read before, null to skip the check
     * @param string $before content as read, for the diff
     * @return array{path:string, size:int, etag:string, access:array<string, mixed>, backup:string, diff:string}
     * @throws ToolFailure when the preparation or the write fails
     */
    public function commit(Folder $root, string $userId, File $file, string $path, string $content, ?string $etag, string $before): array {
        $copy = $this->backup->prepare($root, $file, $path, $userId, $etag);
        $this->visibilityGuard->assertVisible($file);
        try {
            // Checked again right before the write: the backup took time, and files_lock does not refuse every lock.
            NodeAccess::mutate(fn () => $this->locks === null ? $file->putContent($content) : $this->locks->run(fn () => $file->putContent($content), $file, $userId, $path), $this->visibilityGuard);
        } catch (LockWriteFailure $e) {
            throw $e->withNote(EditingMessages::originalKept($copy));
        } catch (LockedException $e) {
            $failure = NodeAccess::lockFailure($e);
            throw $failure instanceof LockWriteFailure ? $failure->withNote(EditingMessages::originalKept($copy)) : new ToolFailure(EditingMessages::writeFailed($copy));
        } catch (\Throwable) {
            throw new ToolFailure(EditingMessages::writeFailed($copy));
        }
        $node = NodeAccess::requireFile(NodeAccess::get($root, $path, $this->visibilityGuard));
        return [
            'path' => $path,
            'size' => strlen($content),
            'etag' => (string)$node->getEtag(),
            'access' => $this->accessInfo->describe($node, $userId),
            'backup' => $copy,
            'diff' => UnifiedDiff::between($before, $content),
        ];
    }
}
