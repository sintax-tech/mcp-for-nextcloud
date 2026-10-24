<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use OCA\Mcp\Service\Scope\FilesAccessGuard;
use OCA\Mcp\Tools\Common\LockAwareWrite;
use OCA\Mcp\Tools\Common\LockWriteFailure;
use OCA\Mcp\Tools\Common\NodeAccess;
use OCA\Mcp\Tools\Common\NodeAccessInfo;
use OCA\Mcp\Tools\ToolFailure;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Lock\LockedException;

/**
 * Backup, write and report for files_edit and files_replace.
 *
 * The backup service is an argument so a test that replaces the module's backup is the one that runs.
 * {@see FileBackup} does not read the file-type policy. This class asks {@see ContentTypeGate} before
 * the backup and again immediately before the write.
 */
final class ContentCommit {
	/**
	 * @param ContentTypeGate $types Current-name policy. A fresh snapshot at each boundary.
	 * @param NodeAccessInfo $access Access description of the file after the write.
	 * @param LockAwareWrite|null $locks Lock-aware write. Null uses the direct putContent branch.
	 * @param FilesAccessGuard|null $visibilityGuard Scope and exclusion tags around the write.
	 */
	public function __construct(
		private ContentTypeGate $types,
		private NodeAccessInfo $access,
		private ?LockAwareWrite $locks = null,
		private ?FilesAccessGuard $visibilityGuard = null,
	) {
	}

	/**
	 * Copies the original, then writes `$content`. A refusal before the copy leaves no backup.
	 * A refusal after the copy keeps the backup and reports the same partial state as a failed write.
	 *
	 * @param FileBackup $backup Backup of the module that is executing, not one captured earlier.
	 * @param Folder $root The user's folder.
	 * @param string $userId Authenticated user.
	 * @param File $file File about to be overwritten.
	 * @param string $path Normalized user-relative path of `$file`.
	 * @param string $content New content.
	 * @param string|null $etag ETag the caller read before, null to skip the check.
	 * @param string $before Content as read, for the diff.
	 * @return array{path:string, size:int, etag:string, access:array<string, mixed>, backup:string, diff:string}
	 * @throws ToolFailure When the preparation or the write fails, or the type policy refuses the name.
	 */
	public function commit(FileBackup $backup, Folder $root, string $userId, File $file, string $path, string $content, ?string $etag, string $before): array {
		// Before the copy: a refused name must not create a backup.
		$this->types->assertCurrentName($file);
		$copy = $backup->prepare($root, $file, $path, $userId, $etag);
		$this->visibilityGuard?->assertVisible($file);
		try {
			// After the copy and immediately before the write. A refusal here keeps the backup.
			$this->types->assertCurrentName($file);
			$backup->assertWritablePath($path);
			// Checked again right before the write: the backup took time, and files_lock does not refuse every lock.
			NodeAccess::mutate(fn () => $this->locks === null ? $file->putContent($content) : $this->locks->run(fn () => $file->putContent($content), $file, $userId, $path), $this->visibilityGuard);
		} catch (LockWriteFailure $e) {
			throw $e->withNote(FilesMessages::originalKept($copy));
		} catch (LockedException $e) {
			$failure = NodeAccess::lockFailure($e);
			throw $failure instanceof LockWriteFailure ? $failure->withNote(FilesMessages::originalKept($copy)) : new ToolFailure(FilesMessages::writeFailed($copy));
		} catch (\Throwable) {
			throw new ToolFailure(FilesMessages::writeFailed($copy));
		}
		$node = NodeAccess::requireFile(NodeAccess::get($root, $path, $this->visibilityGuard));
		return [
			'path' => $path,
			'size' => strlen($content),
			'etag' => (string)$node->getEtag(),
			'access' => $this->access->describe($node, $userId),
			'backup' => $copy,
			'diff' => UnifiedDiff::between($before, $content),
		];
	}
}
