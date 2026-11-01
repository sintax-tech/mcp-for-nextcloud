<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Scripts\Dev;

use OCA\Mcp\Scripts\Dev\FqcnMap;
use OCA\Mcp\Scripts\Dev\MoveEquivalence;
use OCA\Mcp\Scripts\Dev\PathRewrites;
use OCA\Mcp\Scripts\Dev\SourceTrees;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../scripts/dev/bootstrap.php';

/**
 * Runs the token comparator on the real app tree with the real FQCN map: the tree against itself, real moves of
 * `UserTimezone` (planned for RE-04) and `PromptCatalog` (RE-03) with their tests and callers, and the reviewer's
 * real mutations on top of them.
 *
 * Class names and paths are assembled from segments, so this file never holds a literal name or path that a later
 * move would leave stale. Both move fixtures rebuild the earlier tree once their classes have moved, so their
 * positive and negative controls keep running.
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
		[$before, $after] = $this->userTimezone();
		$equivalence = new MoveEquivalence($before, $after, self::map());
		$this->assertSame([], $equivalence->check());
		$this->assertCount(2, $equivalence->pairs());
		$this->assertEveryReaderFailsWhenForgotten($after, self::lib(['Service', 'UserTimezone']), self::lib(['Platform', 'Time', 'UserTimezone']), $before);
	}

	/** One space added inside an interpolated message of ArgumentValidator fails (review A2). */
	public function testAWhitespaceChangeInsideARealStringFails(): void {
		[$before, $after] = $this->userTimezone();
		$path = self::located(['Tools', 'ArgumentValidator']);
		$after[$path] = preg_replace('/"Invalid argument: \$key"/', '" Invalid argument: $key"', $after[$path], 1);
		$problems = (new MoveEquivalence($before, $after, self::map()))->check();
		$this->assertCount(1, $problems);
		$this->assertStringStartsWith("$path: not equivalent to $path:", $problems[0]);
	}

	/** Redirecting the brute-force attribute of OAuthController to another attribute under the same alias fails (review A3). */
	public function testARedirectedSecurityAttributeFails(): void {
		[$before, $after] = $this->userTimezone();
		$path = self::lib(['Controller', 'OAuthController']);
		$attribute = implode('\\', ['OCP', 'AppFramework', 'Http', 'Attribute', '']);
		$after[$path] = str_replace("use {$attribute}BruteForceProtection;", "use {$attribute}NoCSRFRequired as BruteForceProtection;", $after[$path]);
		$problems = (new MoveEquivalence($before, $after, self::map()))->check();
		$this->assertCount(1, $problems);
		$this->assertStringContainsString("C:{$attribute}BruteForceProtection` became", $problems[0]);
	}

	/** Moving PromptCatalogTest one folder deeper breaks `dirname(__DIR__, 3)`, so the RE-03 move fails (review R2-A6). */
	public function testAMoveThatChangesWhatDirPointsToFails(): void {
		$test = self::path('tests/Unit', ['Mcp', 'Prompts', 'PromptCatalogTest']);
		[$before, $after] = $this->promptCatalogMove(false);
		$problems = (new MoveEquivalence($before, $after, self::map()))->check();
		$this->assertCount(1, $problems);
		// The old expression reaches a file under the app folder; the same text in the deeper folder reaches tests/.
		$this->assertStringStartsWith("$test: not equivalent to ", $problems[0]);
		$this->assertStringContainsString('`P:.|dirname(__DIR__)` became line 163 `P:tests|dirname(__DIR__)`', $problems[0]);
	}

	/** The same move passes once the test climbs one more level, `dirname(__DIR__, 4)`: the path is proved the same. */
	public function testAMoveThatRebasesDirEquivalentlyPasses(): void {
		[$before, $after] = $this->promptCatalogMove(true);
		$this->assertSame([], (new MoveEquivalence($before, $after, self::map()))->check());
		$this->assertEveryReaderFailsWhenForgotten($after, self::lib(['Service', 'PromptCatalog']), self::lib(['Mcp', 'Prompts', 'PromptCatalog']), $before);
	}

	/** The real bootstrap autoload must not accept an inaccessible, lexically cancelling path (R3-A7). */
	public function testRealAutoloadWithMissingSegmentFails(): void {
		[$before, $after] = $this->userTimezone();
		$path = self::lib(['AppInfo', 'Application']);
		$after[$path] = str_replace("'/../../vendor/autoload.php'", "'/no-such-r3/../../../vendor/autoload.php'", $after[$path], $count);
		$this->assertSame(1, $count);
		$problems = (new MoveEquivalence($before, $after, self::map()))->check();
		$this->assertCount(1, $problems);
		$this->assertStringStartsWith("$path: not equivalent to $path:", $problems[0]);
	}

	/** The reviewer's unchanged historical description and backup filename both permit the real move (R3-A9). */
	public function testHistoricalAndBackupDescriptionsPassRealMove(): void {
		$path = self::lib(['AppInfo', 'Application']);
		$old = self::lib(['Service', 'UserTimezone']);
		foreach (["The former path was $old; this is historical text.", "$old.bak"] as $description) {
			[$before, $after] = $this->userTimezone();
			$before[$path] .= "\n\$description = '" . $description . "';\n";
			$after[$path] .= "\n\$description = '" . $description . "';\n";
			$this->assertSame([], (new MoveEquivalence($before, $after, self::map()))->check(), $description);
		}
	}

	/**
	 * Keeps a negative proof for every current path reader, including future oracle additions.
	 *
	 * @param array<string,string> $after correctly moved tree
	 * @param string $old old production path
	 * @param string $new new production path
	 * @param array<string,string>|null $before tree before the move; the real tree when null
	 * @return void
	 */
	private function assertEveryReaderFailsWhenForgotten(array $after, string $old, string $new, ?array $before = null): void {
		$before ??= self::tree();
		foreach (RepositoryMoveFixture::forgottenReaders($before, $after, $old, $new) as $reader => $forgotten) {
			$this->assertNotSame($after[$reader], $forgotten[$reader]);
			$this->assertStringContainsString("$reader:", implode("\n", (new MoveEquivalence($before, $forgotten, self::map()))->check()));
		}
	}

	/**
	 * Builds both sides of the RE-04 move before and after the real migration.
	 *
	 * @return array{0: array<string,string>, 1: array<string,string>} trees before and after the move
	 */
	private function userTimezone(): array {
		return UserTimezoneMoveFixture::trees(self::tree(), $this->moved(...));
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
	 * The RE-03 move of PromptCatalog and its test as trees before and after it. Until RE-03 moves the class, the real
	 * tree is the before side and the move is simulated; once it has moved, the real tree is the after side and the
	 * before side is the real tree moved back, so the proof keeps running instead of being skipped.
	 *
	 * @param bool $rebased whether the moved test climbs one more folder to reach the app folder
	 * @return array{0: array<string,string>, 1: array<string,string>} trees before and after the move
	 */
	private function promptCatalogMove(bool $rebased): array {
		if (isset(self::tree()[self::lib(['Service', 'PromptCatalog'])])) {
			return [self::tree(), $this->promptCatalog($rebased)];
		}
		$moved = ['Mcp', 'Prompts', 'PromptCatalogTest'];
		$before = $this->moved(['Mcp', 'Prompts', 'PromptCatalog'], ['Service', 'PromptCatalog'], $moved, ['Service', 'PromptCatalogTest'],
			[self::lib(['Service', 'PromptCatalog']) => [['Mcp', 'Prompts', 'CheckoutPrompt']]]);
		$after = self::tree();
		$climb = static fn (string $code): string => str_replace('dirname(__DIR__, 4)', 'dirname(__DIR__, 3)', $code);
		$before[self::path('tests/Unit', ['Service', 'PromptCatalogTest'])] = $climb($before[self::path('tests/Unit', ['Service', 'PromptCatalogTest'])]);
		if (!$rebased) {
			$after[self::path('tests/Unit', $moved)] = $climb($after[self::path('tests/Unit', $moved)]);
		}
		return [$before, $after];
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
			$pairs = [self::lib($class) => self::lib($target), self::path('tests/Unit', $test) => self::path('tests/Unit', $targetTest)];
			$moved[$path] = str_ends_with($path, '.php')
				? RepositoryMoveFixture::pathsOnly($moved[$path], $pairs)
				: PathRewrites::text($moved[$path], $pairs);
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
	 * Path of a class of the base under lib/ in this tree: where the base had it, or its destination in the map once
	 * its ticket has moved it.
	 *
	 * @param list<string> $segments class segments of the base under the app namespace
	 * @return string the path
	 */
	private static function located(array $segments): string {
		$path = self::lib($segments);
		foreach (self::map()->entries as $entry) {
			if (!isset(self::tree()[$path]) && $entry['path'] === $path) {
				return (string)self::map()->pathOf($entry['target']);
			}
		}
		return $path;
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
		return self::$tree ??= SourceTrees::fromDirectory(__DIR__ . '/../../../..', ['appinfo', 'lib', 'scripts', 'templates', 'tests', 'js']);
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
