<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Files\Deletion\Folder;

use OCA\Mcp\L10n\Translator;

/** Folder-specific limits and measured outcomes, without exposing refused descendants. */
final class DeleteFolderMessages {
	/** @return string Generic complete-tree refusal, including configured hidden tags and inspection limits. */
	public static function refused(): string {
		return Translator::t('The folder tree could not be admitted completely. Roots, shared or mounted contents, hidden or out-of-scope contents, unreadable permissions and inspection limits are refused. Nothing was deleted by this call.');
	}

	/** @return string Same inspected tree observed, without claiming transactional preservation. */
	public static function preserved(): string {
		return Translator::t('The folder and its inspected contents are still present with the same observed identities and ETags. No automatic retry was made.');
	}

	/** @return string Absence is not a recovery receipt. */
	public static function removed(): string {
		return Translator::t('The folder is no longer at its original path. Its trash destination and recoverability have not been confirmed. No recovery copy was created by MCP.');
	}
}
