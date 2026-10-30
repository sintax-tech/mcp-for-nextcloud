<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Scripts\Dev;

use OCA\Mcp\Scripts\Dev\FqcnMap;
use OCA\Mcp\Scripts\Dev\MoveEquivalence;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../scripts/dev/bootstrap.php';

/**
 * Covers scripts/dev/move_equivalence.php: a move commit passes only when it changes nothing but the class names
 * the FQCN map plans, in the moved files and in every caller.
 *
 * The base tree has a guard (`Service\VisibilityGuard`) moved to `Access\Visibility`, a caller importing it, a
 * caller in the guard's old namespace using it unqualified, a caller naming it in a string, and its test.
 */
final class MoveEquivalenceTest extends TestCase {
	/** A `git mv` of the guard and its test, with every caller updated, is equivalent. */
	public function testAMoveWithTheMapAndUpdatedCallersPasses(): void {
		$this->assertSame([], $this->check($this->moved()));
	}

	/** The pairing names the moved files. */
	public function testTheMovedFilesArePaired(): void {
		$equivalence = new MoveEquivalence(self::base(), $this->moved(), self::map());
		$equivalence->check();
		$this->assertSame([
			'lib/Service/VisibilityGuard.php' => 'lib/Access/Visibility/VisibilityGuard.php',
			'tests/Unit/Service/VisibilityGuardTest.php' => 'tests/Unit/Access/Visibility/VisibilityGuardTest.php',
		], $equivalence->pairs());
	}

	/** Importing another class in place of the guard is caught where the guard is used. */
	public function testAGuardImportReplacedByAnotherClassFails(): void {
		$after = $this->moved();
		$after['lib/Tools/Writer.php'] = str_replace(['use OCA\Mcp\Access\Visibility\VisibilityGuard;', 'VisibilityGuard $guard'], ['use OCA\Mcp\Service\GrantPolicy;', 'GrantPolicy $guard'], $after['lib/Tools/Writer.php']);
		$this->assertProblem('lib/Tools/Writer.php: not equivalent to lib/Tools/Writer.php: line 13 `C:OCA\Mcp\Access\Visibility\VisibilityGuard` became line 13 `C:OCA\Mcp\Service\GrantPolicy`', $after);
	}

	/** Keeping the short name while pointing the import elsewhere is caught too. */
	public function testAnAliasRedirectedToAnotherClassFails(): void {
		$after = $this->moved();
		$after['lib/Tools/Writer.php'] = str_replace('use OCA\Mcp\Access\Visibility\VisibilityGuard;', 'use OCA\Mcp\Service\GrantPolicy as VisibilityGuard;', $after['lib/Tools/Writer.php']);
		$this->assertProblem('lib/Tools/Writer.php: not equivalent to lib/Tools/Writer.php: line 13 `C:OCA\Mcp\Access\Visibility\VisibilityGuard` became line 13 `C:OCA\Mcp\Service\GrantPolicy`', $after);
	}

	/** A fully qualified name in a method body replaced by another class fails. */
	public function testAFullyQualifiedNameInTheBodyReplacedFails(): void {
		$after = $this->moved();
		$after['lib/Tools/Writer.php'] = str_replace('\OCA\Mcp\Service\Policy::check()', '\OCA\Mcp\Service\Policy\Other::check()', $after['lib/Tools/Writer.php']);
		$this->assertProblem('lib/Tools/Writer.php: not equivalent to lib/Tools/Writer.php: line 17 `C:OCA\Mcp\Service\Policy` became line 17 `C:OCA\Mcp\Service\Policy\Other`', $after);
	}

	/** A file deleted without being added at its destination fails, and so do the references left pointing at it. */
	public function testAFileOmittedAtTheDestinationFails(): void {
		$after = $this->moved();
		unset($after['lib/Access/Visibility/VisibilityGuard.php']);
		$problems = $this->check($after);
		$this->assertContains('lib/Service/VisibilityGuard.php: deleted without a destination (file omitted)', $problems);
		$this->assertContains('lib/Tools/Writer.php:13: still refers to OCA\Mcp\Access\Visibility\VisibilityGuard, which no longer exists under that name', $problems);
	}

	/** With a ticket, every entry the map gives that ticket must be at its destination. */
	public function testAnEntryOfTheTicketLeftInPlaceFails(): void {
		$this->assertSame(['lib/Service/VisibilityGuard.php: RE-05 plans OCA\Mcp\Access\Visibility\VisibilityGuard, missing at its destination (file omitted)'], $this->check(self::base(), 'RE-05'));
		$this->assertSame([], $this->check($this->moved(), 'RE-05'));
	}

