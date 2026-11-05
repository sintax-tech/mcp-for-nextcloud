<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Access\Policy;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GroupScopeReceiptCharacterizationTest extends TestCase {
	public static function scenarios(): iterable {
		foreach (GroupScopeReceiptScenario::CASES as $case) {
			yield $case => [$case];
		}
	}

	#[DataProvider('scenarios')]
	public function testCommandsMatchThePreExtractionBaseline(string $case): void {
		$baseline = json_decode((string)file_get_contents(__DIR__ . '/group-scope-receipt-baseline.json'), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame($baseline[$case], (new GroupScopeReceiptScenario('record'))->record($case));
	}
}
