<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use OCA\Mcp\Tools\Common\NodeAccess;
use OCA\Mcp\Tools\Common\LockAwareWrite;
use OCA\Mcp\Tools\Common\LockMessages;
use OCA\Mcp\Tools\Common\LockWriteFailure;
use OCA\Mcp\Tools\ToolFailure;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Lock\ManuallyLockedException;

/** Creates and verifies the original copy in the current configured destination. */
final class FileBackupCopy {
	/**
	 * @param \OCA\Mcp\Service\VisibilityGuard|null $visibilityGuard existing privacy preservation
	 * @param LockAwareWrite|null $locks existing lock-aware mutation gateway
	 * @param \OCA\Mcp\Service\BackupLocation|null $location current destination reader
	 */
	public function __construct(private ?\OCA\Mcp\Service\VisibilityGuard $visibilityGuard,
		private ?LockAwareWrite $locks, private ?\OCA\Mcp\Service\BackupLocation $location) {
	}

	/**
	 * @param Folder $root acting user's personal root
	 * @param File $file original to copy
	 * @param string $path normalized original path
	 * @param string $stamp actor-local timestamp
	 * @param string $userId acting user uid
	 * @param bool $asUser whether the write runs in the actor's session
	 * @return string verified copy path
	 * @throws ToolFailure on any copy, verification or lock failure
	 */
	public function copy(Folder $root, File $file, string $path, string $stamp, bool $asUser, string $userId): string {
		// The node the copy was working on when it failed, so a lock refusal names the backup and not the original.
		$target = $root;
		try {
			$folder = NodeAccess::mutate(fn () => NodeAccess::ensureFolder($root, ($this->location?->current()['folder'] ?? \OCA\Mcp\Service\BackupLocation::LEGACY) . dirname($path)), $this->visibilityGuard);
			$target = $folder;
			$base = $file->getName() . '.' . $stamp;
			$name = $base . '.bak';
			for ($i = 2; $folder->nodeExists($name); $i++) {
				$name = $base . '-' . $i . '.bak';
			}
			// Establish privacy while the new backup contains no sensitive bytes.
			$copy = NodeAccess::mutate(fn () => $folder->newFile($name), $this->visibilityGuard);
			$target = $copy;
			$this->visibilityGuard?->copyHiddenTags($file, $copy);
			$stream = $file->fopen('rb');
			if (!is_resource($stream)) {
				throw new \RuntimeException('Backup source stream unavailable');
			}
			try {
				// The new copy is checked right before the original goes into it: a WebDAV client may have locked it.
				$put = static fn () => $copy->putContent($stream);
				NodeAccess::mutate(fn () => $this->locks === null ? $put() : $this->locks->run($put, $copy, $userId, null, $asUser), $this->visibilityGuard);
			} finally {
				if (is_resource($stream)) {
					fclose($stream);
				}
			}
			$valid = $copy->getSize() === $file->getSize();
		} catch (LockWriteFailure $e) {
			throw $e->withNote(FilesMessages::backupFailed());
		} catch (ManuallyLockedException $e) {
			// A backup folder locked by files_lock is a lock, and stays one: the checkout answers it with 423.
			$failure = $this->locks?->failure($e, $target, $userId) ?? NodeAccess::lockFailure($e);
			throw ($failure instanceof LockWriteFailure ? $failure : new LockWriteFailure(LockMessages::lockedWithoutDetails()))
				->withNote(FilesMessages::backupFailed());
		} catch (\Throwable) {
			$valid = false;
		}
		if (!$valid) {
			throw new ToolFailure(FilesMessages::backupFailed());
		}
		return $root->getRelativePath($copy->getPath()) ?? '';
	}
}
