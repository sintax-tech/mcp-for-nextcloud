<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Architecture\Dependencies;

use OCA\Mcp\Tests\Architecture\Inventory\TicketState;
use RuntimeException;

/** Exact, consumed, expiring exceptions; new violations never enter this ledger automatically. */
final class BoundaryLedger {
	/** @param list<array<string,mixed>> $violations Observed edges. @param list<array<string,mixed>> $exceptions Frozen exceptions. @param TicketState $tickets Portable state. @return list<string> Unapproved edges and stale exceptions. */
	public static function problems(array $violations, array $exceptions, TicketState $tickets): array {
		$observed = array_combine(array_map(BoundaryRules::key(...), $violations), $violations) ?: [];
		$seen = [];
		$problems = [];
		foreach ($exceptions as $entry) {
			foreach (['rule', 'source', 'target', 'reason', 'expires'] as $field) {
				if (!is_string($entry[$field] ?? null) || trim($entry[$field]) === '') { throw new RuntimeException('Malformed exception: ' . $field); }
			}
			if (!preg_match('#^lib/[A-Za-z0-9_/]+\.php$#D', $entry['source']) || !preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\\\\)+[A-Za-z_][A-Za-z0-9_]*$/D', $entry['target'])) { throw new RuntimeException('Exception must use an exact canonical path and class'); }
			$tickets->pending($entry['expires']);
			$key = BoundaryRules::key($entry);
			if (isset($seen[$key])) { throw new RuntimeException('Duplicate exception: ' . $key); }
			$seen[$key] = true;
			if (!isset($observed[$key])) { $problems[] = 'Unused exception: ' . $key; }
		}
		foreach ($observed as $key => $edge) {
			if (!isset($seen[$key])) { $problems[] = $key . ':' . $edge['line']; }
		}
		return $problems;
	}
}
