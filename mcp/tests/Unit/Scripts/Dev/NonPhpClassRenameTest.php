<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Scripts\Dev;

use OCA\Mcp\Scripts\Dev\ClassRewrites;
use OCA\Mcp\Scripts\Dev\FqcnMap;
use OCA\Mcp\Scripts\Dev\MoveEquivalence;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../scripts/dev/bootstrap.php';

/**
 * A non-PHP test file that names a moved class (a golden of DI keys, an effect contract recording the class of a
 * refusal) follows the move by the class pair alone, plain or JSON-escaped; any other edit still fails.
 */
final class NonPhpClassRenameTest extends TestCase {
	/** Golden before the move: the class plain, JSON-escaped, with a leading separator and inside a message. */
	private const GOLDEN = "{\"di\": [\"Acme\\\\A\\\\Probe\", \"\\\\Acme\\\\A\\\\Probe\"], \"cause\": \"Acme\\\\A\\\\Probe: refused\"}\nAcme\\A\\Probe\n";

	/**
	 * Only whole names are rewritten, in both spellings.
	 *
	 * @return void
	 */
	public function testOnlyWholeNamesAreRewritten(): void {
		$renames = ['Acme\\A\\Probe' => 'Acme\\B\\Probe'];
		$this->assertSame(str_replace('\\A\\', '\\B\\', self::GOLDEN), ClassRewrites::text(self::GOLDEN, $renames));
		foreach (['Acme\\A\\ProbeTest', 'XAcme\\A\\Probe', 'Other\\Acme\\A\\Probe', 'Acme\\A\\Probe\\Inner', 'Acme\\\\A\\\\Probe\\\\Inner'] as $text) {
			$this->assertSame($text, ClassRewrites::text($text, $renames), $text);
		}
	}

	/**
	 * A golden updated by the pair passes the comparator.
	 *
	 * @return void
	 */
	public function testAGoldenUpdatedByThePairPasses(): void {
		$this->assertSame([], $this->check(str_replace('\\A\\', '\\B\\', self::GOLDEN)));
	}

	/**
	 * A wrong destination, a half-updated golden and an extra edit fail.
	 *
	 * @return void
	 */
	public function testOtherEditsFail(): void {
		$updated = str_replace('\\A\\', '\\B\\', self::GOLDEN);
		foreach ([str_replace('\\A\\', '\\C\\', self::GOLDEN), preg_replace('~\\\\A\\\\~', '\\B\\', self::GOLDEN, 1), str_replace('refused', 'denied', $updated)] as $golden) {
			$this->assertContains('tests/golden.json: non-PHP file changed beyond paired test paths; only actual file-pair substitutions are allowed', $this->check($golden));
		}
		$this->assertCount(3, preg_grep('~still refers to Acme\\\\A\\\\Probe~', $this->check(preg_replace('~\\\\A\\\\~', '\\B\\', self::GOLDEN, 1))));
	}

	/**
	 * A golden left entirely as it was still names the retired class: every occurrence fails, plain and JSON-escaped,
	 * with its line, although the file did not change.
	 *
	 * @return void
	 */
	public function testAGoldenForgottenEntirelyFails(): void {
		$stale = static fn (int $line): string => "tests/golden.json:$line: still refers to Acme\\A\\Probe, moved to Acme\\B\\Probe in this range";
		$this->assertSame([$stale(2), $stale(1), $stale(1), $stale(1)], $this->check(self::GOLDEN));
	}

	/**
	 * Longer names, sibling namespaces and the renamed test classes a baseline keeps on purpose are not stale.
	 *
	 * @return void
	 */
	public function testOnlyRetiredClassesOfTheAppAreStale(): void {
		$updated = str_replace('\\A\\', '\\B\\', self::GOLDEN);
		$others = ['tests/other.txt' => "Acme\\A\\ProbeTest XAcme\\A\\Probe Other\\Acme\\A\\Probe Acme\\A\\Probe\\Inner\n",
			'tests/baseline.txt' => "Acme\\Tests\\A\\ProbeTest::testIt\n"];
		$this->assertSame([], $this->check($updated, $others));
	}

	/**
	 * Compares a one-class move with a golden that names the class.
	 *
	 * @param string $golden golden after the move
	 * @param array<string,string> $unchanged other files, the same on both sides
	 * @return list<string> diagnostics
	 */
	private function check(string $golden, array $unchanged = []): array {
		$map = new FqcnMap([['path' => 'lib/A/Probe.php', 'fqcn' => 'Acme\\A\\Probe', 'target' => 'Acme\\B\\Probe', 'owner' => 'x', 'ticket' => 'RE-X']], 'Acme\\', 'lib/');
		$test = static fn (string $folder): string => "<?php namespace Acme\\Tests\\$folder; class ProbeTest {}";
		return (new MoveEquivalence(
			['lib/A/Probe.php' => '<?php namespace Acme\\A; class Probe {}', 'tests/A/ProbeTest.php' => $test('A'), 'tests/golden.json' => self::GOLDEN] + $unchanged,
			['lib/B/Probe.php' => '<?php namespace Acme\\B; class Probe {}', 'tests/B/ProbeTest.php' => $test('B'), 'tests/golden.json' => $golden] + $unchanged,
			$map,
		))->check();
	}
}
