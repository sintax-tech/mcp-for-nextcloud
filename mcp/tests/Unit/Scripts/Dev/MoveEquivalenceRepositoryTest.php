<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Scripts\Dev;

use OCA\Mcp\Scripts\Dev\FqcnMap;
use OCA\Mcp\Scripts\Dev\MoveEquivalence;
use OCA\Mcp\Scripts\Dev\SourceTrees;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../scripts/dev/bootstrap.php';

/**
 * Runs the token comparator on the real app tree with the real FQCN map: the tree against itself, a real move of
 * `UserTimezone` (planned for RE-04) with its test and every caller, and the reviewer's real mutations on top of it.
 *
 * Class names are assembled from segments, so this file never holds a literal class name that a later move would
 * leave stale. Once RE-04 moves `UserTimezone` for real, the move tests are skipped (that ticket proves its own).
 */
final class MoveEquivalenceRepositoryTest extends TestCase {
	/** @var array<string,string>|null the app tree, read once */
	private static ?array $tree = null;

	/** The unchanged tree is equivalent to itself, including the fixtures that name planned destinations (review A1). */
	public function testTheTreeAgainstItselfPasses(): void {
		$this->assertSame([], (new MoveEquivalence(self::tree(), self::tree(), self::map()))->check());
	}

	/** A `git mv` of UserTimezone and its test, with namespaces and every caller updated, passes. */
	public function testARealMovePasses(): void {
		$equivalence = new MoveEquivalence(self::tree(), $this->moved(), self::map());
		$this->assertSame([], $equivalence->check());
		$this->assertCount(2, $equivalence->pairs());
	}

	/** One space added inside an interpolated message of ArgumentValidator fails (review A2). */
	public function testAWhitespaceChangeInsideARealStringFails(): void {
		$after = $this->moved();
		$path = 'lib/Tools/ArgumentValidator.php';
		$after[$path] = preg_replace('/"Invalid argument: \$key"/', '" Invalid argument: $key"', $after[$path], 1);
		$problems = (new MoveEquivalence(self::tree(), $after, self::map()))->check();
		$this->assertCount(1, $problems);
		$this->assertStringStartsWith("$path: not equivalent to $path:", $problems[0]);
	}

	/** Redirecting the brute-force attribute of OAuthController to another attribute under the same alias fails (review A3). */
	public function testARedirectedSecurityAttributeFails(): void {
		$after = $this->moved();
		$path = 'lib/Controller/OAuthController.php';
		$attribute = implode('\\', ['OCP', 'AppFramework', 'Http', 'Attribute', '']);
		$after[$path] = str_replace("use {$attribute}BruteForceProtection;", "use {$attribute}NoCSRFRequired as BruteForceProtection;", $after[$path]);
		$problems = (new MoveEquivalence(self::tree(), $after, self::map()))->check();
		$this->assertCount(1, $problems);
		$this->assertStringContainsString("C:{$attribute}BruteForceProtection` became", $problems[0]);
	}

	/**
	 * The tree after moving UserTimezone to Platform\Time, as the RE-04 move commit would.
	 *
	 * @return array<string,string> path => content
	 */
	private function moved(): array {
		$tree = self::tree();
		if (!isset($tree['lib/Service/UserTimezone.php'])) {
			$this->markTestSkipped('UserTimezone already moved; RE-04 runs the comparator on its own move commit');
		}
		$old = ['OCA', 'Mcp', 'Service', 'UserTimezone'];
		$new = ['OCA', 'Mcp', 'Platform', 'Time', 'UserTimezone'];
		$moved = [];
		foreach ($tree as $path => $content) {
			$moved[$path] = str_replace([implode('\\', $old), implode('\\\\', $old)], [implode('\\', $new), implode('\\\\', $new)], $content);
		}
		$namespace = static fn (array $segments): string => 'namespace ' . implode('\\', $segments) . ';';
		$moved['lib/Platform/Time/UserTimezone.php'] = str_replace($namespace(['OCA', 'Mcp', 'Service']), $namespace(['OCA', 'Mcp', 'Platform', 'Time']), $moved['lib/Service/UserTimezone.php']);
		$moved['tests/Unit/Platform/Time/UserTimezoneTest.php'] = str_replace($namespace(['OCA', 'Mcp', 'Tests', 'Unit']), $namespace(['OCA', 'Mcp', 'Tests', 'Unit', 'Platform', 'Time']), $moved['tests/Unit/UserTimezoneTest.php']);
		unset($moved['lib/Service/UserTimezone.php'], $moved['tests/Unit/UserTimezoneTest.php']);
		return $moved;
	}

	/**
	 * The PHP and data files of the app a move commit can touch.
	 *
	 * @return array<string,string> path => content
	 */
	private static function tree(): array {
		return self::$tree ??= SourceTrees::fromDirectory(__DIR__ . '/../../../..', ['appinfo', 'lib', 'scripts', 'templates', 'tests']);
	}

	/**
	 * The real FQCN map.
	 *
	 * @return FqcnMap the map
	 */
	private static function map(): FqcnMap {
		return FqcnMap::fromJson(self::tree()['scripts/dev/fqcn-map.json']);
	}
}
