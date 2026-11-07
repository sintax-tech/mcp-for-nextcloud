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
 * The page files of a collective, found by walking its folder through Files, which only reads. The module never asks
 * Collectives to build the tree: every proven release writes the Readme.md of a folder that holds pages without one
 * while it does (4.0.0 and 4.4.0 in getPagesFromFolder, 4.5.0 and later in PageInfoTreeBuilder::createIndexPage), and
 * a check before that call cannot stop a Readme.md removed in between. The pages are then read one by one with
 * PageService::find, which only reads in every proven release. Dotted entries are ignored, as Collectives ignores them;
 * a folder that holds pages without its Readme.md is refused, so the answer never depends on what such a tree misses.
 */
class PageFileWalk {
	/**
	 * @param Folder $folder the actor's folder of the collective
	 * @return list<int> ids of the page files, landing page first, then depth first
	 * @throws ToolFailure when a folder with pages has no Readme.md
	 */
	public function pageIds(Folder $folder): array {
		return $this->walk($folder)['ids'];
	}

	/**
	 * @param Folder $folder a folder of the collective
	 * @return array{pages:bool, ids:list<int>} whether it or a subfolder holds a page, and the ids of its page files
	 * @throws ToolFailure when it holds pages without a Readme.md
	 */
	private function walk(Folder $folder): array {
		$index = null;
		$files = [];
		$below = [];
		$pages = false;
		foreach ($folder->getDirectoryListing() as $node) {
			$name = $node->getName();
			if (str_starts_with($name, '.')) {
				continue;
			}
			if ($node instanceof Folder) {
				$sub = $this->walk($node);
				$pages = $pages || $sub['pages'];
				array_push($below, ...$sub['ids']);
			} elseif ($node instanceof File && str_ends_with($name, '.md')) {
				$pages = true;
				if ($name === 'Readme.md') {
					$index = (int)$node->getId();
				} else {
					$files[] = (int)$node->getId();
				}
			}
		}
		if ($pages && $index === null) {
			throw new ToolFailure(CollectivesMessages::indexMissing());
		}
		return ['pages' => $pages, 'ids' => $index === null ? [] : [$index, ...$files, ...$below]];
	}
}
