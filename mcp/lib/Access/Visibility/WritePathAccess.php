<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Visibility;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;
use OCP\Files\Node;

/** Exclusion preflight for destinations and privacy tag propagation before backup content writes. */
interface WritePathAccess {
	/**
	 * Check the base, an existing target and the deepest existing destination ancestor through virtual visibility.
	 * @param Folder $root Native destination base folder.
	 * @param string $path User-relative destination path.
	 * @return bool False when configuration or any applicable existing boundary is hidden or unreadable.
	 */
	public function isPathAllowedForWrite(Folder $root, string $path): bool;

	/**
	 * Assign exclusions inherited by the source before writing backup content; clear virtually after each assignment attempt.
	 * @param Node $source Original file or folder whose inherited tags must be preserved.
	 * @param Node $destination Empty backup receiving those tags.
	 * @return void
	 * @throws ToolFailure With CommonMessages::backupFailed() when configuration, ancestry, lookup or assignment fails.
	 */
	public function copyHiddenTags(Node $source, Node $destination): void;
}
