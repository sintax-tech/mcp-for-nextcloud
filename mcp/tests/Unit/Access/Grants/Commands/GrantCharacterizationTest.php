<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Access\Grants\Commands;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GrantCharacterizationTest extends TestCase {
	public static function scenarios(): iterable {
		foreach (GrantScenario::CASES as $case) { yield $case => [$case]; }
	}

	#[DataProvider('scenarios')]
	public function testCommandsMatchThePreExtractionBaseline(string $case): void {
		$baseline = json_decode(file_get_contents(__DIR__ . '/grant-baseline.json'), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame($baseline[$case], (new GrantScenario('record'))->record($case));
	}
}
