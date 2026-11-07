<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Polls;

use OCA\Mcp\Tests\Integration\Polls\PollsCreateOptionComparison;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Native timestamp ties never weaken creation receipt order or strict per-ID persistence checks. */
final class PollsCreateOptionComparisonTest extends TestCase {
	/**
	 * @return void
	 */
	public function testNativeTimestampTieAcceptsEitherOrderWithoutChangingReceipt(): void {
		[$planned, $receipt, $native] = self::fixture();
		$originalReceipt = $receipt;
		PollsCreateOptionComparison::assertPlanAndNative($planned, $receipt, $native);
		PollsCreateOptionComparison::assertPlanAndNative($planned, $receipt, array_reverse($native));
		self::assertSame($originalReceipt, $receipt);
	}

	/**
	 * @return iterable<string,array{array,array,array}> Independent order, membership, value and type violations.
	 */
	public static function mismatches(): iterable {
		[$planned, $receipt, $native] = self::fixture();
		yield 'receipt order' => [$planned, array_reverse($receipt), $native];
		$changed = $receipt;
		$changed[1]['inputIndex'] = 0;
		yield 'input index' => [$planned, $changed, $native];
		$changed = $receipt;
		$changed[1]['id'] = $changed[0]['id'];
		yield 'repeated receipt ID' => [$planned, $changed, $native];
		foreach (['text' => 'wrong text', 'timestamp' => 1800000001, 'duration' => 1] as $field => $value) {
			$changed = $native;
			$changed[0][$field] = $value;
			yield 'native ' . $field => [$planned, $receipt, $changed];
		}
		$changed = $native;
		$changed[1]['duration'] = '0';
		yield 'native scalar type' => [$planned, $receipt, $changed];
		$changed = $native;
		$changed[0] = ['id' => $native[0]['id']] + $planned[1];
		$changed[1] = ['id' => $native[1]['id']] + $planned[0];
		yield 'values moved between IDs' => [$planned, $receipt, $changed];
		$changed = $native;
		$changed[1]['id'] = $changed[0]['id'];
		yield 'repeated native ID' => [$planned, $receipt, $changed];
		$changed = $native;
		$changed[1]['id'] = 999;
		yield 'foreign native ID' => [$planned, $receipt, $changed];
		yield 'missing native option' => [$planned, $receipt, [$native[0]]];
		yield 'extra native option' => [$planned, $receipt, [...$native, ['id' => 103] + $planned[0]]];
		$changed = $native;
		unset($changed[0]['id']);
		yield 'missing native ID' => [$planned, $receipt, $changed];
		$changed = $receipt;
		$changed[0]['text'] = 'wrong receipt';
		yield 'receipt differs from plan' => [$planned, $changed, $native];
	}

	/**
	 * @param list<array<string,mixed>> $planned Accepted values in input order.
	 * @param list<array<string,mixed>> $receipt Claimed MCP receipt.
	 * @param list<array<string,mixed>> $native Native rows returned in any tie order.
	 * @return void
	 */
	#[DataProvider('mismatches')]
	public function testRejectsEveryOrderIdentityOrValueMismatch(array $planned, array $receipt, array $native): void {
		$this->expectException(AssertionFailedError::class);
		PollsCreateOptionComparison::assertPlanAndNative($planned, $receipt, $native);
	}

	/**
	 * @return array{array,array,array} The two H3 date values sharing timestamp but differing in duration and text.
	 */
	private static function fixture(): array {
		$planned = [
			['text' => '2027-01-15T08:00:00+00:00 - 2027-01-16T08:00:00+00:00', 'timestamp' => 1800000000, 'duration' => 86400],
			['text' => '2027-01-15T08:00:00+00:00', 'timestamp' => 1800000000, 'duration' => 0],
		];
		$receipt = [
			['inputIndex' => 0, 'id' => 101] + $planned[0],
			['inputIndex' => 1, 'id' => 102] + $planned[1],
		];
		$native = [['id' => 101] + $planned[0], ['id' => 102] + $planned[1]];
		return [$planned, $receipt, $native];
	}
}
