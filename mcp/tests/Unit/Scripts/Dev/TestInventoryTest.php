<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Scripts\Dev;

use OCA\Mcp\Scripts\Dev\FqcnMap;
use OCA\Mcp\Scripts\Dev\JunitReport;
use OCA\Mcp\Scripts\Dev\TestInventory;
use OCA\Mcp\Scripts\Dev\TestInventoryComparison;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../scripts/dev/bootstrap.php';

/**
 * Covers scripts/dev/test_inventory.php: test identities are collected from the sources, followed through the FQCN
 * map when a test moves, and a baseline test that disappears, or a change in the failure set, is reported.
 */
final class TestInventoryTest extends TestCase {
	/** `test*` methods, `#[Test]` and `@test` count; private, protected and helper methods do not. */
	public function testTheInventoryFindsEveryTestMethod(): void {
		$code = <<<'PHP'
			<?php
			namespace Acme\Tests\Unit\Service;
			use PHPUnit\Framework\Attributes\Test;
			final class GuardTest extends \PHPUnit\Framework\TestCase {
				public function testAllows(): void { $x = "{$this->name}"; }
				#[Test]
				public function refusesHidden(): void {}
				/** @test */
				public function refusesGuests(): void {}
				private function testHelper(): void {}
				protected function build(): void {}
				public static function provider(): array { return []; }
			}
			PHP;
		$this->assertSame([
			'Acme\Tests\Unit\Service\GuardTest::refusesGuests',
			'Acme\Tests\Unit\Service\GuardTest::refusesHidden',
			'Acme\Tests\Unit\Service\GuardTest::testAllows',
		], TestInventory::scan(['tests/Unit/Service/GuardTest.php' => $code, 'lib/Service/Guard.php' => "<?php\nclass X { public function testNo() {} }\n"]));
	}

	/**
	 * A test that moved with its class is found through the map; one at the Unit root follows its class too; a
	 * test with no class of lib/ to follow moves only through the map's `tests` list.
	 */
	public function testMovedTestsAreFoundThroughTheMap(): void {
		$comparison = new TestInventoryComparison(self::map());
		$baseline = ['Acme\Tests\Unit\Service\GuardTest::testA', 'Acme\Tests\Unit\PolicyTest::testB', 'Acme\Tests\Unit\Other\HelperTest::testC'];
		$current = ['Acme\Tests\Unit\Access\GuardTest::testA', 'Acme\Tests\Unit\Access\PolicyTest::testB', 'Acme\Tests\Unit\Moved\HelperTest::testC'];
		$this->assertSame([], $comparison->missing($baseline, $current));
		$this->assertSame(['exact' => 0, 'map' => 3], $comparison->rules);
	}

	/** A class elsewhere with the same short name never stands in for a baseline test (review A5). */
	public function testAShortNameLookAlikeOutsideTheMapIsMissing(): void {
		$comparison = new TestInventoryComparison(self::map());
		$this->assertSame(
			['Acme\Tests\Unit\TimezoneTest::testA (only Unrelated\TimezoneTest has the same short name; list the move under "tests" in the FQCN map)'],
			$comparison->missing(['Acme\Tests\Unit\TimezoneTest::testA'], ['Unrelated\TimezoneTest::testA']),
		);
		$this->assertSame(['exact' => 0, 'map' => 0], $comparison->rules);
	}

	/** A renamed or deleted baseline test is reported, even when the total count stays the same. */
	public function testAMissingBaselineTestIsReported(): void {
		$comparison = new TestInventoryComparison(self::map());
		$baseline = ['Acme\Tests\Unit\Service\GuardTest::testA', 'Acme\Tests\Unit\Service\GuardTest::testB'];
		$current = ['Acme\Tests\Unit\Access\GuardTest::testA', 'Acme\Tests\Unit\Access\GuardTest::testRenamed'];
		$this->assertSame(['Acme\Tests\Unit\Service\GuardTest::testB (looked for Acme\Tests\Unit\Access\GuardTest::testB)'], $comparison->missing($baseline, $current));
	}

	/** The failure sets of two JUnit reports must be the same once the classes are mapped. */
	public function testFailureSetsAreComparedThroughTheMap(): void {
		$report = static fn (string $class, array $cases): JunitReport => JunitReport::fromXml('<testsuites><testsuite>' . implode('', array_map(
			static fn (string $name, bool $failed): string => "<testcase class=\"$class\" name=\"$name\">" . ($failed ? '<error>x</error>' : '') . '</testcase>',
			array_keys($cases),
			$cases,
		)) . '</testsuite></testsuites>');
		$comparison = new TestInventoryComparison(self::map());
		$before = $report('Acme\Tests\Unit\Service\GuardTest', ['testA' => true, 'testB' => false, 'testC with data set #0' => true]);
		$same = $report('Acme\Tests\Unit\Access\GuardTest', ['testA' => true, 'testB' => false, 'testC with data set #0' => true]);
		$this->assertSame(['gone' => [], 'new' => []], $comparison->failures($before, $same));
		$changed = $report('Acme\Tests\Unit\Access\GuardTest', ['testA' => false, 'testB' => true, 'testC with data set #0' => true]);
		$this->assertSame(['gone' => ['Acme\Tests\Unit\Access\GuardTest::testA'], 'new' => ['Acme\Tests\Unit\Access\GuardTest::testB']], $comparison->failures($before, $changed));
	}

	/**
	 * Fixture map: `Service\Guard` and `Service\Policy` move to `Access`; `Other\HelperTest` is a listed test move.
	 *
	 * @return FqcnMap the map
	 */
	private static function map(): FqcnMap {
		return new FqcnMap([
			['path' => 'lib/Service/Guard.php', 'fqcn' => 'Acme\Service\Guard', 'target' => 'Acme\Access\Guard', 'owner' => 'Access', 'ticket' => 'RE-05'],
			['path' => 'lib/Service/Policy.php', 'fqcn' => 'Acme\Service\Policy', 'target' => 'Acme\Access\Policy', 'owner' => 'Access', 'ticket' => 'RE-05'],
		], 'Acme\\', 'lib/', [['fqcn' => 'Acme\Tests\Unit\Other\HelperTest', 'target' => 'Acme\Tests\Unit\Moved\HelperTest']]);
	}
}
