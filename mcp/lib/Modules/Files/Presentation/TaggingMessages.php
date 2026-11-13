<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Presentation;

use OCA\Mcp\L10n\Translator;

/** Tool descriptions, parameter descriptions and user messages for Files tagging. */
final class TaggingMessages {
	/** Static texts only. */
	private function __construct() {
	}

	/** @return string description of files_list_tags */
	public static function listTagsTool(): string {
		return 'List the collaborative (system) tags of one file or folder the user can see, read through the native '
			. 'Nextcloud tag relation. Only tags the user may see are returned, each with its id, name and the native '
			. 'flags user_visible, user_assignable and can_assign. Tags the administrator uses to hide files from MCP are '
			. 'never listed. A hidden, missing or unreadable path is answered as not found. Read-only.';
	}

	/** @return string description of files_set_tag */
	public static function setTagTool(): string {
		return 'Assign one existing collaborative (system) tag to one file or folder, or remove it, through the native '
			. 'Nextcloud tag relation. Name the tag by tag_id (from files_list_tags) or by its exact name, never both. '
			. 'Nextcloud decides as in the web interface: the tag must be one the user may see and assign, and the user '
			. 'must be allowed to change the file. Tags the administrator uses to hide files from MCP can never be '
			. 'assigned or removed here. A hidden, missing or unchangeable file and an unknown or unusable tag get the '
			. 'same not-found answer. Tags are seen by everyone who sees the file and may start automated rules. No '
			. 'file content changes and nothing is deleted. Requires the files.tags permission, a plan, confirm: true '
			. 'with its plan_state, and confirm_shared for a file of someone else.';
	}

	/** @return string description of the tag_id parameter */
	public static function tagId(): string {
		return 'ID of an existing tag, as files_list_tags returns it. Give tag_id or tag, not both.';
	}

	/** @return string description of the tag parameter */
	public static function tagName(): string {
		return 'Exact name of an existing tag, as Nextcloud shows it. Give tag_id or tag, not both.';
	}

	/** @return string description of the assigned parameter */
	public static function assigned(): string {
		return 'true to assign the tag to the file, false to remove it from the file.';
	}

	/** @return string refusal when both or neither of tag_id and tag are given */
	public static function oneOfTagIdOrName(): string {
		return Translator::t('Give exactly one of tag_id and tag.');
	}

	/** @return string refusal when the files.tags permission was withdrawn after the plan */
	public static function notAllowed(): string {
		return Translator::t('Changing file tags is not allowed for your account.');
	}

	/** @return string warning attached to a plan whose state changed since the approved one */
	public static function planChanged(): string {
		return Translator::t('The file or its tags changed since the plan. Review this new plan before confirming again.');
	}

	/** @return string error of a confirmed change the native pipeline did not finish */
	public static function writeFailed(): string {
		return Translator::t('Nextcloud did not finish the tag change. The tags read back now are shown.');
	}
}
