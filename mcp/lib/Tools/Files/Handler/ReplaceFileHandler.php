<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files\Handler;

use OCA\Mcp\Service\Scope\FilesAccessGuard;
use OCA\Mcp\Tools\ArgumentValidationException;
use OCA\Mcp\Tools\Common\LockAwareWrite;
use OCA\Mcp\Tools\Common\NodeAccess;
use OCA\Mcp\Tools\Common\PathGuard;
use OCA\Mcp\Tools\Common\SharedWriteGuard;
use OCA\Mcp\Tools\Files\ContentCommit;
use OCA\Mcp\Tools\Files\ContentTypeGate;
use OCA\Mcp\Tools\Files\ContentWritePlan;
use OCA\Mcp\Tools\Files\FileBackup;
use OCA\Mcp\Tools\Files\FilesMessages;
use OCA\Mcp\Tools\Files\FilesModule;
use OCA\Mcp\Tools\Files\ReplaceSource;
use OCA\Mcp\Tools\Files\TextExtractor;
use OCA\Mcp\Tools\Files\UnifiedDiff;
use OCA\Mcp\Tools\Files\UniqueSnippet;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Tools\ToolResult;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;

/**
 * Backs `files_replace`: one unique snippet, counted and substituted over the raw bytes.
 *
 * {@see ReplaceSource} only reads and keeps its limits. {@see UniqueSnippet} is unchanged.
 * Text, size, the snippet check, ETag and lock run before the file-type policy.
 */
final class ReplaceFileHandler {
	/**
	 * @param TextExtractor $extractor Reads the current text for the diff only. The substitution uses the raw bytes.
	 * @param SharedWriteGuard $shared Confirmation of a write outside the personal scope.
	 * @param ContentWritePlan $plans Shared plan fields. No backup is taken there.
	 * @param ContentCommit $commit Backup and write. Receives the module's backup at execution time.
	 * @param ContentTypeGate $types Current-name policy, one snapshot per boundary.
	 * @param LockAwareWrite|null $locks Refuses a locked file before any backup. Null where files_lock is not wired.
	 * @param \OCA\Mcp\Service\BackupLocation|null $backupLocation configured protected roots
	 * @param FilesAccessGuard|null $visibilityGuard Scope and exclusion tags of the resolved file.
	 */
	public function __construct(
		private TextExtractor $extractor,
		private SharedWriteGuard $shared,
		private ContentWritePlan $plans,
		private ContentCommit $commit,
		private ContentTypeGate $types,
		private ?LockAwareWrite $locks = null,
		private ?FilesAccessGuard $visibilityGuard = null,
		private ?\OCA\Mcp\Service\BackupLocation $backupLocation = null,
	) {
	}

	/**
	 * The plan of `files_replace`: the same diff, from the same snippet check the write performs.
	 *
	 * @param Folder $root The user's folder.
	 * @param string $userId Authenticated user.
	 * @param string $path File to overwrite, user-relative.
	 * @param string $old Snippet that must appear exactly once.
	 * @param string $new Replacement text.
	 * @param string|null $etag ETag the caller read before, null to skip the check.
	 * @return array<string, mixed> Plan. Nothing is written and no backup is created.
	 * @throws ArgumentValidationException When {@see PathGuard::normalize()} rejects '..' or a control character.
	 * @throws NotFoundException When {@see NodeAccess::get()} does not find the path.
	 * @throws ToolFailure When the file is over the read ceiling, the snippet is absent or ambiguous, or the write would be refused.
	 */
	public function plan(Folder $root, string $userId, string $path, string $old, string $new, ?string $etag): array {
		$path = PathGuard::normalize($path);
		$this->assertEditable($root, $path);
		if (strlen($new) > FilesModule::MAX_EDIT_BYTES) {
			throw new ToolFailure(FilesMessages::editTooLarge(FilesModule::MAX_EDIT_BYTES));
		}
		UniqueSnippet::assertText($old);
		$file = $this->file($root, $path);
		NodeAccess::checkEtag($file, $etag);
		$raw = ReplaceSource::read($file);
		$updated = UniqueSnippet::replace($raw, $old, $new);
		$this->types->assertCurrentName($file);
		return $this->plans->describe($file, $userId, $path, [
			'action' => 'files_replace',
			// UniqueSnippet only answers for a snippet found, and replaced, exactly once.
			'snippet' => ['occurrences' => 1, 'replaced' => 1],
			'size' => ['before' => (int)$file->getSize(), 'after' => strlen($updated)],
			'diff' => UnifiedDiff::between($this->extractor->extract($file), $updated),
			'message' => FilesMessages::planReplace(),
		]);
	}

