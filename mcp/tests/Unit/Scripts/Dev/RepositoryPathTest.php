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
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../scripts/dev/bootstrap.php';

/** Repository-relative paths have the same strict pair-only proof as app-relative paths. */
final class RepositoryPathTest extends TestCase {
	/** Updates only paths; a forgotten manifest or an unrelated metadata/order edit must fail. */
	public function testRepositoryPathsPreserveEveryOtherManifestByte(): void {
		$old = "mcp/lib/A/Probe.php\t10\tabc123\nother.php\t20\tdef456\n";
		$new = str_replace('/A/', '/B/', $old);
		$this->assertSame([], $this->check($old, $new));
		foreach ([$old, str_replace("\t10", "\t11", $new), str_replace('abc123', 'aaa123', $new), implode("\n", array_reverse(explode("\n", trim($new)))) . "\n"] as $changed) {
			$this->assertNotSame([], $this->check($old, $changed));
		}
		$this->assertSame(" mcp/lib/B/Probe.php:42", PathRewrites::text(" mcp/lib/A/Probe.php:42", ['lib/A/Probe.php' => 'lib/B/Probe.php']));
	}

	/** Coincidental fixture prefixes and lib-relative aliases cannot acquire a repository-root proof. */
	public function testOtherPrefixesAndEmbeddedTextStayUnproved(): void {
		foreach (['fixtures/mcp/lib/', 'mcp/', 'other:mcp/lib/', 'other!mcp/lib/'] as $prefix) {
			$old = $prefix . 'A/Probe.php';
			$this->assertSame($old, PathRewrites::text($old, ['lib/A/Probe.php' => 'lib/B/Probe.php']));
		}
		$this->assertNotSame([], $this->check('fixtures/mcp/lib/A/Probe.php', 'fixtures/mcp/lib/B/Probe.php'));
		$this->assertNotSame([], $this->check('file:///lib/A/Probe.php', 'file:///lib/A/Probe.php'));
		$this->assertNotSame([], $this->check('other:mcp/lib/A/Probe.php', 'other:mcp/lib/A/Probe.php'));
	}

	/**
	 * Compares a tiny move with an unchanged-format TSV reader.
	 *
	 * @param string $old old manifest
	 * @param string $new updated manifest
	 * @return list<string> diagnostics
	 */
	private function check(string $old, string $new): array {
		$map = new FqcnMap([['path' => 'lib/A/Probe.php', 'fqcn' => 'Acme\\A\\Probe', 'target' => 'Acme\\B\\Probe', 'owner' => 'x', 'ticket' => 'RE-X']], 'Acme\\', 'lib/');
		$before = ['lib/A/Probe.php' => '<?php namespace Acme\\A; class Probe {}', 'tests/manifest.tsv' => $old];
		$after = ['lib/B/Probe.php' => '<?php namespace Acme\\B; class Probe {}', 'tests/manifest.tsv' => $new];
		return (new MoveEquivalence($before, $after, $map))->check();
	}
}
