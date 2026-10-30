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

/** Regressions for path-pair rewrites, implicit location consumers and composed class references (R4). */
final class ReviewR4Test extends TestCase {
	/** Literal and concatenated path readers may be updated only to the actual paired path (A11). */
	public function testMappedPathReadersPass(): void {
		foreach (["file_get_contents('/src/A/Probe.php');", "file_get_contents('/src/' . 'A/Probe.php');"] as $reader) {
			$this->assertSame([], $this->compare($reader, str_replace('A/Probe', 'B/Probe', $reader)));
		}
		$js = "fs.readFileSync('src/A/Probe.php'); assert.ok(true, 'read from src/A/Probe.php');\n";
		$this->assertSame([], $this->compare('', '', $js, str_replace('src/A/', 'src/B/', $js)));
	}

	/** Forgotten PHP and non-PHP readers remain stale even when no caller content changed (A11). */
	public function testForgottenReadersFail(): void {
		foreach ([["file_get_contents('src/A/Probe.php');", ''], ['', "fs.readFileSync('src/A/Probe.php');"]] as [$php, $js]) {
			$problems = $this->compare($php, $php, $js, $js);
			$this->assertStringContainsString('still refers to the file src/A/Probe.php', implode("\n", $problems));
		}
	}

	/** Backups, child paths and unrelated edits must never be hidden by the path rename (A11). */
	public function testOnlyWholePairedPathChangesPass(): void {
		foreach (['.bak', '/child', ''] as $suffix) {
			$php = "file_get_contents('src/A/Probe.php$suffix');";
			$js = "fs.readFileSync('src/A/Probe.php$suffix');";
			$extra = $suffix === '' ? " echo 'extra';" : '';
			$this->assertNotSame([], $this->compare($php, str_replace('src/A/', 'src/B/', $php) . $extra));
			$this->assertNotSame([], $this->compare('', '', $js, str_replace('src/A/', 'src/B/', $js) . $extra));
		}
		$this->assertNotSame([], $this->compare("file_get_contents('src/A/Probe.php');", "file_get_contents('src/C/Probe.php');"));
	}

	/**
	 * Include-path, stack and reflection location uses are refused in a file changing folder (A12).
	 *
	 * @param string $body method body
	 */
	#[DataProvider('locationCalls')]
	public function testImplicitLocationCallsFail(string $body): void {
		$problems = $this->compare('', '', '', '', $body);
		$this->assertStringContainsString('not proved', implode("\n", $problems), $body);
		$this->assertStringContainsString('line', implode("\n", $problems));
	}

	/**
	 * @return iterable<string,array{string}> direct, aliased, named-argument and nested-argument cases
	 */
	public static function locationCalls(): iterable {
		foreach (['stream_resolve_include_path', 'set_include_path', 'get_included_files', 'debug_backtrace', 'debug_print_backtrace'] as $name) {
			yield $name => ["$name('dep.php');"];
			yield "explicit $name" => ["\\$name('dep.php');"];
		}
		foreach (['fopen', 'gzopen'] as $name) {
			yield $name => ["$name('dep.php', 'r', true);"];
		}
		foreach (['file', 'readfile', 'gzfile', 'readgzfile'] as $name) {
			yield $name => ["$name('dep.php', true);"];
		}
		yield 'SplFileObject' => ["new \\SplFileObject('dep.php', 'r', true);"];
		yield 'ReflectionClass filename' => ["(new \\ReflectionClass(self::class))->getFileName();"];
		yield 'nullsafe filename' => ['$reflection?->getFileName();'];
		yield 'named include_path' => ["fopen(use_include_path: true, filename: 'dep.php', mode: 'r');"];
		yield 'SplFileObject named include path' => ["new \\SplFileObject(filename: 'dep.php', useIncludePath: true);"];
		yield 'unpack' => ['fopen(...$args);'];
		yield 'nested arguments' => ["fopen(make(['a', 'b']), 'r', false);"];
	}

	/** Unchanged two-argument fopen calls have no include-path argument and remain a positive control. */
	public function testOrdinaryOpenWithoutIncludePathPasses(): void {
		$this->assertSame([], $this->compare('', '', '', '', "fopen('/absolute/path', 'r');"));
	}

	/** Callable strings resolve their class and retain the member; a forgotten callable is stale (A13). */
	public function testCallableStringsFollowClassPairs(): void {
		foreach (["'Acme\\\\A\\\\Probe::run'", "'\\\\Acme\\\\A\\\\Probe::run'", '"Acme\\\\A\\\\Probe::run"'] as $literal) {
			$old = "is_callable($literal);";
			$new = str_replace('A\\\\Probe', 'B\\\\Probe', $old);
			$this->assertSame([], $this->compare($old, $new), $literal);
			$this->assertStringContainsString('still refers to Acme\\A\\Probe', implode("\n", $this->compare($old, $old)));
			$this->assertNotSame([], $this->compare($old, str_replace('::run', '::other', $new)));
		}
	}

	/** __NAMESPACE__ is unsafe in unchanged siblings of a class retired in this range (A13). */
	public function testNamespaceCompositionInUnchangedSiblingFails(): void {
		$body = "namespace Acme\\A; class_exists(__NAMESPACE__ . '\\\\Probe');";
		$this->assertStringContainsString('composed class reference not proved', implode("\n", $this->compare($body, $body)));
		$this->assertSame([], $this->compare(str_replace('Acme\\A', 'Acme\\Elsewhere', $body), str_replace('Acme\\A', 'Acme\\Elsewhere', $body)));
	}

	/**
	 * Compares a mapped move plus independent PHP and JS callers.
	 *
	 * @param string $oldPhp old PHP caller body
	 * @param string $newPhp new PHP caller body
	 * @param string $oldJs old non-PHP caller
	 * @param string|null $newJs new caller, null keeps it unchanged
	 * @param string $body identical moved-file method body
	 * @return list<string> diagnostics
	 */
	private function compare(string $oldPhp, string $newPhp, string $oldJs = '', ?string $newJs = null, string $body = ''): array {
		$map = new FqcnMap([['path' => 'src/A/Probe.php', 'fqcn' => 'Acme\\A\\Probe', 'target' => 'Acme\\B\\Probe', 'owner' => 'x', 'ticket' => 'RE-X']], 'Acme\\', 'src/');
		$before = ['src/A/Probe.php' => "<?php namespace Acme\\A; class Probe { function run() { $body } }", 'Caller.php' => "<?php $oldPhp", 'tests/probe.cjs' => $oldJs];
		$after = ['src/B/Probe.php' => "<?php namespace Acme\\B; class Probe { function run() { $body } }", 'Caller.php' => "<?php $newPhp", 'tests/probe.cjs' => $newJs ?? $oldJs];
		return (new MoveEquivalence($before, $after, $map))->check();
	}
}
