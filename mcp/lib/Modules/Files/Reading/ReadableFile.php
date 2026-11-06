<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reading;

use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\File;
use OCP\Files\Folder;
use OCA\Mcp\Access\ResourceScope\FilesAccessGuard;

/** Preserves the existing Files ReadableFile behavior. */
final class ReadableFile {
	/**
	 * @param ?FilesAccessGuard $visibilityGuard existing visibilityGuard collaborator
	 */
	public function __construct(
		private ?FilesAccessGuard $visibilityGuard = null,
	) {
	}

	/**
	 * @param Folder $root the user's folder
	 * @param string $path normalized user-relative path
	 * @return File the readable node
	 * @throws ToolFailure when it is not a file
	 */
	public function get(Folder $root, string $path): File {
		return NodeAccess::requireFile(NodeAccess::get($root, $path, $this->visibilityGuard));
	}
}
