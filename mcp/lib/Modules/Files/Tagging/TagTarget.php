<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Tagging;

use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCP\Files\Folder;
use OCP\Files\ForbiddenException;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;

/** The file or folder a tagging tool names by path, behind the Files gate, or the not-found refusal. */
final class TagTarget {
	/** Static resolution only. */
	private function __construct() {
	}

	/**
	 * @param Folder $root the actor's root
	 * @param string $path normalized user-relative path
	 * @param FilesAccessGuard $guard Files scope, Guests external storage, exclusion tags and backups
	 * @return Node the visible, readable node at that path
	 * @throws ToolFailure the not-found refusal for a hidden, missing, unreadable or forbidden path
	 */
	public static function node(Folder $root, string $path, FilesAccessGuard $guard): Node {
		try {
			return NodeAccess::get($root, $path, $guard);
		} catch (ToolFailure|NotFoundException|NotPermittedException|ForbiddenException) {
			throw new ToolFailure(CommonMessages::notFound());
		}
	}
}
