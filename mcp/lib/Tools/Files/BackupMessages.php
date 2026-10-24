<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use OCA\Mcp\L10n\Translator;

/** Backup-related wording shared by tools, plans and failures, without fixed destination assumptions. */
final class BackupMessages {
	/** @return string description of files_edit */
	public static function editTool(): string {
		return 'Replace the content of an existing text file and return the diff. Before writing, requires '
			. 'active versioning. MCP copies follow the administrator backup setting. If the file is outside your personal '
			. 'folder, only write after asking the user and retrying the call with confirm_shared. '
			. 'Never creates, moves, or deletes files.';
	}

	/** @return string description of files_replace */
	public static function replaceTool(): string {
		return 'Replace a unique snippet of an existing text file and return the diff. The specified snippet '
			. 'must appear exactly once. Before writing, requires active versioning and applies the administrator backup setting. If the file is outside your personal folder, only write after '
			. 'asking the user and retrying the call with confirm_shared.';
	}

	/** @return string description of files_checkout */
	public static function checkoutTool(): string {
		return 'Provide temporary download and upload links to edit any file type (document, spreadsheet, PDF, '
			. 'image) with local tools (curl) without passing content through the model, up to the upload limit. '
			. 'Upload raw bytes with curl -T /tmp/file -X PUT -H "Content-Type: application/octet-stream" "$UPLOAD_URL". '
			. 'A text file can also be edited directly with files_edit or files_replace. Links are valid for 5 to 15 minutes, single-use, '
			. 'and bound to you, the file, and current ETag: if the file changes before upload, nothing is '
			. 'written. Upload follows the same administrator backup setting as files_edit. If the file is outside your personal '
			. 'folder, only issue links after asking the user and retrying with confirm_shared.';
	}

	/** @return string description of files_version_restore */
	public static function versionRestoreTool(): string {
		return 'Restore a previous file version. Pre-write copies use the configured MCP backup folder unless suspended by the administrator while native versioning is enabled. If the file is outside your personal folder, '
			. 'restore only after asking the user and retrying with confirm_shared.';
	}

	/**
	 * @return string the plan of files_edit
	 */
	public static function planEdit(): string {
		return Translator::t('Nothing was changed. After your approval the file is replaced by the content below and a copy of the current content is kept in the backup folder.');
	}

	/**
	 * @return string the plan of files_replace
	 */
	public static function planReplace(): string {
		return Translator::t('Nothing was changed. After your approval the snippet below is replaced and a copy of the current content is kept in the backup folder.');
	}

	/**
	 * @return string the plan of files_version_restore
	 */
	public static function planRestore(): string {
		return Translator::t('Nothing was changed. After your approval a copy of the current content is kept in the backup folder and the content of the version below is written back to the file; Nextcloud versioning applies as to any edit, according to its policy.');
	}

	/**
	 * @return string what the backup taken by a write gives back
	 */
	public static function planBackupConsequence(): string {
		return Translator::t('The previous content stays available in the backup folder. Nextcloud may also keep versions of the file, according to its versioning policy.');
	}

	/**
	 * @return string what the links of a checkout mean
	 */
	public static function planCheckoutConsequence(): string {
		return Translator::t('The upload replaces the whole file, takes the same backup as an edit and is refused if the file changed since the checkout.');
	}

	/** @return string an edit inside the backup folder */
	public static function backupPath(): string {
		return Translator::t('MCP backup folders, including previously configured names, are protected against writes through MCP.');
	}

	/**
	 * @param string $backup path of the verified copy of the original
	 * @return string a failed write, naming where the original still is
	 */
	public static function writeFailed(string $backup): string {
		return $backup === '' ? Translator::t('Failed to write file. No MCP pre-write copy was created; native versioning follows its own policy.') : Translator::t('Failed to write file; original preserved in %s.', [$backup]);
	}

	/**
	 * @param string $backup user-relative path of the backup taken before the refused write
	 * @return string closing sentence of a lock refusal that came after the backup
	 */
	public static function originalKept(string $backup): string {
		return $backup === '' ? Translator::t('No MCP pre-write copy was created; native versioning follows its own policy.') : Translator::t('The original is kept in %s.', [$backup]);
	}
}
