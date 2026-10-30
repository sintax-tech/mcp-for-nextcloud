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

/** R5 path aliases/suffixes, prefix refusal and exception location regressions. */
final class ReviewR5Test extends TestCase {
	/**
	 * App/lib-relative path values and inventory keys preserve their complete suffixes.
	 *
	 * @param string $literal caller literal
	 */
	#[DataProvider('paths')]
	public function testAliasesAndSuffixes(string $literal): void {
		$updated = str_replace('A/Probe', 'B/Probe', $literal);
		$this->assertSame([], $this->compare("\$key = '$literal';", "\$key = '$updated';"));
		$this->assertNotSame([], $this->compare("\$key = '$literal';", "\$key = '$literal';"));
		$this->assertSame([], $this->compare('', '', "const key = '$literal';", "const key = '$updated';"));
		$this->assertNotSame([], $this->compare('', '', "const key = '$literal';", "const key = '$literal';"));
		if (str_contains($literal, '#') || str_contains($literal, ':')) {
			$this->assertNotSame([], $this->compare("\$key = '$literal';", "\$key = '$updated-extra';"));
			$this->assertNotSame([], $this->compare('', '', "const key = '$literal';", "const key = '$updated-extra';"));
		}
	}

	/**
	 * @return iterable<string,array{string}> supported forms; suffixes are never discarded
	 */
	public static function paths(): iterable {
		foreach (['lib/A/Probe.php', '/lib/A/Probe.php', 'A/Probe.php', '/A/Probe.php'] as $base) {
			foreach (['', '#method:operation:1', ':42'] as $suffix) {
				yield $base . $suffix => [$base . $suffix];
			}
		}
	}

	/** Prefix collisions name other files and can never be silently remapped. */
	public function testCoincidingPrefixesFail(): void {
		foreach (['fixtures/lib/', '/../../fixtures/lib/', 'fixtures/', '//lib/', '../'] as $prefix) {
			$old = $prefix . 'A/Probe.php';
			$new = $prefix . 'B/Probe.php';
			$this->assertStringContainsString('path not proved', implode("\n", $this->compare("file_get_contents('$old');", "file_get_contents('$new');")));
			$this->assertStringContainsString('path not proved', implode("\n", $this->compare('', '', "// $old", "// $new")));
		}
	}

	/** Exception getFile/getFileName calls reveal the executing file just like reflection. */
	public function testExceptionFileIsRefused(): void {
		foreach (['(new \\Exception())->getFile();', '$error?->getFile();'] as $body) {
			$this->assertStringContainsString('not proved', implode("\n", $this->compare('', '', '', '', $body)));
		}
	}

	/** A method named file is not PHP's include-path function (the real FilesModule uses this method). */
	public function testMemberNamedFilePasses(): void {
		$this->assertSame([], $this->compare('', '', '', '', '$this->file("x", "r", true);'));
	}

	/**
	 * Creates a mapped move and independent PHP/non-PHP callers.
	 *
	 * @param string $oldPhp old PHP caller
	 * @param string $newPhp new PHP caller
	 * @param string $oldJs old JS caller
	 * @param string $newJs new JS caller
	 * @param string $body identical moved method body
	 * @return list<string> diagnostics
	 */
	private function compare(string $oldPhp, string $newPhp, string $oldJs = '', string $newJs = '', string $body = ''): array {
		$map = new FqcnMap([['path' => 'lib/A/Probe.php', 'fqcn' => 'Acme\\A\\Probe', 'target' => 'Acme\\B\\Probe', 'owner' => 'x', 'ticket' => 'RE-X']], 'Acme\\', 'lib/');
		$before = ['lib/A/Probe.php' => "<?php namespace Acme\\A; class Probe { function run() { $body } }", 'tests/Caller.php' => "<?php $oldPhp", 'tests/caller.cjs' => $oldJs];
		$after = ['lib/B/Probe.php' => "<?php namespace Acme\\B; class Probe { function run() { $body } }", 'tests/Caller.php' => "<?php $newPhp", 'tests/caller.cjs' => $newJs];
		return (new MoveEquivalence($before, $after, $map))->check();
	}
}
