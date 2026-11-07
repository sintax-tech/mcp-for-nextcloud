<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Contacts\Backup;

/**
 * Mutex key for one user's contact-backup folder.
 *
 * Nextcloud stores it in `file_locks.key`, a varchar(64). A longer value is truncated on insert, and
 * {@see \OC\Lock\DBLockingProvider::releaseLock()} then updates zero rows, so the next backup of the same
 * folder stays locked. This key is built to that width, prefix included.
 */
final class ContactBackupLock {
	/** `oc_file_locks.key` width on Nextcloud 35. */
	public const MAX_LENGTH = 64;

	/**
	 * Static key only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * Lock key for the backup folder of one user.
	 *
	 * The `mcp-contact-backup/` prefix stays visible. Only the first characters of the sha256 are kept, so the
	 * value that is acquired is the value that is stored and released.
	 *
	 * @param string $userId Account that owns the backup.
	 * @param string $folderPath Absolute folder path from {@see \OCP\Files\Node::getPath()}.
	 * @return string Key of at most {@see self::MAX_LENGTH} characters.
	 */
	public static function key(string $userId, string $folderPath): string {
		$prefix = 'mcp-contact-backup/';
		$hash = hash('sha256', $userId . '\0' . $folderPath);
		return $prefix . substr($hash, 0, self::MAX_LENGTH - strlen($prefix));
	}
}
