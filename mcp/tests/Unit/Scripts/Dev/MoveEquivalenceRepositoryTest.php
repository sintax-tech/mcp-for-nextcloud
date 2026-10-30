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
 * Runs the token comparator on the real app tree with the real FQCN map: the tree against itself, real moves of
 * `UserTimezone` (planned for RE-04) and `PromptCatalog` (RE-03) with their tests and callers, and the reviewer's
 * real mutations on top of them.
 *
 * Class names and paths are assembled from segments, so this file never holds a literal name or path that a later
 * move would leave stale. Once a class moves for real, its tests are skipped (that ticket proves its own move).
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
		$equivalence = new MoveEquivalence(self::tree(), $this->userTimezone(), self::map());
		$this->assertSame([], $equivalence->check());
		$this->assertCount(2, $equivalence->pairs());
	}

	/** One space added inside an interpolated message of ArgumentValidator fails (review A2). */
	public function testAWhitespaceChangeInsideARealStringFails(): void {
		$after = $this->userTimezone();
		$path = self::lib(['Tools', 'ArgumentValidator']);
		$after[$path] = preg_replace('/"Invalid argument: \$key"/', '" Invalid argument: $key"', $after[$path], 1);
		$problems = (new MoveEquivalence(self::tree(), $after, self::map()))->check();
		$this->assertCount(1, $problems);
		$this->assertStringStartsWith("$path: not equivalent to $path:", $problems[0]);
	}

	/** Redirecting the brute-force attribute of OAuthController to another attribute under the same alias fails (review A3). */
	public function testARedirectedSecurityAttributeFails(): void {
		$after = $this->userTimezone();
		$path = self::lib(['Controller', 'OAuthController']);
		$attribute = implode('\\', ['OCP', 'AppFramework', 'Http', 'Attribute', '']);
		$after[$path] = str_replace("use {$attribute}BruteForceProtection;", "use {$attribute}NoCSRFRequired as BruteForceProtection;", $after[$path]);
		$problems = (new MoveEquivalence(self::tree(), $after, self::map()))->check();
		$this->assertCount(1, $problems);
		$this->assertStringContainsString("C:{$attribute}BruteForceProtection` became", $problems[0]);
	}

	/** Moving PromptCatalogTest one folder deeper breaks `dirname(__DIR__, 3)`, so the RE-03 move fails (review R2-A6). */
	public function testAMoveThatChangesWhatDirPointsToFails(): void {
		$test = self::path('tests/Unit', ['Mcp', 'Prompts', 'PromptCatalogTest']);
		$problems = (new MoveEquivalence(self::tree(), $this->promptCatalog(false), self::map()))->check();
		$this->assertCount(1, $problems);
		// The old expression reaches a file under the app folder; the same text in the deeper folder reaches tests/.
		$this->assertMatchesRegularExpression('/^' . preg_quote("$test: not equivalent to ", '/') . '.*: line (\d+) `P:(\S+)` became line \1 `P:tests\/\2`$/', $problems[0]);
	}

	/** The same move passes once the test climbs one more level, `dirname(__DIR__, 4)`: the path is proved the same. */
	public function testAMoveThatRebasesDirEquivalentlyPasses(): void {
		$this->assertSame([], (new MoveEquivalence(self::tree(), $this->promptCatalog(true), self::map()))->check());
	}

	/**
	 * The tree after moving UserTimezone to Platform\Time, as the RE-04 move commit would.
	 *
	 * @return array<string,string> path => content
	 */
	private function userTimezone(): array {
		return $this->moved(['Service', 'UserTimezone'], ['Platform', 'Time', 'UserTimezone'], ['UserTimezoneTest'], ['Platform', 'Time', 'UserTimezoneTest'], []);
	}

	/**
	 * The tree after moving PromptCatalog and its test to Mcp\Prompts, as the RE-03 move commit would: the class
	 * imports the two Service classes it used unqualified, McpProtocol imports the moved class.
	 *
	 * @param bool $rebased whether the moved test climbs one more folder to reach the app folder
	 * @return array<string,string> path => content
	 */
	private function promptCatalog(bool $rebased): array {
		$imports = [
			self::lib(['Mcp', 'Prompts', 'PromptCatalog']) => [['Service', 'GrantPolicy'], ['Service', 'CheckoutPrompt']],
			self::lib(['Service', 'McpProtocol']) => [['Mcp', 'Prompts', 'PromptCatalog']],
		];
		$tree = $this->moved(['Service', 'PromptCatalog'], ['Mcp', 'Prompts', 'PromptCatalog'], ['Service', 'PromptCatalogTest'], ['Mcp', 'Prompts', 'PromptCatalogTest'], $imports);
		if ($rebased) {
			$test = self::path('tests/Unit', ['Mcp', 'Prompts', 'PromptCatalogTest']);
			$tree[$test] = str_replace('dirname(__DIR__, 3)', 'dirname(__DIR__, 4)', $tree[$test]);
		}
		return $tree;
	}

	/**
	 * The tree after a `git mv` of one class and its test, with every reference updated.
	 *
	 * @param list<string> $class class segments under the app namespace
	 * @param list<string> $target target segments under the app namespace
	 * @param list<string> $test test class segments under tests/Unit
	 * @param list<string> $targetTest target test segments under tests/Unit
	 * @param array<string,list<list<string>>> $imports path (after the move) => classes to import after its namespace line
	 * @return array<string,string> path => content
	 */
	private function moved(array $class, array $target, array $test, array $targetTest, array $imports): array {
		$tree = self::tree();
		if (!isset($tree[self::lib($class)])) {
			$this->markTestSkipped(implode('\\', $class) . ' already moved; its ticket runs the comparator on its own move commit');
		}
		$fqcn = static fn (array $segments, string $glue): string => implode($glue, ['OCA', 'Mcp', ...$segments]);
		$moved = [];
		foreach ($tree as $path => $content) {
			$moved[$path] = str_replace([$fqcn($class, '\\'), $fqcn($class, '\\\\')], [$fqcn($target, '\\'), $fqcn($target, '\\\\')], $content);
		}
		$namespace = static fn (array $segments): string => 'namespace ' . $fqcn(array_slice($segments, 0, -1), '\\') . ';';
		$moved[self::lib($target)] = str_replace($namespace($class), $namespace($target), $moved[self::lib($class)]);
		$tests = static fn (array $segments): array => ['Tests', 'Unit', ...$segments];
		$moved[self::path('tests/Unit', $targetTest)] = str_replace($namespace($tests($test)), $namespace($tests($targetTest)), $moved[self::path('tests/Unit', $test)]);
		unset($moved[self::lib($class)], $moved[self::path('tests/Unit', $test)]);
		foreach ($imports as $path => $classes) {
			$lines = implode('', array_map(static fn (array $segments): string => 'use ' . $fqcn($segments, '\\') . ";\n", $classes));
			$moved[$path] = preg_replace('/^namespace [^;]+;\n/m', "\$0\n$lines", $moved[$path], 1);
		}
		return $moved;
	}

	/**
	 * Path of a class under lib/, built from segments so this file names no real path.
	 *
	 * @param list<string> $segments class segments under the app namespace
	 * @return string the path
	 */
	private static function lib(array $segments): string {
		return self::path('lib', $segments);
	}

	/**
	 * Path of a PHP file from its folder and segments.
	 *
	 * @param string $folder folder relative to the app folder
	 * @param list<string> $segments path segments, the last one the file name without extension
	 * @return string the path
	 */
	private static function path(string $folder, array $segments): string {
		return $folder . '/' . implode('/', $segments) . '.php';
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
