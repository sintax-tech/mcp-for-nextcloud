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

/** Regressions for the four R3 holes and conservative refusals beyond the supported depth rule. */
final class PathSafetyTest extends TestCase {
	/**
	 * Unchanged callers still compare exact path suffixes, in every context (R3-A7).
	 *
	 * @param string $before source expression
	 * @param string $after changed expression
	 */
	#[DataProvider('changedPaths')]
	public function testLexicalPathChangesFail(string $before, string $after): void {
		$this->assertNotSame([], $this->compare('echo 1;', 'echo 1;', "<?php $before;", "<?php $after;"));
	}

	/**
	 * @return iterable<string,array{string,string}> before and after expressions
	 */
	public static function changedPaths(): iterable {
		yield 'printed dot' => ["echo __DIR__ . '/./a'", "echo __DIR__ . '/a'"];
		yield 'glob missing segment' => ["glob(__DIR__ . '/*.php')", "glob(__DIR__ . '/no-such/../*.php')"];
		yield 'realpath missing segment' => ["realpath(__DIR__ . '/Probe.php')", "realpath(__DIR__ . '/no-such/../Probe.php')"];
		yield 'autoload guard' => ["is_file(__DIR__ . '/../../vendor/autoload.php')", "is_file(__DIR__ . '/no-such/../../../vendor/autoload.php')"];
		yield 'cancelled segments' => ["echo __DIR__ . '/a/../b'", "echo __DIR__ . '/b'"];
	}

	/**
	 * Nonliteral and relative includes fail even when the tokens are unchanged (R3-A10).
	 *
	 * @param string $body method body
	 */
	#[DataProvider('unprovedPaths')]
	public function testUnknownPathsInMovedFilesFail(string $body): void {
		$problems = $this->compare($body, $body);
		$this->assertNotSame([], $problems);
		$this->assertStringContainsString('src/Deep/A/Probe.php', implode("\n", $problems));
		$this->assertStringContainsString('line', implode("\n", $problems));
		$this->assertStringContainsString('not proved', implode("\n", $problems));
	}

	/**
	 * @return iterable<string,array{string}> location-dependent method bodies
	 */
	public static function unprovedPaths(): iterable {
		foreach (['require', 'require_once', 'include', 'include_once'] as $keyword) {
			yield "$keyword variable" => ["\$p = 'dep.php'; $keyword \$p;"];
			yield "$keyword parenthesized" => ["\$p = 'dep.php'; $keyword(\$p);"];
			yield "$keyword relative expression" => ["$keyword ('dep' . '.php');"];
			yield "$keyword parentheses prefix" => ["\$p = 'dep.php'; $keyword ('/absolute/') . \$p;"];
			yield "$keyword misleading literal prefix" => ["\$p = 'dep.php'; $keyword '/absolute/' . \$p;"];
		}
		foreach (['realpath', 'glob', 'is_file', 'file_exists', 'file_get_contents', 'dirname'] as $name) {
			yield "$name variable" => ["\$p = 'dep.php'; $name(\$p);"];
		}
	}

	/** An absolute literal include remains a positive control; no placeholder stands in for unknown paths. */
	public function testAbsoluteLiteralIncludePasses(): void {
		$this->assertSame([], $this->compare("require '/srv/dep.php';", "require '/srv/dep.php';"));
	}

	/** The narrow builtin dirname depth compensation passes with the exact suffix unchanged. */
	public function testSimpleBuiltinDepthCompensationPasses(): void {
		$this->assertSame([], $this->compare("echo dirname(__DIR__, 2) . '/x';", "echo dirname(__DIR__, 3) . '/x';"));
		$this->assertSame([], $this->compare("echo \\dirname(__DIR__, 2) . '/x';", "echo \\dirname(__DIR__, 3) . '/x';"));
		$this->assertNotSame([], $this->compare("echo dirname(__DIR__, 2) . '/x';", "echo dirname(__DIR__, 3) . '/./x';"));
	}

	/** Function imports, local definitions and members are never folded as builtin dirname (R3-A8). */
	public function testRedirectedDirnameFails(): void {
		foreach (['use function A\\dirname;', 'use A\\{function dirname};'] as $import) {
			$before = "<?php namespace Caller; $import echo dirname(__DIR__, 1);";
			$after = str_replace('A\\', 'B\\', $before);
			$this->assertNotSame([], $this->compare('echo 1;', 'echo 1;', $before, $after));
		}
		foreach (['$this->dirname', 'self::dirname', '\\A\\dirname'] as $call) {
			$this->assertNotSame([], $this->compare("echo $call(__DIR__, 2);", "echo $call(__DIR__, 3);"));
		}
		$this->assertNotSame([], $this->compare('echo dirname(__DIR__, 2);', 'echo dirname(__DIR__, 3);', '<?php namespace Acme\\A; function dirname($p, $n) { return "A"; }'));
		$this->assertNotSame([], $this->compare('echo dirname(__DIR__, 2);', 'echo dirname(__DIR__, 3); use function A\\dirname;'));
	}

	/** Historical prose and .bak refer to no retired file; an actual path argument does (R3-A9). */
	public function testStalePathBoundariesAndConsumers(): void {
		$old = 'src/A/Probe.php';
		foreach (["\$description = 'The former path was $old; this is historical text.';", "\$description = '$old.bak';", "file_get_contents('$old.bak');", "file_get_contents('$old' . '.bak');", "\$description = '$old';"] as $body) {
			$this->assertSame([], $this->compare('echo 1;', 'echo 1;', "<?php $body"), $body);
		}
		foreach (["file_get_contents('$old');", "file_exists('/$old');", "require '$old';", "file_get_contents(dirname(__DIR__) . '/$old');"] as $body) {
			$problems = $this->compare('echo 1;', 'echo 1;', "<?php $body");
			$this->assertStringContainsString("still refers to the file $old", implode("\n", $problems), $body);
		}
	}

	/**
	 * Compares a mapped move and an optional unchanged-folder caller.
	 *
	 * @param string $bodyBefore old method body
	 * @param string $bodyAfter new method body
	 * @param string $callerBefore optional caller source
	 * @param string|null $callerAfter caller after, null keeps it unchanged
	 * @return list<string> comparator diagnostics
	 */
	private function compare(string $bodyBefore, string $bodyAfter, string $callerBefore = '<?php', ?string $callerAfter = null): array {
		$old = 'src/A/Probe.php';
		$new = 'src/Deep/A/Probe.php';
		$map = new FqcnMap([['path' => $old, 'fqcn' => 'Acme\\A\\Probe', 'target' => 'Acme\\Deep\\A\\Probe', 'owner' => 'x', 'ticket' => 'RE-X']], 'Acme\\', 'src/');
		$before = [$old => "<?php namespace Acme\\A; class Probe { function run() { $bodyBefore } }", 'Caller.php' => $callerBefore];
		$after = [$new => "<?php namespace Acme\\Deep\\A; class Probe { function run() { $bodyAfter } }", 'Caller.php' => $callerAfter ?? $callerBefore];
		return (new MoveEquivalence($before, $after, $map))->check();
	}
}
