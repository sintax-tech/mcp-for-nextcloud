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
		$first = GroupScopeReceiptScenario::normalize(['life' => ['gen' => $left]]);
		$second = GroupScopeReceiptScenario::normalize(['life' => ['gen' => $right]]);
		self::assertSame($left, $first['life']['gen']);
		self::assertSame($right, $second['life']['gen']);
		self::assertNotSame($first, $second);
	}

	public function testMessagesContainingGeneratedIdsStayLiteral(): void {
		$id = '0123456789abcdef0123456789abcdef';
		$record = ['result' => ['commandId' => $id], 'error' => ['message' => 'literal ' . $id],
			'life' => [['gen' => $id]], 'other' => ['commandId' => $id]];
		$actual = GroupScopeReceiptScenario::normalize($record, [$id], []);
		self::assertSame('literal ' . $id, $actual['error']['message']);
		self::assertSame($id, $actual['life'][0]['gen']);
		self::assertSame($id, $actual['other']['commandId']);
		self::assertSame('id-1', $actual['result']['commandId']);
	}

	public function testGeneratedIdsKeepTheirDistinctnessAndKnownReferences(): void {
		$a = '0123456789abcdef0123456789abcdef';
		$b = 'fedcba9876543210fedcba9876543210';
		$record = ['result' => ['commandId' => $a, 'receiptId' => $a . '-2'],
			'life' => [['gen' => $a], ['gen' => $b]],
			'events' => [['kind' => 'audit', 'payload' => json_encode(['receiptId' => $a . '-2', 'undoOf' => $a . '-1', 'message' => $a], JSON_THROW_ON_ERROR)]]];
		$actual = GroupScopeReceiptScenario::normalize($record, [$a], [$a, $b]);
		self::assertSame('id-1', $actual['result']['commandId']);
		self::assertSame('id-1-2', $actual['result']['receiptId']);
		self::assertSame('id-1', $actual['life'][0]['gen']);
		self::assertSame('id-2', $actual['life'][1]['gen']);
		$payload = json_decode($actual['events'][0]['payload'], true, 512, JSON_THROW_ON_ERROR);
		self::assertSame('id-1-2', $payload['receiptId']);
		self::assertSame('id-1-1', $payload['undoOf']);
		self::assertSame($a, $payload['message']);
	}

	#[DataProvider('scenarios')]
	public function testCommandsMatchThePreExtractionBaseline(string $case): void {
		$baseline = json_decode((string)file_get_contents(__DIR__ . '/group-scope-receipt-baseline.json'), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame($baseline[$case], (new GroupScopeReceiptScenario('record'))->record($case));
	}
}
