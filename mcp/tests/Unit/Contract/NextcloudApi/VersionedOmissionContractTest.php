<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\AssertionFailedError;
use OCA\Mcp\Tests\Unit\Contract\NextcloudApi\Polls\PollsCreateApiUsage;
/** Negative declarations cannot excuse missing APIs; projection negatives are also exercised by applied mutants. */
final class VersionedOmissionContractTest extends TestCase {
	/** @return iterable<string,array{string}> declaration/surface counterexamples */
	public static function invalid(): iterable {
		foreach (['wrong missing', 'wrong field', 'wrong by', 'wrong pin', 'fake fallback', 'present on 8', 'absent on 9'] as $case) {
			yield $case => [$case];
		}
	}
	/**
	 * A false omission declaration must fail independently of the global scanner.
	 * @param string $case counterexample
	 * @return void
	 */
	#[DataProvider('invalid')]
	public function testBadDeclarationOrNativeSurfaceIsRejected(string $case): void {
		$api = 'OCA\Polls\Db\Poll::getTimezoneName';
		$gate = PollsCreateApiUsage::VERSION_GATED[$api];
		if ($case === 'wrong missing') {
			$gate['missing'] = ['31'];
		}
		if ($case === 'wrong field') {
			$gate['omittedField'] = 'miscSettings';
		}
		if ($case === 'wrong by') {
			$gate['by'] = self::class;
		}
		if ($case === 'wrong pin') {
			$gate['pins']['31'] = '9.3.0';
		}
		if ($case === 'fake fallback') {
			$gate['fallback'] = 'OCA\Polls\Db\Poll::getId';
		}
		$surface = static function (string $major, string $class) use ($case): array {
			$present = !in_array($major, ['31', '32'], true);
			if ($case === 'present on 8' && $major === '32') {
				$present = true;
			}
			if ($case === 'absent on 9' && $major === '35') {
				$present = false;
			}
			return ['present' => $present];
		};
		$this->expectException(AssertionFailedError::class);
		VersionedOmissionContract::handled($api, $gate, ['31', '32', '33', '34', '35'], $surface, static fn(?array $s, string $m): bool => $s['present']);
	}
	/** @return void ordinary gates still enter the unchanged fallback checks */
	public function testOrdinaryVersionGateIsNotConsumed(): void {
		self::assertFalse(VersionedOmissionContract::handled('ordinary', ['missing' => []], [], static fn() => null, static fn() => false));
	}
}
