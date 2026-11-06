<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts\Dev;

/** Reviewed certificate descriptors are trusted inputs, separate from the sealed JSON they authorize. */
final class FrozenPathCertificates {
	/** @return list<array<string,mixed>> Ticket, exact pairs fingerprint, source anchors and externally reviewed seal. */
	public static function all(): array { return [[
			'ticket' => 'RE-21',
			'pairs_sha256' => '2d8021ee90e318912c0ee76b2ff3875cd2de725acb696449c73994e6d714fd59',
			'path' => 'scripts/dev/frozen-path-sites.json',
			'seal' => '60c4b27951182125f5690830151eb9d7564055010124020ff8e445768a7e2064',
			'sites' => 4,
			'base_commit' => '2f1cc339d9057811893478f50b9878fab171967a',
			'base_tree' => 'd80a681734af242e43df606a04ad39aa5651c505',
		]]; }
}
