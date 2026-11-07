<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Describe the recorded directories removed or recreated by undo. */
namespace OCA\Mcp\Modules\Files\Reorganization;

use OCA\Mcp\Platform\FileAccess\PathGuard;
use OCP\Files\Folder;

/** Preserves the existing BatchDirectories operations and refusal order. */
final class BatchDirectories {
	/**
	 * Which of the folders a batch created the undo would remove, and which it would keep.
	 *
	 * Only an empty folder the batch itself created goes; a folder with content, one the user cannot delete
	 * and one that is not there any more are all kept. Reading it is what lets the plan answer the question
	 * before anything is moved back, so it reads the tree as the undo will leave it: the items the batch moved
	 * in are gone by the time the folders are looked at.
	 *
	 * @param Folder $root the user's folder
	 * @param Batch $batch batch being undone
	 * @return array{0: list<string>, 1: list<string>} the folders to remove and the folders to keep
	 */
	public function emptyDirs(Folder $root, Batch $batch): array {
		$removed = [];
		$kept = [];
		$simulatedRemoved = array_map(static fn (array $move): string => PathGuard::normalize($move['to'], 'moves'), $batch->moves);
		foreach ($this->byDepth($batch->dirs) as $dir) {
			$relative = ltrim($dir, '/');
			if (!$root->nodeExists($relative)) {
				continue;
			}
			$folder = $root->get($relative);
			if (!$folder instanceof Folder || !$folder->isDeletable()) {
				$kept[] = $dir;
				continue;
			}
			$listing = $folder->getDirectoryListing();
			$remaining = array_filter(
				$listing,
				function ($child) use ($root, $simulatedRemoved): bool {
					$childPath = '/' . ltrim($root->getRelativePath($child->getPath()) ?? '', '/');
					return !in_array($childPath, $simulatedRemoved, true);
				}
			);
			if ($remaining !== []) {
				$kept[] = $dir;
				continue;
			}
			$removed[] = $dir;
			$simulatedRemoved[] = $dir;
		}
		return [$removed, $kept];
	}

	/**
	 * Orders paths deepest first so undo removes empty children before their parents.
	 *
	 * @param list<string> $paths user-relative folder paths
	 * @return list<string> paths sorted by descending depth
	 */
	public function byDepth(array $paths): array {
		$byDepth = $paths;
		usort($byDepth, fn (string $a, string $b) => substr_count($b, '/') <=> substr_count($a, '/'));
		return $byDepth;
	}

	/**
	 * The folders this batch created that a move needs again to go back, and that are not there anymore.
	 *
	 * @param Folder $root the user's folder
	 * @param Batch $batch the batch being undone
	 * @return list<string> the missing folders, shallowest first
	 */
	public function missingRecordedParents(Folder $root, Batch $batch): array {
		$missing = [];
		foreach ($batch->moves as $move) {
			$segments = PathGuard::segments(PathGuard::normalize($move['from'], 'moves'));
			array_pop($segments);
			while ($segments !== []) {
				$candidate = '/' . implode('/', $segments);
				if ($root->nodeExists(ltrim($candidate, '/'))) {
					break;
				}
				if (in_array($candidate, $batch->dirs, true)) {
					$missing[] = $candidate;
				}
				array_pop($segments);
			}
		}
		return $missing;
	}
}
