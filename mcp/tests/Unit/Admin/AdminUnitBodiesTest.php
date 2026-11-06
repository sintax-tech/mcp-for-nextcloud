<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/** Partial evidence only: ten unchanged function bodies from fe238b21 survive the modular rewrite. */
final class AdminUnitBodiesTest extends TestCase {
	/**
	 * Exclude exactly the extra factory indentation tab; no token, whitespace or comment inside a body is normalized.
	 *
	 * @return void
	 */
	public function testUnchangedMatrixBodiesRetainTheOriginalBytes(): void {
		$cases = [
			['oneStep', 'revisions', '8b158edf7bcdebf5cf60147e08ce44560be994faea24e3d9a33ef555443a0d13'],
			['phpTrim', 'revisions', '93f9eb7327c6e35a266624011d6db9b847f83f1b7ce224320523ddd375d0db2d'],
			['notifyError', 'requests', '6f70a91936f43425e1a6d92e25d0324411ac12dd037202a1e0b3ad04f253e8e6'],
			['el', 'elements', '5e9b0b61f00162f87e026bcf1409044ba10937b53fa491137977cc926177bdc1'],
			['flash', 'elements', '656e63be24ac57fae52ff9d6b643efc087c9c37537791fe921761cff0ee5e926'],
			['unavailableReason', 'operation-cells', 'c2f66250c1017a12db3f5a7450f27fd282a71e6e90709819e755f311f17783ff'],
			['rowUnavailableReason', 'rows', 'e622b83adb4df1b847dd13a11bf111c54dfb695f30bb39e4d92f5254f9f86e8d'],
			['originText', 'level-cells', 'a98a52bce6a98896f551a8018dab689fcff7800429f74a93dd3cf3ab4588d668'],
			['markIcon', 'level-cells', 'c00c6fbad617ad82e4354b33cdb8d6b11b039eba28a749ce8d24000311f8126a'],
			['moduleLabels', 'labels', '9972c0d69b03540d4d42fec78f3fb695c2b8ffec6ba47efca5cacc352fce412a'],
		];
		foreach ($cases as [$name, $unit, $expected]) {
			$source = (string)file_get_contents(dirname(__DIR__, 3) . '/src/admin/matrix/' . $unit . '.js');
			$pattern = '/^\t\tfunction ' . preg_quote($name, '/') . '\([^\n]*\n.*?^\t\t}/ms';
			$this->assertSame(1, preg_match($pattern, $source, $match), $name);
			$body = preg_replace('/^\t/m', '', $match[0]);
			$this->assertSame($expected, hash('sha256', $body), $name);
		}
	}
}
