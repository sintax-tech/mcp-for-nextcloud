<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Presentation;

/** Tool descriptions for Files tagging. */
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
}
