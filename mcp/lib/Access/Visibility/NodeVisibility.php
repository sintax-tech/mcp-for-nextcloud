<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Visibility;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Files\Node;

/** Exclusion decisions and metadata traversal consumed through a single virtual node facade. */
interface NodeVisibility {
	/**
	 * Evaluate tags, mounted and owner ancestors and hidden backup originals, refusing unreadable state.
	 * @param Node $node File or folder requested by the caller.
	 * @return bool False for a hidden node or when privacy cannot be established.
	 */
	public function isVisible(Node $node): bool;

	/**
	 * Explain the actual virtual visibility decision for the administrator, after clearing request caches.
	 * @param Node $node File or folder in the inspected user tree.
	 * @return array{reason: string, folder: ?Node} Public EXPLAINED_* code; folder names only the tagged ancestor.
	 * @throws \Throwable When an overriding decision or invalidation throws.
	 */
	public function explain(Node $node): array;

	/**
	 * Resolve a parent by its native path at mount boundaries so inherited tags use the correct file ID.
	 * @param Node $node Child whose parent is needed.
	 * @return Node Native parent or the filesystem root above the user tree.
	 * @throws \Throwable When a boundary parent is missing, has another path or has no positive ID.
	 */
	public function parentOf(Node $node): Node;

	/**
	 * Refuse a node that the actual virtual visibility decision hides.
	 * @param Node $node File or folder about to be named or reached.
	 * @return void
	 * @throws ToolFailure With CommonMessages::notFound() when the node is hidden.
	 */
	public function assertVisible(Node $node): void;

	/**
	 * Check metadata traversal independently of reachability; Files may cross ancestors outside its selected scope.
	 * @param Node $node Existing path segment crossed without showing its contents.
	 * @return bool Whether the actual traversal policy allows the node.
	 */
	public function isTraversable(Node $node): bool;

	/**
	 * Refuse a path segment through the actual virtual traversal decision.
	 * @param Node $node Existing ancestor about to be crossed.
	 * @return void
	 * @throws ToolFailure With CommonMessages::notFound() when traversal is hidden.
	 */
	public function assertTraversable(Node $node): void;

	/**
	 * Keep visible native nodes in their input order through the actual virtual visibility decision.
	 * @param iterable<mixed> $nodes Candidate nodes; non-node entries are ignored.
	 * @return list<Node> Nodes that remain reachable.
	 */
	public function filter(iterable $nodes): array;

	/**
	 * Forget configuration, node, share, owner and backup metadata caches without resetting diagnostics or log suppression.
	 * @return void
	 */
	public function clearCache(): void;
}
