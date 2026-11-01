<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Files;

use OCA\Mcp\Service\VisibilityGuard;
use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Platform\FileAccess\PathGuard;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;
use OCP\Files\Node;

/** D7 uses metadata only. Callers check node visibility first (D6), then this gate, then recursive helpers. */
final class FolderExposureGate {
	public static function active(?VisibilityGuard $guard): bool {
		return $guard !== null && (!$guard->isConfigurationValid() || $guard->getHiddenTagIds() !== []);
	}

	public static function assertExposure(Node $node, ?VisibilityGuard $guard): void {
		if (NodeAccess::isFolder($node) && self::active($guard)) {
			throw new ToolFailure(FilesMessages::folderExposureRefused());
		}
	}

	/** A folder rename is allowed only when both actual parents have the same positive canonical file id. */
	public static function assertMove(Folder $root, Node $source, string $to, ?VisibilityGuard $guard): void {
		if (!NodeAccess::isFolder($source) || !self::active($guard)) {
			return;
		}
		try {
			$parentPath = dirname(PathGuard::normalize($to));
			$targetParent = $parentPath === '/' ? $root : $root->get(ltrim($parentPath, '/'));
			// Above a mount root core lends the parent another storage's id; the guard resolves the real one.
			$sourceParent = $guard->parentOf($source);
			$sameParent = $targetParent instanceof Folder && $sourceParent instanceof Folder
				&& (int)$sourceParent->getId() > 0
				&& (int)$sourceParent->getId() === (int)$targetParent->getId();
		} catch (\Throwable) {
			$sameParent = false;
		}
		if (!$sameParent) {
			throw new ToolFailure(FilesMessages::folderExposureRefused());
		}
	}
}
