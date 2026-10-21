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

final class TextReplacer {
    public const MAX_EDIT_BYTES = TextFileEditor::MAX_EDIT_BYTES;

    public function __construct(
        private IRootFolder $rootFolder,
        private TextExtractor $extractor,
        private ContentWriter $writer,
        private ContentPlan $contentPlan,
        private SharedWriteGuard $guard,
        private FilesAccessGuard $visibilityGuard,
        private ?LockAwareWrite $locks = null,
    ) {}

    public function execute(array $arguments, string $userId): array {
        $root = $this->rootFolder->getUserFolder($userId);
        return NodeAccess::run(fn (): array => $this->replace($root, $userId, $arguments['path'], $arguments['old'], $arguments['new'], $arguments['etag'] ?? null, (bool)($arguments['confirm_shared'] ?? false)));
    }

    public function preview(array $arguments, string $userId): array {
        $root = $this->rootFolder->getUserFolder($userId);
        return NodeAccess::run(fn (): array => $this->planReplace($root, $userId, $arguments['path'], $arguments['old'], $arguments['new'], $arguments['etag'] ?? null));
    }

    /**
     * Replaces one snippet, counting and substituting over the RAW bytes.
     *
     * The extracted text is only for the diff: it went through mb_scrub, which drops a BOM and turns every
     * byte of a Latin-1 file into "?", so writing it back would silently corrupt the whole file when the
     * snippet touches one line. `old` therefore has to be valid UTF-8, because a byte sequence the client
     * cannot express as text is not something we can locate safely, and the substitution happens on
     * whatever the file actually holds.
     *
     * @return array{content: list<array{type:string, text:string}>}
     * @throws ToolFailure when the snippet is absent or ambiguous, or the write is refused
     */
    private function replace(Folder $root, string $userId, string $path, string $old, string $new, ?string $etag, bool $confirmed): array {
        $path = PathGuard::normalize($path);
        $this->assertEditable($root, $path);
        if (strlen($new) > self::MAX_EDIT_BYTES) {
            throw new ToolFailure(EditingMessages::editTooLarge(self::MAX_EDIT_BYTES));
        }
        if (!mb_check_encoding($old, 'UTF-8')) {
            throw new ToolFailure(EditingMessages::snippetNotUtf8());
        }
        $file = $this->file($root, $path);
        $this->locks?->assertWritable($file, $userId, $path);
        if (($payload = $this->guard->guard($file, $userId, $path, $confirmed)) !== null) {
            return ToolResult::json($payload);
        }
        $raw = (string)$file->getContent();
        $occurrences = substr_count($raw, $old);
        if ($occurrences === 0) {
            throw new ToolFailure(EditingMessages::snippetMissing());
        }
        if ($occurrences > 1) {
            throw new ToolFailure(EditingMessages::snippetAmbiguous($occurrences));
        }
        // str_replace with a count of 1 cannot touch anything but the single occurrence we just verified.
        $updated = str_replace($old, $new, $raw, $count);
        if ($count !== 1) {
            throw new ToolFailure(EditingMessages::snippetAmbiguous($occurrences));
        }
        return ToolResult::json($this->writer->commit($root, $userId, $file, $path, $updated, $etag, $this->extractor->extract($file)));
    }

    /**
     * The plan of `files_replace`: the same diff, from the same snippet check the write performs.
     *
     * @param Folder $root the user's folder
     * @param string $userId authenticated user
     * @param string $path file to overwrite, user-relative
     * @param string $old snippet that must appear exactly once
     * @param string $new replacement text
     * @param string|null $etag ETag the caller read before, null to skip the check
     * @return array<string, mixed>
     * @throws ToolFailure when the snippet is absent or ambiguous, or the write would be refused
     */
    private function planReplace(Folder $root, string $userId, string $path, string $old, string $new, ?string $etag): array {
        $path = PathGuard::normalize($path);
        $this->assertEditable($root, $path);
        if (strlen($new) > self::MAX_EDIT_BYTES) {
            throw new ToolFailure(EditingMessages::editTooLarge(self::MAX_EDIT_BYTES));
        }
        if (!mb_check_encoding($old, 'UTF-8')) {
            throw new ToolFailure(EditingMessages::snippetNotUtf8());
        }
        $file = $this->file($root, $path);
        NodeAccess::checkEtag($file, $etag);
        $raw = (string)$file->getContent();
        $occurrences = substr_count($raw, $old);
        if ($occurrences === 0) {
            throw new ToolFailure(EditingMessages::snippetMissing());
        }
        if ($occurrences > 1) {
            throw new ToolFailure(EditingMessages::snippetAmbiguous($occurrences));
        }
        $updated = str_replace($old, $new, $raw, $count);

        return $this->contentPlan->describe($file, $userId, $path, [
            'action' => 'files_replace',
            'snippet' => ['occurrences' => $occurrences, 'replaced' => $count],
            'size' => ['before' => (int)$file->getSize(), 'after' => strlen($updated)],
            'diff' => UnifiedDiff::between($this->extractor->extract($file), $updated),
            'message' => EditingMessages::planReplace(),
        ]);
    }

    /**
     * files_edit and files_replace write text through the model, so they also need a text file.
     *
     * @param Folder $root the user's folder
     * @param string $path normalized user-relative path
     * @throws ToolFailure when the path is the backup folder or does not hold editable text
     */
    private function assertEditable(Folder $root, string $path): void {
        $this->assertNotBackup($path);
        if (!TextExtractor::isText($this->file($root, $path))) {
            throw new ToolFailure(EditingMessages::notText());
        }
    }

    /**
     * The folder a user recovers from is never written through text replacement.
     *
     * @param string $path normalized user-relative path
     * @throws ToolFailure when the path is inside the backup folder
     */
    private function assertNotBackup(string $path): void {
        if (BackupLocation::isBackupPath($path)) {
            throw new ToolFailure(EditingMessages::backupPath());
        }
    }

    /**
     * @param Folder $root the user's folder
     * @param string $path normalized user-relative path
     * @return File the readable node
     * @throws ToolFailure when it is not a file
     */
    private function file(Folder $root, string $path): File {
        return NodeAccess::requireFile(NodeAccess::get($root, $path, $this->visibilityGuard));
    }
}
