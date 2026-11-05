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

	public function testInputGenerationsStayLiteral(): void {
		$left = '0123456789abcdef0123456789abcdef';
		$right = 'fedcba9876543210fedcba9876543210';
		$first = GroupScopeReceiptScenario::normalize(['inputGen' => $left, 'commandId' => str_repeat('ab', 16)]);
		$second = GroupScopeReceiptScenario::normalize(['inputGen' => $right, 'commandId' => str_repeat('cd', 16)]);
		self::assertSame($left, $first['inputGen']);
		self::assertSame($right, $second['inputGen']);
		self::assertNotSame($first['inputGen'], $second['inputGen']);
		self::assertSame('id-1', $first['commandId']);
		self::assertSame('id-1', $second['commandId']);
	}

	#[DataProvider('scenarios')]
	public function testCommandsMatchThePreExtractionBaseline(string $case): void {
		$baseline = json_decode((string)file_get_contents(__DIR__ . '/group-scope-receipt-baseline.json'), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame($baseline[$case], (new GroupScopeReceiptScenario('record'))->record($case));
	}
}
