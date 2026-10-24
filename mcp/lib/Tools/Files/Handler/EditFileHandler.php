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
use OCA\Mcp\Tools\Files\TextExtractor;
use OCA\Mcp\Tools\Files\UnifiedDiff;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Tools\ToolResult;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;

/**
 * Backs `files_edit`: the plan and the confirmed overwrite of one text file.
 *
 * Text, size, ETag and lock rules run before the file-type policy. The policy runs before the shared-write
 * confirmation, and {@see ContentCommit} reads it again before the backup and before the write.
 */
final class EditFileHandler {
	/**
	 * @param TextExtractor $extractor Reads the current text for the diff. The write stores the caller's bytes.
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
	 * The plan of `files_edit`: the diff the write would produce and the backup it would take first.
	 *
	 * @param Folder $root The user's folder.
	 * @param string $userId Authenticated user.
	 * @param string $path File to overwrite, user-relative.
	 * @param string $content New complete content.
	 * @param string|null $etag ETag the caller read before, null to skip the check.
	 * @return array<string, mixed> Plan. Nothing is written and no backup is created.
	 * @throws ArgumentValidationException When {@see PathGuard::normalize()} rejects '..' or a control character.
	 * @throws NotFoundException When {@see NodeAccess::get()} does not find the path.
	 * @throws ToolFailure For a non-text file, an oversized content, a stale ETag, a denied type or a denied write.
	 */
	public function plan(Folder $root, string $userId, string $path, string $content, ?string $etag): array {
		$path = PathGuard::normalize($path);
		$this->assertEditable($root, $path);
		if (strlen($content) > FilesModule::MAX_EDIT_BYTES) {
			throw new ToolFailure(FilesMessages::editTooLarge(FilesModule::MAX_EDIT_BYTES));
		}
		$file = $this->file($root, $path);
		NodeAccess::checkEtag($file, $etag);
		$this->types->assertCurrentName($file);
		$diff = UnifiedDiff::between($this->extractor->extract($file), $content);
		return $this->plans->describe($file, $userId, $path, [
			'action' => 'files_edit',
			'size' => ['before' => (int)$file->getSize(), 'after' => strlen($content)],
			'diff' => $diff,
			'message' => FilesMessages::planEdit(),
		]);
	}

	/**
	 * Overwrites one text file. The backup is the module's, passed in so a replaced collaborator is used.
	 *
	 * @param Folder $root The user's folder.
	 * @param string $userId Authenticated user.
	 * @param string $path File to overwrite, user-relative.
	 * @param string $content New complete content.
	 * @param string|null $etag ETag the caller read before, null to skip the check.
	 * @param bool $confirmed Whether the caller passed confirm_shared. This does not bypass the type policy.
	 * @param FileBackup $backup Backup service of the module executing this call.
	 * @return array{content: list<array{type:string, text:string}>} The write report, or the shared-write confirmation.
	 * @throws ArgumentValidationException When {@see PathGuard::normalize()} rejects '..' or a control character.
	 * @throws NotFoundException When {@see NodeAccess::get()} does not find the path.
	 * @throws ToolFailure For a non-text file, an oversized content, a stale ETag, a lock, a denied type or a failed write.
	 */
	public function execute(Folder $root, string $userId, string $path, string $content, ?string $etag, bool $confirmed, FileBackup $backup): array {
		$path = PathGuard::normalize($path);
		$this->assertEditable($root, $path);
		if (strlen($content) > FilesModule::MAX_EDIT_BYTES) {
			throw new ToolFailure(FilesMessages::editTooLarge(FilesModule::MAX_EDIT_BYTES));
		}
		$file = $this->file($root, $path);
		$this->locks?->assertWritable($file, $userId, $path);
		// Before the shared-write confirmation: a denied type must not return a confirmation payload.
		$this->types->assertCurrentName($file);
		if (($payload = $this->shared->guard($file, $userId, $path, $confirmed)) !== null) {
			return ToolResult::json($payload);
		}
		return ToolResult::json($this->commit->commit($backup, $root, $userId, $file, $path, $content, $etag, $this->extractor->extract($file)));
	}

	/**
	 * files_edit writes text through the model, so the path must be editable text outside the backup folder.
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
