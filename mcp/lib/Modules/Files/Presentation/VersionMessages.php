<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Presentation;

use OCA\Mcp\L10n\Translator;

/** Tool descriptions, parameter descriptions and user messages for Files version. */
final class VersionMessages {
	/** @return string description of files_versions_list */
	public static function versionsListTool(): string {
		return 'List versions of a file from the Versions app, from newest to oldest.';
	}

	/** @return string description of files_version_read */
	public static function versionReadTool(): string {
		return 'Read the text of a previous file version, with the same limit and text extraction as '
			. 'files_read.';
	}

	/** @return string description of files_version_restore */
	public static function versionRestoreTool(): string {
		return \OCA\Mcp\Modules\Files\Backups\BackupMessages::versionRestoreTool();
	}

	/** @return string description of the version parameter */
	public static function version(): string {
		return 'Version identifier, as returned by files_versions_list';
	}

	/**
	 * @return string the plan of files_version_restore
	 */
	public static function planRestore(): string {
		return \OCA\Mcp\Modules\Files\Backups\BackupMessages::planRestore();
	}

	/** @return string versioning is required and not active */
	public static function versionsOff(): string {
		return Translator::t('File versioning (files_versions) is not active on this account.');
	}

	/**
	 * @param string $requested version identifier asked for
	 * @return string no version with that identifier
	 */
	public static function versionMissing(string $requested): string {
		return Translator::t('Version not found for this file: %s.', [$requested]);
	}

	/** @return string the version could not be read */
	public static function versionUnreadable(): string {
		return Translator::t('Could not read content of this version.');
	}

	/** @return string the rollback did not report success */
	public static function versionRestoreFailed(): string {
		return Translator::t('Could not restore this version.');
	}
}
