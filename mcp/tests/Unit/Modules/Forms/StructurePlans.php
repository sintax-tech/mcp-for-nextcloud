<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;

require_once __DIR__ . '/StructureHarness.php';

use PHPUnit\Framework\TestCase;

/** Plans of the seven structure tools, built by the real flow, for the Forms readability contract. */
final class StructurePlans {
	private function __construct() {
	}

	/**
	 * @param TestCase $test owner of the mocks
	 * @return array<string,array<string,mixed>> plan of each structure tool, keyed by tool name
	 */
	public static function all(TestCase $test): array {
		$plans = [];
		foreach (StructureHarness::operations() as $name => $op) {
			$plans[$name] = StructureHarness::flow($test, new StructureFixture())->preview($op, StructureHarness::arguments()[$name], 'alice');
		}
		return $plans;
	}
}
