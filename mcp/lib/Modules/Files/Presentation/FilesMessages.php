<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Presentation;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Platform\FileAccess\CommonMessages;

/** Formats files messages. */
final class FilesMessages {
	/** Folder in the user's root that receives a copy of every file before an edit writes it. */
	public const BACKUP_FOLDER = \OCA\Mcp\Modules\Files\FileBackup::FOLDER;

	/** D7 refusal for folder operations that could expose an unverified subtree. @return string message text */
	public static function folderExposureRefused(): string {
		return Translator::t('Folders cannot be shared, copied or moved to another directory through this connector while files are excluded from MCP access. Use the Nextcloud Files interface for these folder operations.');
	}

	/** @return string description of the path parameter */
	public static function path(): string {
		return 'Path of the file or folder, e.g. /Documents/report.pdf';
	}

	/** @return string description of the etag parameter */
	public static function etag(): string {
		return 'Previously read ETag; if it differs, nothing is written';
	}

	/** @return string description of the confirm_shared parameter */
	public static function confirmShared(): string {
		return 'Required only when the file is outside your personal folder (shared, team folder, '
			. 'or external storage). Send only after confirming with the user.';
	}

	/** @return string a path that is not a folder */
	public static function notAFolder(): string {
		return Translator::t('The specified path is not a folder.');
	}

	/** @return string a path that is not a file */
	public static function notAFile(): string {
		return Translator::t('The specified path is not a file.');
	}

	/** @return string the backup copy could not be created or verified */
	public static function backupFailed(): string {
		return CommonMessages::backupFailed();
	}

	/** @return string something already sits where a folder would go */
	public static function destinationExists(): string {
		return Translator::t('A file or folder already exists at this destination.');
	}

}
