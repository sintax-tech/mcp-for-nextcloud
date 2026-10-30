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
		$after['lib/Tools/Writer.php'] = str_replace(['use Acme\Access\Visibility\VisibilityGuard;', 'VisibilityGuard $guard'], ['use Acme\Service\GrantPolicy;', 'GrantPolicy $guard'], $after['lib/Tools/Writer.php']);
		$this->assertProblem('lib/Tools/Writer.php: not equivalent to lib/Tools/Writer.php: line 13 `C:Acme\Access\Visibility\VisibilityGuard` became line 13 `C:Acme\Service\GrantPolicy`', $after);
	}

	/** Keeping the short name while pointing the import elsewhere is caught too. */
	public function testAnAliasRedirectedToAnotherClassFails(): void {
		$after = $this->moved();
		$after['lib/Tools/Writer.php'] = str_replace('use Acme\Access\Visibility\VisibilityGuard;', 'use Acme\Service\GrantPolicy as VisibilityGuard;', $after['lib/Tools/Writer.php']);
		$this->assertProblem('lib/Tools/Writer.php: not equivalent to lib/Tools/Writer.php: line 13 `C:Acme\Access\Visibility\VisibilityGuard` became line 13 `C:Acme\Service\GrantPolicy`', $after);
	}

	/** A fully qualified name in a method body replaced by another class fails. */
	public function testAFullyQualifiedNameInTheBodyReplacedFails(): void {
		$after = $this->moved();
		$after['lib/Tools/Writer.php'] = str_replace('\Acme\Service\Policy::check()', '\Acme\Service\Policy\Other::check()', $after['lib/Tools/Writer.php']);
		$this->assertProblem('lib/Tools/Writer.php: not equivalent to lib/Tools/Writer.php: line 17 `C:Acme\Service\Policy` became line 17 `C:Acme\Service\Policy\Other`', $after);
	}

	/** A file deleted without being added at its destination fails, and so do the references left pointing at it. */
	public function testAFileOmittedAtTheDestinationFails(): void {
		$after = $this->moved();
		unset($after['lib/Access/Visibility/VisibilityGuard.php']);
		$problems = $this->check($after);
		$this->assertContains('lib/Service/VisibilityGuard.php: deleted without a destination (file omitted)', $problems);
		$this->assertContains('lib/Tools/Writer.php:13: refers to Acme\Access\Visibility\VisibilityGuard, which no file declares', $problems);
	}

	/** With a ticket, every entry the map gives that ticket must be at its destination. */
	public function testAnEntryOfTheTicketLeftInPlaceFails(): void {
		$this->assertSame(['lib/Service/VisibilityGuard.php: RE-05 plans Acme\Access\Visibility\VisibilityGuard, missing at its destination (file omitted)'], $this->check(self::base(), 'RE-05'));
		$this->assertSame([], $this->check($this->moved(), 'RE-05'));
	}

	/** A class-name string changed to anything but its planned name fails. */
	public function testAStringWithAnAlteredClassNameFails(): void {
		$after = $this->moved();
		$after['lib/Service/Registry.php'] = str_replace("'Acme\\\\Access\\\\Visibility\\\\VisibilityGuard'", "'Acme\\\\Access\\\\Visibility\\\\VisibilityGard'", $after['lib/Service/Registry.php']);
		$this->assertProblem('lib/Service/Registry.php: not equivalent to lib/Service/Registry.php: line 9 `S=Acme\Access\Visibility\VisibilityGuard` became line 11 `S=Acme\Access\Visibility\VisibilityGard`', $after);
	}

	/** A string still naming the old class is stale. */
	public function testAStringLeftWithTheOldNameFails(): void {
		$after = $this->moved();
		$after['lib/Service/Registry.php'] = self::base()['lib/Service/Registry.php'];
		$this->assertContains('lib/Service/Registry.php:9: still refers to Acme\Service\VisibilityGuard, which no longer exists under that name', $this->check($after));
	}

	/** A same-namespace caller that was not given an import is stale: the unqualified name now resolves to nothing. */
	public function testAnUnchangedSameNamespaceCallerFails(): void {
		$after = $this->moved();
		$after['lib/Service/Registry.php'] = str_replace("use Acme\\Access\\Visibility\\VisibilityGuard;\n", '', $after['lib/Service/Registry.php']);
		$problems = $this->check($after);
		$this->assertContains('lib/Service/Registry.php:12: still refers to Acme\Service\VisibilityGuard, which no longer exists under that name', $problems);
	}

	/** A class moved somewhere the map does not plan fails, even when every reference follows it. */
	public function testAMoveTheMapDoesNotPlanFails(): void {
		$after = [];
		foreach ($this->moved() as $path => $content) {
			$after[str_replace('Access/Visibility', 'Access/Elsewhere', $path)] = str_replace('Access\Visibility', 'Access\Elsewhere', $content);
		}
		$this->assertContains('lib/Access/Elsewhere/VisibilityGuard.php: Acme\Service\VisibilityGuard became Acme\Access\Elsewhere\VisibilityGuard, which the FQCN map does not plan', $this->check($after));
	}

	/** A move commit adds no class and changes no non-PHP file other than the map and the size inventory. */
	public function testNewFilesAndOtherDataFail(): void {
		$after = $this->moved() + ['lib/Service/Extra.php' => "<?php\nnamespace Acme\\Service;\nfinal class Extra {}\n", 'js/admin.js' => 'x'];
		$after['scripts/dev/fqcn-map.json'] = '{}';
		$problems = $this->check($after);
		$this->assertContains('lib/Service/Extra.php: new file in a move range', $problems);
		$this->assertContains('js/admin.js: non-PHP file changed; a move commit only moves PHP and updates the map', $problems);
		$this->assertNotContains('scripts/dev/fqcn-map.json: non-PHP file changed; a move commit only moves PHP and updates the map', $problems);
	}

	/** A file naming a planned destination that does not exist yet is not stale, so a tree passes against itself (review A1). */
	public function testAPlannedDestinationIsNotAStaleReference(): void {
		$tree = self::base() + ['tests/Unit/PlanFixture.php' => "<?php\nnamespace Acme\\Tests\\Unit;\nfinal class PlanFixture {\n\tconst TARGET = 'Acme\\\\Access\\\\Visibility\\\\VisibilityGuard';\n}\n"];
		$this->assertSame([], (new MoveEquivalence($tree, $tree, self::map()))->check());
	}

	/** An attribute class moved by the map follows the move even with arguments; redirecting it does not pass (review A3). */
	public function testAnAttributeWithArgumentsFollowsTheMap(): void {
		$attributed = static fn (array $tree): array => ['lib/Tools/Writer.php' => str_replace('	public function write(', "	#[VisibilityGuard(action: 'write'), GrantPolicy]\n	public function write(", $tree['lib/Tools/Writer.php'])] + $tree;
		$before = $attributed(self::base());
		$after = $attributed($this->moved());
		$this->assertSame([], (new MoveEquivalence($before, $after, self::map()))->check());
		$after['lib/Tools/Writer.php'] = str_replace('use Acme\Access\Visibility\VisibilityGuard;', 'use Acme\Service\GrantPolicy as VisibilityGuard;', $after['lib/Tools/Writer.php']);
		$this->assertNotSame([], (new MoveEquivalence($before, $after, self::map()))->check());
	}

	/** Reordering imports, reformatting and editing comments are not semantic and pass. */
	public function testImportOrderWhitespaceAndCommentsAreIgnored(): void {
		$after = $this->moved();
		$after['lib/Tools/Writer.php'] = str_replace(
			["use Acme\\Service\\GrantPolicy;\nuse Acme\\Access\\Visibility\\VisibilityGuard;", '/** Writes. */'],
			["use Acme\\Access\\Visibility\\VisibilityGuard;\nuse Acme\\Service\\GrantPolicy;", "/**\n * Writes, reworded.\n */"],
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
			$entry('lib/Service/VisibilityGuard.php', 'Acme\Service\VisibilityGuard', 'Acme\Access\Visibility\VisibilityGuard', 'RE-05'),
			$entry('lib/Service/GrantPolicy.php', 'Acme\Service\GrantPolicy', 'stays', null),
			$entry('lib/Service/Registry.php', 'Acme\Service\Registry', 'stays', null),
			$entry('lib/Tools/Writer.php', 'Acme\Tools\Writer', 'stays', null),
		], 'Acme\\', 'lib/');
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

			namespace Acme\Tools;

			use Acme\Service\GrantPolicy;
			use Acme\Service\VisibilityGuard;

			/** Writes. */
			final class Writer {
				public function __construct(
					private GrantPolicy $grants,
					private VisibilityGuard $guard,
				) {
				}
				public function write(string $path): bool {
					return \Acme\Service\Policy::check() && $this->guard->allows($path) && VisibilityGuard::class !== '';
				}
			}

			PHP;
		$registry = <<<'PHP'
			<?php
			declare(strict_types=1);

			namespace Acme\Service;

			final class Registry {
				public function names(): array {
					return [
						'Acme\\Service\\VisibilityGuard',
						'not a class',
						VisibilityGuard::class,
					];
				}
			}

			PHP;
		return [
			'lib/Service/VisibilityGuard.php' => "<?php\ndeclare(strict_types=1);\n\nnamespace Acme\\Service;\n\nfinal class VisibilityGuard {\n\tpublic function allows(string \$path): bool {\n\t\treturn \$path !== '' && GrantPolicy::open();\n\t}\n}\n",
			'lib/Service/GrantPolicy.php' => "<?php\nnamespace Acme\\Service;\nfinal class GrantPolicy {\n\tpublic static function open(): bool {\n\t\treturn true;\n\t}\n}\n",
			'lib/Service/Registry.php' => $registry,
			'lib/Tools/Writer.php' => $writer,
			'tests/Unit/Service/VisibilityGuardTest.php' => "<?php\nnamespace Acme\\Tests\\Unit\\Service;\n\nuse Acme\\Service\\VisibilityGuard;\n\nfinal class VisibilityGuardTest {\n\tpublic function testAllows(): void {\n\t\tnew VisibilityGuard();\n\t}\n}\n",
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
			["namespace Acme\\Service;\n", 'GrantPolicy::open()'],
			["namespace Acme\\Access\\Visibility;\n\nuse Acme\\Service\\GrantPolicy;\n", 'GrantPolicy::open()'],
			$tree['lib/Service/VisibilityGuard.php'],
		);
		$test = str_replace(['Tests\\Unit\\Service;', 'use Acme\\Service\\VisibilityGuard;'], ['Tests\\Unit\\Access\\Visibility;', 'use Acme\\Access\\Visibility\\VisibilityGuard;'], $tree['tests/Unit/Service/VisibilityGuardTest.php']);
		unset($tree['lib/Service/VisibilityGuard.php'], $tree['tests/Unit/Service/VisibilityGuardTest.php']);
		$tree['lib/Access/Visibility/VisibilityGuard.php'] = $guard;
		$tree['tests/Unit/Access/Visibility/VisibilityGuardTest.php'] = $test;
		$tree['lib/Tools/Writer.php'] = str_replace('use Acme\Service\VisibilityGuard;', 'use Acme\Access\Visibility\VisibilityGuard;', $tree['lib/Tools/Writer.php']);
		$tree['lib/Service/Registry.php'] = str_replace(
			["final class Registry", "'Acme\\\\Service\\\\VisibilityGuard'"],
			["use Acme\\Access\\Visibility\\VisibilityGuard;\n\nfinal class Registry", "'Acme\\\\Access\\\\Visibility\\\\VisibilityGuard'"],
			$tree['lib/Service/Registry.php'],
		);
		$tree['scripts/dev/fqcn-map.json'] = '{"map": "after"}';
		return $tree;
	}
}
