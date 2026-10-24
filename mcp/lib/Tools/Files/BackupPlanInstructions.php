<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use OCA\Mcp\L10n\Translator;

/** Tool-specific confirmation instructions when the administrator suspended MCP pre-write copies. */
final class BackupPlanInstructions {
	/**
	 * @param string $action tool name from the existing plan
	 * @return string truthful confirmation retaining the tool's concrete operation
	 */
	public static function withoutCopy(string $action): string {
		return match ($action) {
			'files_checkout' => Translator::t('Nothing was changed. After confirmation temporary download and upload links are issued. Upload replaces the whole file and is refused if the file changed since checkout. No MCP pre-write copy is created while native versioning is enabled for this user.'),
			'files_version_restore' => Translator::t('Nothing was changed. After confirmation the selected version is written back to the file. No MCP pre-write copy is created while native versioning is enabled for this user.'),
			'files_edit' => Translator::t('Nothing was changed. After confirmation the file is replaced by the content below. No MCP pre-write copy is created while native versioning is enabled for this user.'),
			'files_replace' => Translator::t('Nothing was changed. After confirmation the snippet below is replaced. No MCP pre-write copy is created while native versioning is enabled for this user.'),
			default => Translator::t('Nothing was changed. After confirmation the requested operation follows the administrator backup setting: no MCP pre-write copy is created while native versioning is enabled for this user.'),
		};
	}
}
