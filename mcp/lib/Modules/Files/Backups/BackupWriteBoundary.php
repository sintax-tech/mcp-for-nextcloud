<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Backups;

use OCA\Mcp\Platform\Backups\BackupLocation;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/** Fresh reservation admission, including checkout links issued before the administrator changed destinations. */
final class BackupWriteBoundary {
	/**
	 * @param string $path normalized actor-relative write path
	 * @param BackupLocation|null $location live reserved-name document
	 * @return void
	 * @throws ToolFailure if the path is reserved or reservation metadata cannot be read
	 */
	public static function check(string $path, ?BackupLocation $location): void {
		if (BackupLocation::contains($path, $location)) {
			throw new ToolFailure(\OCA\Mcp\Modules\Files\Presentation\EditingMessages::backupPath());
		}
	}

	/**
	 * @param string $path normalized actor-relative path
	 * @param BackupLocation|null $location live reserved-name document
	 * @return string the admitted path
	 * @throws ToolFailure when a reserved path cannot be written
	 */
	public static function checked(string $path, ?BackupLocation $location): string {
		self::check($path, $location);
		return $path;
	}

	/**
	 * @param FileBackup $backup live admission service
	 * @param string $path original actor-relative path
	 * @param string $copy verified copy path, or empty for an authorized suspension
	 * @return void
	 * @throws ToolFailure for this reservation refusal, carrying the truthful recovery note
	 */
	public static function afterCopy(FileBackup $backup, string $path, string $copy): void {
		try {
			$backup->assertWritablePath($path);
		} catch (ToolFailure $error) {
			throw new ToolFailure($error->getMessage() . ' ' . \OCA\Mcp\Modules\Files\Presentation\EditingMessages::originalKept($copy));
		}
	}
}
