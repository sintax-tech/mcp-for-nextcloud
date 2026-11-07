<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Integration;

use OCA\Mcp\Modules\Tables\Writing\SchemaReceiptComparison;
use OCA\Mcp\Modules\Tables\Presentation\{SchemaSnapshot, SchemaMessages};
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/** Fresh authorized readback only; no native mutations or retries. */
class SchemaObservation {
	/** @param SchemaTargetReader $reader Uncached native metadata boundary. */
	public function __construct(private SchemaTargetReader $reader) {}
	/**
	 * @param array<string,mixed> $p Prepared node.
	 * @param int|null $id Native target ID.
	 * @param string $uid Caller.
	 * @return array<string,mixed> Fresh authorized metadata.
	 */
	public function node(array $p, ?int $id, string $uid): array {
		$a = $p['arguments'];
		if ($id === null) throw new ToolFailure(SchemaMessages::uncertain());
		$node = $this->reader->target($p['kind'], $id, $uid, $p['kind'] !== 'table' ? $a['table_id'] : null);
		return SchemaSnapshot::of(SchemaNativeSnapshot::of($node), $p['kind']);
	}
	/**
	 * @param array<string,mixed> $p New column intent.
	 * @param int|null $id Native new ID.
	 * @param string $uid Caller.
	 * @return array<string,mixed> Column plus view-membership verification.
	 */
	public function columnCreated(array $p, ?int $id, string $uid): array {
		$a = $p['arguments'];
		$observed = $this->node($p, $id, $uid);
		if (($a['view_ids'] ?? []) !== []) {
			$observed['views'] = [];
			$observed['viewsVerified'] = true;
			foreach ($a['view_ids'] as $viewId) {
				$view = $this->reader->target('view', $viewId, $uid, $a['table_id']);
				$observed['views'][$viewId] = SchemaSnapshot::of((array)$view->jsonSerialize(), 'view');
				$expected = $p['views'][$viewId]['columnSettings'] ?? [];
				$orders = array_column($expected, 'order');
				$expected[] = ['columnId' => $id, 'order' => $orders === [] ? 0 : max($orders) + 1, 'readonly' => false, 'mandatory' => false];
				$observed['viewsVerified'] = $observed['viewsVerified'] && in_array($id, $view->getColumnIds(), true)
					&& SchemaReceiptComparison::same($expected, $observed['views'][$viewId]['columnSettings'] ?? null);
			}
		}
		return $observed;
	}
	/**
	 * @param array<string,mixed> $p Share intent.
	 * @param string $uid Caller.
	 * @return array<string,mixed> Fresh share.
	 */
	public function sharing(array $p, string $uid): array { return $this->shares($p, $uid, false); }
	/**
	 * @param array<string,mixed> $p Revocation intent.
	 * @param string $uid Caller.
	 * @return array<string,mixed> Empty only when the share is gone.
	 */
	public function revocation(array $p, string $uid): array { return $this->shares($p, $uid, true); }
	/**
	 * @param array<string,mixed> $p Authorized intent.
	 * @param string $uid Caller.
	 * @param bool $revoke Revocation.
	 * @return array<string,mixed> Whitelisted observed share.
	 */
	private function shares(array $p, string $uid, bool $revoke): array {
		$a = $p['arguments'];

		$items = $this->reader->shares($a['node_type'], $a['node_id'], $uid);
		$found = [];
		foreach ($items as $share) {
			if ($revoke ? (int)$share['id'] === $a['share_id']
				: ($share['receiver'] ?? '') === $a['recipient']['id'] && ($share['receiverType'] ?? '') === $a['recipient']['type']) $found[] = $share;
		}
		if ($revoke && $found === []) return [];
		if (count($found) !== 1) throw new ToolFailure(SchemaMessages::uncertain());
		return SchemaSnapshot::share($found[0]);
	}
}
