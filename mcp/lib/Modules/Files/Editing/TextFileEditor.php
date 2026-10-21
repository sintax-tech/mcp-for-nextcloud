<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Editing;

use OCA\Mcp\Modules\Files\Reading\TextExtractor;
use OCA\Mcp\Platform\FileAccess\BackupLocation;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Platform\FileAccess\Locks\LockAwareWrite;
use OCA\Mcp\Platform\FileAccess\Nodes\NodeAccess;
use OCA\Mcp\Platform\FileAccess\Paths\PathGuard;
use OCA\Mcp\Platform\FileAccess\Sharing\SharedWriteGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;

/** Protected text replacement. Preview and execution intentionally have different refusal ordering. */
final class TextFileEditor {
    public const MAX_EDIT_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private IRootFolder $rootFolder,
        private TextExtractor $extractor,
        private ContentWriter $writer,
        private ContentPlan $contentPlan,
        private SharedWriteGuard $guard,
        private FilesAccessGuard $visibilityGuard,
        private LockAwareWrite $locks,
    ) {}

    /** Returns a domain plan; the existing registry renders its MCP envelope. */
    public function preview(EditRequest $request, string $userId): array {
        $root = $this->rootFolder->getUserFolder($userId);
        return NodeAccess::run(fn () => $this->planWrite($root, $userId, $request->path, $request->content, $request->etag));
    }

    /** Global confirmation is enforced by the calling registry; shared confirmation is checked here. */
    public function execute(EditRequest $request, string $userId): array {
        $root = $this->rootFolder->getUserFolder($userId);
        return NodeAccess::run(fn () => $this->write($root, $userId, $request->path, $request->content, $request->etag, $request->confirmShared));
    }

    /** Shared read-only preparation; never checks versioning, creates a backup, or refuses a lock. */
    private function prepare(Folder $root, string $path, string $content): File {
        $this->assertEditable($root, $path);
        if (strlen($content) > self::MAX_EDIT_BYTES) {
            throw new ToolFailure(EditingMessages::editTooLarge(self::MAX_EDIT_BYTES));
        }
        return $this->file($root, $path);
    }

    private function planWrite(Folder $root, string $userId, string $path, string $content, ?string $etag): array {
        $path = PathGuard::normalize($path);
        $file = $this->prepare($root, $path, $content);
        NodeAccess::checkEtag($file, $etag);
        $diff = UnifiedDiff::between($this->extractor->extract($file), $content);

        return $this->contentPlan->describe($file, $userId, $path, [
            'action' => 'files_edit',
            'size' => ['before' => (int)$file->getSize(), 'after' => strlen($content)],
            'diff' => $diff,
            'message' => EditingMessages::planEdit(),
        ]);
    }

    private function write(Folder $root, string $userId, string $path, string $content, ?string $etag, bool $confirmed): array {
        $path = PathGuard::normalize($path);
        $file = $this->prepare($root, $path, $content);
        $this->locks->assertWritable($file, $userId, $path);
        if (($payload = $this->guard->guard($file, $userId, $path, $confirmed)) !== null) {
            return ToolResult::json($payload);
        }
        return ToolResult::json($this->writer->commit($root, $userId, $file, $path, $content, $etag, $this->extractor->extract($file)));
    }

    private function file(Folder $root, string $path): File {
        return NodeAccess::requireFile(NodeAccess::get($root, $path, $this->visibilityGuard));
    }

    private function assertEditable(Folder $root, string $path): void {
        $this->assertNotBackup($path);
        if (!TextExtractor::isText($this->file($root, $path))) {
            throw new ToolFailure(EditingMessages::notText());
        }
    }

    private function assertNotBackup(string $path): void {
        if (BackupLocation::isBackupPath($path)) {
            throw new ToolFailure(EditingMessages::backupPath());
        }
    }
}