	/** A class-name string changed to anything but its planned name fails. */
	public function testAStringWithAnAlteredClassNameFails(): void {
		$after = $this->moved();
		$after['lib/Service/Registry.php'] = str_replace("'OCA\\\\Mcp\\\\Access\\\\Visibility\\\\VisibilityGuard'", "'OCA\\\\Mcp\\\\Access\\\\Visibility\\\\VisibilityGard'", $after['lib/Service/Registry.php']);
		$this->assertProblem('lib/Service/Registry.php: not equivalent to lib/Service/Registry.php: line 9 `S:OCA\Mcp\Access\Visibility\VisibilityGuard` became line 11 `S:OCA\Mcp\Access\Visibility\VisibilityGard`', $after);
	}

	/** A string still naming the old class is stale. */
	public function testAStringLeftWithTheOldNameFails(): void {
		$after = $this->moved();
		$after['lib/Service/Registry.php'] = self::base()['lib/Service/Registry.php'];
		$this->assertContains('lib/Service/Registry.php:9: still refers to OCA\Mcp\Service\VisibilityGuard, which no longer exists under that name', $this->check($after));
	}

	/** A same-namespace caller that was not given an import is stale: the unqualified name now resolves to nothing. */
	public function testAnUnchangedSameNamespaceCallerFails(): void {
		$after = $this->moved();
		$after['lib/Service/Registry.php'] = str_replace("use OCA\\Mcp\\Access\\Visibility\\VisibilityGuard;\n", '', $after['lib/Service/Registry.php']);
		$problems = $this->check($after);
		$this->assertContains('lib/Service/Registry.php:12: still refers to OCA\Mcp\Service\VisibilityGuard, which no longer exists under that name', $problems);
	}

	/** A class moved somewhere the map does not plan fails, even when every reference follows it. */
	public function testAMoveTheMapDoesNotPlanFails(): void {
		$after = [];
		foreach ($this->moved() as $path => $content) {
			$after[str_replace('Access/Visibility', 'Access/Elsewhere', $path)] = str_replace('Access\Visibility', 'Access\Elsewhere', $content);
		}
		$this->assertContains('lib/Access/Elsewhere/VisibilityGuard.php: OCA\Mcp\Service\VisibilityGuard became OCA\Mcp\Access\Elsewhere\VisibilityGuard, which the FQCN map does not plan', $this->check($after));
	}

	/** A move commit adds no class and changes no non-PHP file other than the map and the size inventory. */
	public function testNewFilesAndOtherDataFail(): void {
		$after = $this->moved() + ['lib/Service/Extra.php' => "<?php\nnamespace OCA\\Mcp\\Service;\nfinal class Extra {}\n", 'js/admin.js' => 'x'];
		$after['scripts/dev/fqcn-map.json'] = '{}';
		$problems = $this->check($after);
		$this->assertContains('lib/Service/Extra.php: new file in a move range', $problems);
		$this->assertContains('js/admin.js: non-PHP file changed; a move commit only moves PHP and updates the map', $problems);
		$this->assertNotContains('scripts/dev/fqcn-map.json: non-PHP file changed; a move commit only moves PHP and updates the map', $problems);
	}

	/** Reordering imports, reformatting and editing comments are not semantic and pass. */
	public function testImportOrderWhitespaceAndCommentsAreIgnored(): void {
		$after = $this->moved();
		$after['lib/Tools/Writer.php'] = str_replace(
			["use OCA\\Mcp\\Service\\GrantPolicy;\nuse OCA\\Mcp\\Access\\Visibility\\VisibilityGuard;", '/** Writes. */'],
			["use OCA\\Mcp\\Access\\Visibility\\VisibilityGuard;\nuse OCA\\Mcp\\Service\\GrantPolicy;", "/**\n * Writes, reworded.\n */"],
			$after['lib/Tools/Writer.php'],
		);
		$this->assertSame([], $this->check($after));
	}

	/**
	 * Runs the comparator from the base tree.
	 *
	 * @param array<string,string> $after new tree
	 * @param string|null $ticket ticket whose entries must have moved
	 * @return list<string> problems
	 */
	private function check(array $after, ?string $ticket = null): array {
		return (new MoveEquivalence(self::base(), $after, self::map()))->check($ticket);
	}

	/**
	 * Asserts that one problem is reported.
	 *
	 * @param string $problem the expected problem
	 * @param array<string,string> $after new tree
	 */
	private function assertProblem(string $problem, array $after): void {
		$this->assertContains($problem, $this->check($after));
	}

