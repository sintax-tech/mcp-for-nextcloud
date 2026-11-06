<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Sharing;

use OCA\Mcp\Modules\Tables\Presentation\{SchemaSnapshot, SchemaMessages};
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Platform\FileAccess\CommonMessages;

/** Pure recipient/node binding, explicit bits and no implicit privilege updates. */
class ShareChanges {
	/**
	 * @param bool $revoke Explicit revocation intent.
	 * @param array<string,mixed> $p Prepared target.
	 * @param list<array<string,mixed>> $shares Authorized native share snapshots.
	 * @return array<string,mixed> Share state.
	 */
	public function of(bool $revoke, array $p, array $shares): array {
		$a = $p['arguments']; $matches = [];
		foreach ($shares as $share) {
			if (($share['nodeType'] ?? '') !== $a['node_type'] || (int)($share['nodeId'] ?? 0) !== $a['node_id']) throw new ToolFailure(CommonMessages::notFound());
			if ($revoke ? (int)$share['id'] === $a['share_id'] : ($share['receiver'] ?? '') === $a['recipient']['id'] && ($share['receiverType'] ?? '') === $a['recipient']['type']) $matches[] = $share;
		}
		if (count($matches) > 1) throw new ToolFailure(SchemaMessages::differentShare());
		if ($revoke) {
			if ($matches === [] || !in_array($matches[0]['receiverType'], ['user', 'group'], true)) throw new ToolFailure(CommonMessages::notFound());
			$p['before'] = SchemaSnapshot::share($matches[0]); $p['after'] = []; $p['changes'] = [];
		} else {
			$p['before'] = $matches === [] ? [] : SchemaSnapshot::share($matches[0]);
			$p['changes'] = ['nodeId' => $a['node_id'], 'nodeType' => $a['node_type'], 'recipient' => $a['recipient'], 'permissions' => $a['permissions']];
			$p['after'] = $p['changes'];
			if ($matches !== []) {
				if ($p['before']['permissions'] !== $a['permissions']) throw new ToolFailure(SchemaMessages::differentShare());
				$p['noop'] = true;
			}
		}
		return $p;
	}
}
