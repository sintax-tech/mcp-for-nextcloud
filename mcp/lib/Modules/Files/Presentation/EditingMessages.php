<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Presentation;

use OCA\Mcp\L10n\Translator;

/** Tool descriptions, parameter descriptions and user messages for Files editing. */
final class EditingMessages {
	/** @return string description of files_edit */
	public static function editTool(): string {
		return \OCA\Mcp\Modules\Files\Backups\BackupMessages::editTool();
	}

	/** @return string description of files_replace */
	public static function replaceTool(): string {
		return \OCA\Mcp\Modules\Files\Backups\BackupMessages::replaceTool();
	}

	/** @return string description of the content parameter of files_edit */
	public static function content(): string {
		return 'Complete new content (UTF-8)';
	}

	/** @return string description of the old parameter of files_replace */
	public static function oldSnippet(): string {
		return 'Snippet to replace; must appear exactly once in the file';
	}

	/** @return string description of the new parameter of files_replace */
	public static function newSnippet(): string {
		return 'Replacement text for the specified snippet';
	}

	/**
	 * @return string the plan of files_edit
	 */
	public static function planEdit(): string {
		return \OCA\Mcp\Modules\Files\Backups\BackupMessages::planEdit();
	}

	/**
	 * @return string the plan of files_replace
	 */
	public static function planReplace(): string {
		return \OCA\Mcp\Modules\Files\Backups\BackupMessages::planReplace();
	}

	/**
	 * @return string what the backup taken by a write gives back
	 */
	public static function planBackupConsequence(): string {
		return \OCA\Mcp\Modules\Files\Backups\BackupMessages::planBackupConsequence();
	}

	/** @return string a non-text file */
	public static function notText(): string {
		return Translator::t('Only text files can be edited via MCP.');
	}

	/** @return string an edit inside the backup folder */
	public static function backupPath(): string {
		return \OCA\Mcp\Modules\Files\Backups\BackupMessages::backupPath();
	}

	/**
	 * @param int $limit maximum accepted size in bytes
	 * @return string a content over the edit limit
	 */
	public static function editTooLarge(int $limit): string {
		return Translator::t('Content exceeds the edit limit of %s bytes.', [(string)$limit]);
	}

	/**
	 * @param string $backup path of the verified copy of the original
	 * @return string a failed write, naming where the original still is
	 */
	public static function writeFailed(string $backup): string {
		return \OCA\Mcp\Modules\Files\Backups\BackupMessages::writeFailed($backup);
	}

	/**
	 * @param string $backup user-relative path of the backup taken before the refused write
	 * @return string closing sentence of a lock refusal that came after the backup
	 */
	public static function originalKept(string $backup): string {
		return \OCA\Mcp\Modules\Files\Backups\BackupMessages::originalKept($backup);
	}

	/** @return string versioning is off for the user */
	public static function versioningOff(): string {
		return Translator::t('Editing blocked: file versioning (files_versions) is not active.');
	}

	/** @return string the snippet given to files_replace does not occur */
	public static function snippetMissing(): string {
		return \OCA\Mcp\Platform\Text\SnippetMessages::missing();
	}

	/** @return string the snippet given to files_replace is not text we can locate in the file */
	public static function snippetNotUtf8(): string {
		return \OCA\Mcp\Platform\Text\SnippetMessages::notUtf8();
	}

	/**
	 * The search stops at the second occurrence, so the message gives no count.
	 * @return string the snippet given to files_replace or notes_edit is ambiguous
	 */
	public static function snippetAmbiguous(): string {
		return \OCA\Mcp\Platform\Text\SnippetMessages::ambiguous();
	}
}
