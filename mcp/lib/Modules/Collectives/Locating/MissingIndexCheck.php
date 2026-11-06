<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Locating;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Collectives\Presentation\CollectivesMessages;
use OCP\Files\File;
use OCP\Files\Folder;

/**
 * Reading the page tree is only side-effect free while every folder that holds pages has its Readme.md: every proven
 * Collectives release creates a missing one while it builds the tree (4.0.0 and 4.4.0 in getPagesFromFolder, 4.5.0
 * and later in PageInfoTreeBuilder::createIndexPage), whatever forceIndex says. This walks the folder through Files,
 * which only reads, with the same rule (dotted entries ignored, a folder has pages when it or a subfolder holds a .md),
 * and refuses before Collectives is asked, so a read or a plan never writes.
 */
class MissingIndexCheck {
	/**
	 * @param Folder $folder the actor's folder of the collective
	 * @throws ToolFailure when a folder with pages has no Readme.md
	 */
	public function assertComplete(Folder $folder): void {
		$this->hasPages($folder);
	}

	/**
	 * @param Folder $folder a folder of the collective
	 * @return bool whether it or a subfolder holds a page
	 * @throws ToolFailure when it holds pages without a Readme.md
	 */
	private function hasPages(Folder $folder): bool {
		$pages = false;
		$index = false;
		foreach ($folder->getDirectoryListing() as $node) {
			$name = $node->getName();
			if (str_starts_with($name, '.')) {
				continue;
			}
			if ($node instanceof Folder) {
				$pages = $this->hasPages($node) || $pages;
			} elseif ($node instanceof File && str_ends_with($name, '.md')) {
				$pages = true;
				$index = $index || $name === 'Readme.md';
			}
		}
		if ($pages && !$index) {
			throw new ToolFailure(CollectivesMessages::indexMissing());
		}
		return $pages;
	}
}
