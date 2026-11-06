<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Integration;

use OCA\Mcp\Modules\Tables\Presentation\{SchemaSnapshot, SchemaMessages};
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/** Fresh authorized readback only; no native mutations or retries. */
class SchemaObservation {
	/** @param SchemaTargetReader $reader Uncached native metadata boundary. */
	public function __construct(private SchemaTargetReader $reader) {}
	/**
	 * @param string $action Attempted operation.
	 * @param array<string,mixed> $p Preparation.
	 * @param int|null $returned Native ID.
	 * @param string $uid Caller.
	 * @return array<string,mixed> Fresh authorized observation.
	 */
	public function observe(string $action, array $p, ?int $returned, string $uid): array {
		$a = $p['arguments'];
		if (in_array($action, ['share_node', 'unshare_node'], true)) {
			$items = $this->reader->shares($a['node_type'], $a['node_id'], $uid);
			$found = [];
			foreach ($items as $share) {
				if ($action === 'unshare_node' ? (int)$share['id'] === $a['share_id']
					: ($share['receiver'] ?? '') === $a['recipient']['id'] && ($share['receiverType'] ?? '') === $a['recipient']['type']) $found[] = $share;
			}
			if ($action === 'unshare_node' && $found === []) return [];
			if (count($found) !== 1) throw new ToolFailure(SchemaMessages::uncertain());
			return SchemaSnapshot::share($found[0]);
		}
		$id = str_starts_with($action, 'create_') ? $returned : $p['id'];
		if ($id === null) throw new ToolFailure(SchemaMessages::uncertain());
		$node = $this->reader->target($p['kind'], $id, $uid, $p['kind'] !== 'table' ? $a['table_id'] : null);
		$observed = SchemaSnapshot::of((array)$node->jsonSerialize(), $p['kind']);
		if ($action === 'create_column' && ($a['view_ids'] ?? []) !== []) {
			$observed['views'] = []; $observed['viewsVerified'] = true;
			foreach ($a['view_ids'] as $viewId) {
				$view = $this->reader->target('view', $viewId, $uid, $a['table_id']);
				$observed['views'][$viewId] = SchemaSnapshot::of((array)$view->jsonSerialize(), 'view');
				$expected = $p['views'][$viewId]['columnSettings'] ?? [];
				$orders = array_column($expected, 'order');
				$expected[] = ['columnId' => $id, 'order' => $orders === [] ? 0 : max($orders) + 1, 'readonly' => false, 'mandatory' => false];
				$observed['viewsVerified'] = $observed['viewsVerified'] && in_array($id, $view->getColumnIds(), true)
					&& \OCA\Mcp\Modules\Tables\Writing\SchemaReceiptComparison::same($expected, $observed['views'][$viewId]['columnSettings'] ?? null);
			}
		}
		return $observed;
	}
}
