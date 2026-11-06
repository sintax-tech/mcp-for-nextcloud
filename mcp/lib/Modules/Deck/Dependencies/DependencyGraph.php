<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Dependencies;

/** Bounded breadth-first traversal of the authorized outgoing native graph, never a global graph assertion. */
final class DependencyGraph {
	public const MAX_NODES = 100;
	public const MAX_EDGES = 500;
	public const MAX_DEPTH = 20;

	/**
	 * Receive only the node-read boundary required by the bounded traversal.
	 *
	 * @param DependencyNodeSource $gateway Fresh READ boundary for every expanded node and edge target.
	 * @param int $maxNodes Maximum distinct readable nodes, including the root.
	 * @param int $maxEdges Maximum distinct readable directed edges.
	 * @param int $maxDepth Maximum shortest-path distance from the root, with root depth zero.
	 */
	public function __construct(private \OCA\Mcp\Modules\Deck\Integration\DependencyNodeSource $gateway, private int $maxNodes = self::MAX_NODES,
		private int $maxEdges = self::MAX_EDGES, private int $maxDepth = self::MAX_DEPTH) {
	}

	/**
	 * @param string $userId Authenticated caller.
	 * @param int $rootId Readable source card.
	 * @param array|null $view Parent listings scoped to this traversal or enclosing state operation.
	 * @return array{rootId: int, nodes: list<array>, edges: list<array>, complete: bool, limits: array}
	 * @throws \Throwable When a fresh node read fails; no partial graph is returned.
	 * @throws DeckRefusalException When the readable subgraph exceeds any bound.
	 */
	public function read(string $userId, int $rootId, ?array &$view = null): array {
		$view ??= [];
		$queue = [[$rootId, 0]];
		$visited = [$rootId => true];
		$nodes = [];
		$edges = [];
		for ($cursor = 0; $cursor < count($queue); $cursor++) {
			[$id, $depth] = $queue[$cursor];
			$node = $this->gateway->dependencyNode($userId, $id, $view);
			$nodes[$id] = ['card' => $node['card'], 'boardId' => $node['boardId']];
			foreach ($node['targets'] as $target) {
				$targetId = (int)$target['card']->getId();
				$key = $id . ':' . $targetId;
				$edges[$key] = ['originId' => $id, 'targetId' => $targetId];
				if (count($edges) > $this->maxEdges) {
					throw new \OCA\Mcp\Modules\Deck\Failures\DeckRefusalException(\OCA\Mcp\Modules\Deck\Presentation\DeckMessages::dependencyGraphLimit());
				}
				if (isset($visited[$targetId])) {
					continue;
				}
				if (count($visited) >= $this->maxNodes || $depth >= $this->maxDepth) {
					throw new \OCA\Mcp\Modules\Deck\Failures\DeckRefusalException(\OCA\Mcp\Modules\Deck\Presentation\DeckMessages::dependencyGraphLimit());
				}
				$visited[$targetId] = true;
				$queue[] = [$targetId, $depth + 1];
			}
		}
		ksort($nodes, SORT_NUMERIC);
		$edges = array_values($edges);
		usort($edges, static fn (array $a, array $b): int =>
			[$a['originId'], $a['targetId']] <=> [$b['originId'], $b['targetId']]);
		return ['rootId' => $rootId, 'nodes' => array_values($nodes), 'edges' => $edges, 'complete' => true,
			'limits' => ['nodes' => $this->maxNodes, 'edges' => $this->maxEdges, 'depth' => $this->maxDepth]];
	}
}
