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
		], [
			'ticket' => 'RE-22',
			'pairs_sha256' => '691b1f19e56a3079f437c11ddaf6f5aded64d13528d7d99867c6bc8768a8aa27',
			'path' => 'scripts/dev/frozen-path-sites-re22.json',
			'seal' => 'd91d0eef23388bad9ba45c1b69bd57e9ce8e7bdf87846b2407a55cb1433f6eb0',
			'sites' => 1,
			'base_commit' => '64d736c25f1ab9fd279191f2435cc0be8a65ee78',
			'base_tree' => '2f74fe4511a837daa9617aa3c8768b6c47838123',
		], [
			'ticket' => 'RE-24',
			'pairs_sha256' => 'ac55590d5f6c282f3ef740b36e217beff419bcef95430b94235565f01a35318b',
			'path' => 'scripts/dev/frozen-path-sites-re24.json',
			'seal' => '3f2d4662c6744a84a77a6e2ad0b1c698f35569e07758dcf467cb65fdb6f6e24e',
			'sites' => 1,
			'base_commit' => 'bcfa17297d81907741612b0507e3f17cc36ae3cf',
			'base_tree' => '0e79794dc5d3feb8bd419117dc390eb82cca1e44',
		]]; }
}
