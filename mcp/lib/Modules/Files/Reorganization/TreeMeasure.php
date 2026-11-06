<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Measure copy sources while preserving visibility and size refusals. */
namespace OCA\Mcp\Modules\Files\Reorganization;

use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Folder;
use OCP\Files\Node;

/** Preserves the existing TreeMeasure operations and refusal order. */
final class TreeMeasure {
	/**
	 * Bind the existing collaborators without storing operation state.
	 * @param TreeEntries $treeEntries treeEntries collaborator.
	 * @param ?\OCA\Mcp\Access\ResourceScope\FilesAccessGuard $visibilityGuard visibilityGuard collaborator.
	 * @return void
	 */
	public function __construct(
		private readonly TreeEntries $treeEntries,
		private readonly ?\OCA\Mcp\Access\ResourceScope\FilesAccessGuard $visibilityGuard = null,
	) {
	}

	/**
	 * Counts the nodes and bytes a copy would bring, stopping as soon as a ceiling is passed so a huge
	 * tree is not measured to the end just to be refused.
	 *
	 * A hidden node inside the tree stops the copy too: every copied node gets a new id, the hidden tag belongs
	 * to the id of the original, and the copy of a hidden file would be readable by anyone who can read the
	 * folder it lands in. The answer is the generic denial, so the count of what is hidden is never told.
	 *
	 * @param Node $node the node a copy would start from, a file or a whole folder
	 * @return array{nodes:int, bytes:int}
	 * @throws ToolFailure when the source is over either ceiling or holds a hidden node
	 */
	public function measure(Node $node): array {
		$nodes = $bytes = 0;
		$pending = [$node];
		while ($pending !== []) {
			$current = array_pop($pending);
			if ($current !== $node && $this->visibilityGuard !== null && !$this->visibilityGuard->isVisible($current)) {
				throw new ToolFailure(CommonMessages::forbidden());
			}
			$nodes++;
			if ($nodes > ReorganizationLimits::NODES) {
				throw new ToolFailure(\OCA\Mcp\Modules\Files\Presentation\ReorganizationMessages::copyTooManyNodes(ReorganizationLimits::NODES));
			}
			if ($current instanceof Folder) {
				foreach ($this->treeEntries->sorted($current) as $child) {
					$pending[] = $child;
				}
				continue;
			}
			$bytes += (int)$current->getSize();
			if ($bytes > ReorganizationLimits::BYTES) {
				throw new ToolFailure(\OCA\Mcp\Modules\Files\Presentation\ReorganizationMessages::copyTooLarge(ReorganizationLimits::BYTES));
			}
		}
		return ['nodes' => $nodes, 'bytes' => $bytes];
	}
}
