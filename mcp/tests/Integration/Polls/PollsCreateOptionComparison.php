<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Polls;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;

/** Verifies creation input order independently of native timestamp ties, with exact per-ID values. */
final class PollsCreateOptionComparison {
	/**
	 * Native OptionMapper orders date options by timestamp without an ID tie-breaker. Creation receipts instead
	 * retain input order and identify each individually read-back option. Match native rows by that identity.
	 *
	 * @param list<array<string,mixed>> $planned Canonical values in the accepted input order.
	 * @param list<array<string,mixed>> $receipt MCP options with inputIndex and native ID.
	 * @param list<array<string,mixed>> $native Native list from the fixture's authorized observation.
	 * @return void
	 * @throws AssertionFailedError If input order, identities, values or scalar types differ.
	 */
	public static function assertPlanAndNative(array $planned, array $receipt, array $native): void {
		Assert::assertSame(array_keys($planned), array_column($receipt, 'inputIndex'), 'Receipt retains input order');
		Assert::assertSame($planned, self::values($receipt), 'Receipt retains every planned option value and type');

		$receiptIds = array_column($receipt, 'id');
		$nativeIds = array_column($native, 'id');
		foreach ([$receipt, $native] as $rows) {
			$ids = array_column($rows, 'id');
			Assert::assertCount(count($rows), $ids, 'Every option has an ID');
			Assert::assertCount(count($ids), array_unique($ids, SORT_REGULAR), 'Option IDs are unique');
			foreach ($ids as $id) {
				Assert::assertIsInt($id, 'Native option ID is an integer');
				Assert::assertGreaterThan(0, $id, 'Native option ID is positive');
			}
		}
		sort($receiptIds, SORT_NUMERIC);
		sort($nativeIds, SORT_NUMERIC);
		Assert::assertSame($receiptIds, $nativeIds, 'Receipt and native list contain exactly the same option IDs');

		$nativeById = array_column($native, null, 'id');
		$matched = array_map(static fn (array $row): array => $nativeById[$row['id']], $receipt);
		Assert::assertSame(self::values($receipt), self::values($matched), 'Exact native text, timestamp and duration per ID');
	}

	/**
	 * @param list<array<string,mixed>> $rows Receipt or matched native options, without reordering them.
	 * @return list<array<string,mixed>> The original strict value/type projection.
	 */
	private static function values(array $rows): array {
		return array_map(
			static fn (array $row): array => array_intersect_key($row, array_flip(['text', 'timestamp', 'duration'])),
			$rows,
		);
	}
}