	/**
	 * Replaces one snippet. The extracted text is only for the diff: it went through mb_scrub, which drops a BOM
	 * and turns every byte of a Latin-1 file into "?", so writing it back would silently corrupt the whole file
	 * when the snippet touches one line. `old` therefore has to be valid UTF-8, and the substitution happens on
	 * whatever the file actually holds.
	 *
	 * @param Folder $root The user's folder.
	 * @param string $userId Authenticated user.
	 * @param string $path File to change, user-relative.
	 * @param string $old Snippet that must appear exactly once, overlapping occurrences included ({@see UniqueSnippet}).
	 * @param string $new Replacement text.
	 * @param string|null $etag ETag the caller read before, null to skip the check.
	 * @param bool $confirmed Whether the caller passed confirm_shared. This does not bypass the type policy.
	 * @param FileBackup $backup Backup service of the module executing this call.
	 * @return array{content: list<array{type:string, text:string}>} The write report, or the shared-write confirmation.
	 * @throws ArgumentValidationException When {@see PathGuard::normalize()} rejects '..' or a control character.
	 * @throws NotFoundException When {@see NodeAccess::get()} does not find the path.
	 * @throws ToolFailure When the file is over the read ceiling, the snippet is absent or ambiguous, or the write is refused.
	 */
	public function execute(Folder $root, string $userId, string $path, string $old, string $new, ?string $etag, bool $confirmed, FileBackup $backup): array {
		$path = PathGuard::normalize($path);
		$this->assertEditable($root, $path);
		if (strlen($new) > FilesModule::MAX_EDIT_BYTES) {
			throw new ToolFailure(FilesMessages::editTooLarge(FilesModule::MAX_EDIT_BYTES));
		}
		UniqueSnippet::assertText($old);
		$file = $this->file($root, $path);
		$this->locks?->assertWritable($file, $userId, $path);
		// Before the shared-write confirmation, and before the raw read: a denied type does not touch the file.
		$this->types->assertCurrentName($file);
		if (($payload = $this->shared->guard($file, $userId, $path, $confirmed)) !== null) {
			return ToolResult::json($payload);
		}
		$raw = ReplaceSource::read($file);
		$updated = UniqueSnippet::replace($raw, $old, $new);
		return ToolResult::json($this->commit->commit($backup, $root, $userId, $file, $path, $updated, $etag, $this->extractor->extract($file)));
	}

	/**
	 * files_replace writes text through the model, so the path must be editable text outside the backup folder.
	 *
	 * @param Folder $root The user's folder.
	 * @param string $path Normalized user-relative path.
	 * @return void
	 * @throws ToolFailure When the path is the backup folder or does not hold editable text.
	 */
	private function assertEditable(Folder $root, string $path): void {
		if (FileBackup::isBackupPath($path, $this->backupLocation)) {
			throw new ToolFailure(FilesMessages::backupPath());
		}
		if (!TextExtractor::isText($this->file($root, $path))) {
			throw new ToolFailure(FilesMessages::notText());
		}
	}

	/**
	 * @param Folder $root The user's folder.
	 * @param string $path Normalized user-relative path.
	 * @return File The readable node.
	 * @throws ArgumentValidationException When {@see PathGuard::normalize()} rejects '..' or a control character.
	 * @throws NotFoundException When {@see NodeAccess::get()} does not find the path.
	 * @throws ToolFailure When it is not a file.
	 */
	private function file(Folder $root, string $path): File {
		return NodeAccess::requireFile(NodeAccess::get($root, $path, $this->visibilityGuard));
	}
}
