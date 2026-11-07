<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Tests\Unit\Contract\NextcloudApi;

use OCA\Mcp\Modules\Polls\Creation\CreateDefaults;
use PHPUnit\Framework\Assert;
/** Explicit omission, distinct from a fallback API. All ordinary version gates keep the original contract. */
final class VersionedOmissionContract {
	/**
	 * Checks only the declared optional field, its exact native pins, and actual projection/dispatch in both layouts.
	 * @param string $api native getter
	 * @param array<string,mixed> $gate declaration
	 * @param list<string> $majors covered majors
	 * @param callable(string,string):?array $surface native source surface
	 * @param callable(?array,string):bool $serves native method predicate
	 * @return bool whether this is an omission gate; false preserves the ordinary fallback contract unchanged
	 */
	public static function handled(string $api, array $gate, array $majors, callable $surface, callable $serves): bool {
		if (!array_key_exists('omittedField', $gate)) {
			return false;
		}
		Assert::assertSame('OCA\Polls\Db\Poll::getTimezoneName', $api);
		Assert::assertSame(['31', '32'], $gate['missing']);
		Assert::assertSame('timezoneName', $gate['omittedField']);
		Assert::assertSame(CreateDefaults::class, $gate['by']);
		Assert::assertArrayNotHasKey('fallback', $gate);
		Assert::assertSame(['31' => '8.6.3', '32' => '8.6.3', '33' => '9.0.7', '34' => '9.1.4', '35' => '9.3.0'], $gate['pins']);
		[$class, $member] = explode('::', $api);
		foreach ($majors as $major) {
			$available = !in_array($major, $gate['missing'], true);
			Assert::assertSame($available, $serves($surface($major, $class), $member), $api . ' at ' . $major);
			$native = new VersionedOmissionProbe(CreateDefaults::observable($available), $available);
			$actual = CreateDefaults::read($native, $available);
			Assert::assertSame(CreateDefaults::observable($available), $actual);
			Assert::assertSame($available ? 1 : 0, $native->calls);
			Assert::assertSame($available, array_key_exists('timezoneName', $actual));
			Assert::assertSame($available, array_key_exists('timezoneName', CreateDefaults::persisted($available)));
			if ($available) {
				Assert::assertNull($actual['timezoneName']);
			}
		}
		return true;
	}
}
