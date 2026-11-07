<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reorganization;

use OCA\Mcp\Access\FileTypes\FileTypeCheck;
use OCA\Mcp\Platform\FileAccess\NodeAccess;
use OCA\Mcp\Platform\FileAccess\PathGuard;
use OCA\Mcp\Platform\FileAccess\CommonMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;

/** Bounded whole-tree and ordered inverse/direct preflight, with no mutations or per-name config reads. */
final class FileTypePreflight {
	/** @var array<string, list<Node>> Trees already walked in this boundary only. */
	private array $trees = [];
	/** @var array<string, bool> Distinct inspected nodes, bounding aggregate batch work. */
	private array $seen = [];
	/** Projected nodes evaluated in this boundary, including repeated/overlapping moves. */
	private int $work = 0;

	/** @param CopyMoveContext $context Native authorization and the single final-name evaluator. */
	public function __construct(private \OCA\Mcp\Modules\Files\Reorganization\CopyMoveContext $context) {
	}

	/**
	 * @param Node $source Resolved source at the operation boundary.
	 * @param string $to Projected destination.
	 * @param FileTypeCheck $snapshot Shared immutable policy.
	 * @param bool $copy Also enforce the existing copy byte ceiling.
	 * @return array{entries:list<array{source:string,to:string,id:int,folder:bool}>,nodes:int,bytes:int}|null Null means off: no new traversal.
	 * @throws ToolFailure Before effects for any hidden node, refused name or ceiling.
	 */
	public function inspect(Node $source, string $to, FileTypeCheck $snapshot, bool $copy = false): ?array {
		if (!$snapshot->isActive()) {
			return null;
		}
		$key = $source->getPath() . ':' . $source->getId();
		if (!isset($this->trees[$key])) {
			$nodes = [];
			$pending = [$source];
			while ($pending !== []) {
				$node = array_pop($pending);
				if ($this->context->visibilityGuard !== null && !$this->context->visibilityGuard->isVisible($node)) {
					throw new ToolFailure(CommonMessages::notFound());
				}
				if (!$node->isReadable()) {
					throw new ToolFailure(CommonMessages::notFound());
				}
				$this->seen[$node->getPath() . ':' . $node->getId()] = true;
				if (count($this->seen) > \OCA\Mcp\Modules\Files\Reorganization\ReorganizationLimits::NODES) {
					throw new ToolFailure(\OCA\Mcp\Modules\Files\Presentation\FileTreeMessages::tooManyNodes(\OCA\Mcp\Modules\Files\Reorganization\ReorganizationLimits::NODES));
				}
				$nodes[] = $node;
				if ($node instanceof Folder) {
					foreach ($node->getDirectoryListing() as $child) {
						$pending[] = $child;
						if (count($pending) + count($nodes) > \OCA\Mcp\Modules\Files\Reorganization\ReorganizationLimits::NODES) {
							// Pending children are not visibility-checked: memory bounds reveal no count.
							throw new ToolFailure(CommonMessages::notFound());
						}
					}
				}
			}
			$this->trees[$key] = $nodes;
		}
		$entries = [];
		$bytes = 0;
		foreach ($this->trees[$key] as $node) {
			if (++$this->work > \OCA\Mcp\Modules\Files\Reorganization\ReorganizationLimits::NODES) {
				throw new ToolFailure(\OCA\Mcp\Modules\Files\Presentation\FileTreeMessages::tooManyNodes(\OCA\Mcp\Modules\Files\Reorganization\ReorganizationLimits::NODES));
			}
			$final = $to . substr($node->getPath(), strlen($source->getPath()));
			if ($node instanceof File) {
				$this->context->policy()->assertProjected($node, $final, $snapshot);
				$bytes += max(0, (int)$node->getSize());
				if ($copy && $bytes > \OCA\Mcp\Modules\Files\Reorganization\ReorganizationLimits::BYTES) {
					throw new ToolFailure(\OCA\Mcp\Modules\Files\Presentation\ReorganizationMessages::copyTooLarge(\OCA\Mcp\Modules\Files\Reorganization\ReorganizationLimits::BYTES));
				}
			}
			$entries[] = ['source' => $node->getPath(), 'to' => $final, 'id' => (int)$node->getId(), 'folder' => $node instanceof Folder];
		}
		return ['entries' => $entries, 'nodes' => count($entries), 'bytes' => $bytes];
	}

	/**
	 * Evaluate every projected step, including forbidden intermediate names, before any mkdir or move.
	 * Native planner permission/conflict rules are retained; aliases only resolve the source for type evaluation.
	 * The native direct planner still refuses a later source that does not yet exist; aliases do not enable
	 * chained batches. They cover projected type names independently, including recorded inverse sequences.
	 * @param Folder $root User root.
	 * @param list<array<string,mixed>> $moves Ordered direct moves, or original recorded moves for undo.
	 * @param FileTypeCheck $snapshot One boundary for the complete batch.
	 * @param bool $undo Reverse the recorded sequence and destinations.
	 * @return list<array{from:string,to:string,reason:string}> Type conflicts; no hidden path or count is revealed.
	 */
	public function moves(Folder $root, array $moves, FileTypeCheck $snapshot, bool $undo = false): array {
		if (!$snapshot->isActive()) {
			return [];
		}
		$aliases = [];
		$conflicts = [];
		foreach ($undo ? array_reverse($moves) : $moves as $item) {
			$from = PathGuard::normalize($undo ? $item['to'] : $item['from']);
			$to = PathGuard::normalize($undo ? $item['from'] : $item['to']);
			$physical = $from;
			foreach (array_reverse($aliases) as $alias) {
				if ($from === $alias['to'] || str_starts_with($from, $alias['to'] . '/')) {
					$physical = $alias['physical'] . substr($from, strlen($alias['to']));
					break;
				}
			}
			try {
				$node = NodeAccess::get($root, $physical, $this->context->visibilityGuard);
			} catch (ToolFailure) {
				// Existing planner/undo checks retain the source missing/hidden refusal.
				continue;
			}
			try {
				// A hidden descendant refuses the entire operation generically, never as an item with a path.
				$this->inspect($node, $to, $snapshot);
			} catch (\OCA\Mcp\Access\FileTypes\FileTypeDenied|\OCA\Mcp\Access\FileTypes\FileWritePolicyUnavailable $e) {
				$conflicts[] = ['from' => $from, 'to' => $to, 'reason' => $e->getMessage()];
			}
			$aliases[] = ['to' => $to, 'physical' => $physical];
		}
		return $conflicts;
	}
}
