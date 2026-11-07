<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Dependencies;

/** Canonical readable state; hidden links and their activity timestamps cannot affect a fingerprint. */
final class DependencyChange {
	/**
	 * @param array<string, mixed> $state Fresh authorized state from the gateway.
	 * @param bool $includeGraph Include the cycle-check snapshot for a plan or pre-write validation.
	 * @return array<string, mixed> Scalar state suitable for PlanState, never a native entity.
	 */
	public static function snapshot(array $state, bool $includeGraph = true): array {
		$node = static fn (array $item): array => ['id' => (int)$item['card']->getId(),
			'boardId' => $item['boardId'], 'stackId' => (int)$item['card']->getStackId(),
			'title' => (string)$item['card']->getTitle()];
		$result = ['origin' => $node($state['origin']), 'target' => $node($state['target']),
			'board' => $state['board'], 'present' => $state['present'],
			'relations' => array_map($node, $state['origin']['targets'])];
		if ($includeGraph) {
			$result['graph'] = $state['graph'] === null ? null : [
				'nodes' => array_map($node, $state['graph']['nodes']), 'edges' => $state['graph']['edges']];
		}
		return $result;
	}
}
