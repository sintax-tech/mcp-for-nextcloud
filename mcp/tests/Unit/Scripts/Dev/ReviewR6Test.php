<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Scripts\Dev;

use OCA\Mcp\Scripts\Dev\FqcnMap;
use OCA\Mcp\Scripts\Dev\MoveEquivalence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../scripts/dev/bootstrap.php';

/** R6 ordered expectations must be prepared for either assertion method and either array syntax. */
final class ReviewR6Test extends TestCase {
	/**
	 * An inline expectation is refused; assigning and explicitly sorting it before the move is accepted.
	 *
	 * @param string $assert assertion method
	 * @param string $array inline expected array
	 */
	#[DataProvider('expectations')]
	public function testOrderedExpectations(string $assert, string $array): void {
		$problems = implode("\n", $this->compare("sort(\$actual); \$this->$assert($array, \$actual);"));
		$this->assertStringContainsString('tests/Caller.php:1: path not proved: inline expected path order', $problems);
		$this->assertSame([], $this->compare("\$expected = $array; sort(\$expected); sort(\$actual); \$this->$assert(\$expected, \$actual);"));
	}

	/** @return iterable<string,array{string,string}> methods, short/long arrays and mixed nested delimiters */
	public static function expectations(): iterable {
		foreach (['assertSame', 'assertEquals'] as $assert) {
			foreach (["['A/Probe.php']", "array('A/Probe.php')", "[array('A/Probe.php')]", "array(['A/Probe.php'])"] as $array) {
				yield "$assert $array" => [$assert, $array];
			}
		}
	}

	/** Closing the first argument must stop the array scan before a path in the actual value. */
	public function testOtherArgumentsAreNotExpectedArrays(): void {
		$this->assertSame([], $this->compare("sort(\$actual); \$this->assertEquals(array([1]), 'A/Probe.php');"));
	}

	/**
	 * Updates the path in a tiny mapped move so only the expectation order can make the proof fail.
	 *
	 * @param string $caller original reader body
	 * @return list<string> diagnostics
	 */
	private function compare(string $caller): array {
		$map = new FqcnMap([['path' => 'lib/A/Probe.php', 'fqcn' => 'Acme\\A\\Probe', 'target' => 'Acme\\B\\Probe', 'owner' => 'x', 'ticket' => 'RE-X']], 'Acme\\', 'lib/');
		$before = ['lib/A/Probe.php' => '<?php namespace Acme\\A; class Probe {}', 'tests/Caller.php' => '<?php ' . $caller];
		$after = ['lib/B/Probe.php' => '<?php namespace Acme\\B; class Probe {}', 'tests/Caller.php' => '<?php ' . str_replace('A/Probe.php', 'B/Probe.php', $caller)];
		return (new MoveEquivalence($before, $after, $map))->check();
	}
}
