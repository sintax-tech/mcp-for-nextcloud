<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Check complete batch visibility before exposing paths or applying effects. */
namespace OCA\Mcp\Modules\Files\Reorganization;

use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Platform\FileAccess\PathGuard;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;
use OCP\Files\Node;

/** Preserves the existing BatchVisibility operations and refusal order. */
final class BatchVisibility {
	/**
	 * Bind the existing collaborators without storing operation state.
	 * @param ?\OCA\Mcp\Access\ResourceScope\FilesAccessGuard $visibilityGuard visibilityGuard collaborator.
	 * @return void
	 */
	public function __construct(
		private readonly ?\OCA\Mcp\Access\ResourceScope\FilesAccessGuard $visibilityGuard = null,
	) {
	}

	/** Classify the whole batch before any lock checks, folder creation or mutation.
	 * @param Folder $root root input.
	 * @param array $moves moves input.
	 * @param bool $undo undo input.
	 * @return void
	 */
	public function assertFolderMoves(Folder $root, array $moves, bool $undo = false): void {
		if ($this->visibilityGuard !== null && !$this->visibilityGuard->isConfigurationValid()) {
			throw new ToolFailure(CommonMessages::notFound());
		}
		if (!FolderExposureGate::active($this->visibilityGuard)) {
			return;
		}
		foreach ($moves as $item) {
			$from = $undo ? $item['to'] : $item['from'];
			$to = $undo ? $item['from'] : $item['to'];
			try {
				$source = NodeAccess::run(fn (): Node => NodeAccess::get($root, $from, $this->visibilityGuard));
			} catch (ToolFailure) {
				// Existing planning/undo checks retain their normal missing/hidden/ACL refusals.
				continue;
			}
			FolderExposureGate::assertMove($root, $source, $to, $this->visibilityGuard);
		}
	}

	/**
	 * Refuses the whole undo when anything it would touch is hidden by a tag, directly or through a folder above it.
	 *
	 * What it touches: each item where the batch left it, the place it goes back to (the item already there, or the
	 * nearest folder that exists above it) and each folder the batch created. The tags may have been set after the
	 * batch ran, so the batch record proves nothing. The refusal names no path and no item.
	 *
	 * @param Folder $root the user's folder
	 * @param Batch $batch the batch to undo
	 * @throws ToolFailure when one of those nodes is hidden or cannot be checked
	 * @return void
	 */
	public function assertUndoVisible(Folder $root, Batch $batch): void {
		$paths = $batch->dirs;
		foreach ($batch->moves as $move) {
			$paths[] = $move['to'];
			$paths[] = $move['from'];
		}
		$this->assertPathsVisible($root, $paths);
	}

	/** @param list<string> $paths paths or nearest existing ancestors to check
	 * @param Folder $root root input.
	 * @return void
	 */
	public function assertPathsVisible(Folder $root, array $paths): void {
		if ($this->visibilityGuard === null) {
			return;
		}
		foreach ($paths as $path) {
			try {
				$segments = PathGuard::segments(PathGuard::normalize($path, 'moves'));
				// The node itself when it exists, otherwise the nearest folder above it that does, the user's
				// folder itself at the top.
				while ($segments !== [] && !$root->nodeExists(implode('/', $segments))) {
					array_pop($segments);
				}
				$visible = $this->visibilityGuard->isVisible($segments === [] ? $root : $root->get(implode('/', $segments)));
			} catch (\Throwable) {
				$visible = false;
			}
			if (!$visible) {
				throw new ToolFailure(\OCA\Mcp\Modules\Files\Presentation\ReorganizationMessages::batchHasHiddenItem());
			}
		}
	}
}