	/**
	 * The map of the fixture: the guard moves in RE-05, everything else stays.
	 *
	 * @return FqcnMap the map
	 */
	private static function map(): FqcnMap {
		$entry = static fn (string $path, string $fqcn, string $target, ?string $ticket): array => ['path' => $path, 'fqcn' => $fqcn, 'target' => $target, 'owner' => 'x', 'ticket' => $ticket];
		return new FqcnMap([
			$entry('lib/Service/VisibilityGuard.php', 'OCA\Mcp\Service\VisibilityGuard', 'OCA\Mcp\Access\Visibility\VisibilityGuard', 'RE-05'),
			$entry('lib/Service/GrantPolicy.php', 'OCA\Mcp\Service\GrantPolicy', 'stays', null),
			$entry('lib/Service/Registry.php', 'OCA\Mcp\Service\Registry', 'stays', null),
			$entry('lib/Tools/Writer.php', 'OCA\Mcp\Tools\Writer', 'stays', null),
		], 'OCA\Mcp\\', 'lib/');
	}

	/**
	 * The base tree.
	 *
	 * @return array<string,string> path => content
	 */
	private static function base(): array {
		$writer = <<<'PHP'
			<?php
			declare(strict_types=1);

			namespace OCA\Mcp\Tools;

			use OCA\Mcp\Service\GrantPolicy;
			use OCA\Mcp\Service\VisibilityGuard;

			/** Writes. */
			final class Writer {
				public function __construct(
					private GrantPolicy $grants,
					private VisibilityGuard $guard,
				) {
				}
				public function write(string $path): bool {
					return \OCA\Mcp\Service\Policy::check() && $this->guard->allows($path) && VisibilityGuard::class !== '';
				}
			}

			PHP;
		$registry = <<<'PHP'
			<?php
			declare(strict_types=1);

			namespace OCA\Mcp\Service;

			final class Registry {
				public function names(): array {
					return [
						'OCA\\Mcp\\Service\\VisibilityGuard',
						'not a class',
						VisibilityGuard::class,
					];
				}
			}

			PHP;
		return [
			'lib/Service/VisibilityGuard.php' => "<?php\ndeclare(strict_types=1);\n\nnamespace OCA\\Mcp\\Service;\n\nfinal class VisibilityGuard {\n\tpublic function allows(string \$path): bool {\n\t\treturn \$path !== '' && GrantPolicy::open();\n\t}\n}\n",
			'lib/Service/GrantPolicy.php' => "<?php\nnamespace OCA\\Mcp\\Service;\nfinal class GrantPolicy {\n\tpublic static function open(): bool {\n\t\treturn true;\n\t}\n}\n",
			'lib/Service/Registry.php' => $registry,
			'lib/Tools/Writer.php' => $writer,
			'tests/Unit/Service/VisibilityGuardTest.php' => "<?php\nnamespace OCA\\Mcp\\Tests\\Unit\\Service;\n\nuse OCA\\Mcp\\Service\\VisibilityGuard;\n\nfinal class VisibilityGuardTest {\n\tpublic function testAllows(): void {\n\t\tnew VisibilityGuard();\n\t}\n}\n",
			'scripts/dev/fqcn-map.json' => '{"map": "base"}',
		];
	}

	/**
	 * The base tree after `git mv` of the guard and its test, with the namespace, the imports and the string updated.
	 *
	 * @return array<string,string> path => content
	 */
	private function moved(): array {
		$tree = self::base();
		$guard = str_replace(
			["namespace OCA\\Mcp\\Service;\n", 'GrantPolicy::open()'],
			["namespace OCA\\Mcp\\Access\\Visibility;\n\nuse OCA\\Mcp\\Service\\GrantPolicy;\n", 'GrantPolicy::open()'],
			$tree['lib/Service/VisibilityGuard.php'],
		);
		$test = str_replace(['Tests\\Unit\\Service;', 'use OCA\\Mcp\\Service\\VisibilityGuard;'], ['Tests\\Unit\\Access\\Visibility;', 'use OCA\\Mcp\\Access\\Visibility\\VisibilityGuard;'], $tree['tests/Unit/Service/VisibilityGuardTest.php']);
		unset($tree['lib/Service/VisibilityGuard.php'], $tree['tests/Unit/Service/VisibilityGuardTest.php']);
		$tree['lib/Access/Visibility/VisibilityGuard.php'] = $guard;
		$tree['tests/Unit/Access/Visibility/VisibilityGuardTest.php'] = $test;
		$tree['lib/Tools/Writer.php'] = str_replace('use OCA\Mcp\Service\VisibilityGuard;', 'use OCA\Mcp\Access\Visibility\VisibilityGuard;', $tree['lib/Tools/Writer.php']);
		$tree['lib/Service/Registry.php'] = str_replace(
			["final class Registry", "'OCA\\\\Mcp\\\\Service\\\\VisibilityGuard'"],
			["use OCA\\Mcp\\Access\\Visibility\\VisibilityGuard;\n\nfinal class Registry", "'OCA\\\\Mcp\\\\Access\\\\Visibility\\\\VisibilityGuard'"],
			$tree['lib/Service/Registry.php'],
		);
		$tree['scripts/dev/fqcn-map.json'] = '{"map": "after"}';
		return $tree;
	}
}
