<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Scripts\Dev;

use OCA\Mcp\Scripts\Dev\FqcnMap;
use OCA\Mcp\Scripts\Dev\MoveEquivalence;
use OCA\Mcp\Scripts\Dev\PathIdentity;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../scripts/dev/bootstrap.php';

/**
 * A destination nested under a new folder ends with the old lib-relative path (`lib/Tools/Probe.php` moved to
 * `lib/Mcp/Tools/Probe.php` ends with `/Tools/Probe.php`). The updated reader names the destination and passes; the
 * old path, a coincidental prefix and an embedded repository path still fail.
 */
final class NestedDestinationTest extends TestCase {
	/** Old path of the moved file. */
	private const OLD = 'lib/Tools/Probe.php';
	/** Nested destination of the moved file. */
	private const NEW = 'lib/Mcp/Tools/Probe.php';

	/**
	 * The proved identities of the destination name it; the old path and unproved prefixes do not.
	 *
	 * @return void
	 */
	public function testOnlyProvedDestinationIdentitiesNameTheDestination(): void {
		foreach ([self::NEW, '/' . self::NEW, 'mcp/' . self::NEW, 'Mcp/Tools/Probe.php', self::NEW . '#Probe'] as $value) {
			$this->assertTrue(PathIdentity::namesDestination($value, self::OLD, self::NEW), $value);
		}
		foreach ([self::OLD, 'mcp/' . self::OLD, 'fixtures/' . self::NEW, 'lib/Other/Tools/Probe.php'] as $value) {
			$this->assertFalse(PathIdentity::namesDestination($value, self::OLD, self::NEW), $value);
		}
		$this->assertFalse(PathIdentity::namesDestination(self::OLD, self::OLD, self::OLD));
	}

	/**
	 * A manifest and a PHP reader updated to the nested destination pass.
	 *
	 * @return void
	 */
	public function testUpdatedReadersOfANestedDestinationPass(): void {
		$this->assertSame([], $this->check(self::NEW));
	}

	/**
	 * A forgotten reader and readers with an unproved prefix fail in both the manifest and the PHP reader.
	 *
	 * @return void
	 */
	public function testForgottenAndUnprovedReadersStillFail(): void {
		foreach ([self::OLD, 'fixtures/' . self::NEW, 'lib/Other/Tools/Probe.php'] as $path) {
			$problems = $this->check($path);
			$this->assertNotSame([], $problems, $path);
			$this->assertNotSame([], preg_grep('~^tests/manifest\.tsv:1: ~', $problems), $path);
			$this->assertNotSame([], preg_grep('~^tests/Reader\.php:2: ~', $problems), $path);
		}
		$this->assertNotSame([], $this->check('other:mcp/' . self::NEW, 'other:mcp/' . self::OLD));
	}

	/**
	 * Compares the move with a manifest and a PHP reader that name `$path` after it.
	 *
	 * @param string $path path the readers name after the move, relative to the app unless prefixed
	 * @param string|null $before path the readers named before the move; the old path when null
	 * @return list<string> diagnostics
	 */
	private function check(string $path, ?string $before = null): array {
		$map = new FqcnMap([['path' => self::OLD, 'fqcn' => 'Acme\\Tools\\Probe', 'target' => 'Acme\\Mcp\\Tools\\Probe', 'owner' => 'x', 'ticket' => 'RE-X']], 'Acme\\', 'lib/');
		$reader = static fn (string $name): string => "<?php\n\$path = '$name';\n";
		$manifest = static fn (string $name): string => (str_contains($name, ':') ? $name : 'mcp/' . $name) . "\t10\tabc123\n";
		$old = $before ?? self::OLD;
		return (new MoveEquivalence(
			[self::OLD => '<?php namespace Acme\\Tools; class Probe {}', 'tests/manifest.tsv' => $manifest($old), 'tests/Reader.php' => $reader($old)],
			[self::NEW => '<?php namespace Acme\\Mcp\\Tools; class Probe {}', 'tests/manifest.tsv' => $manifest($path), 'tests/Reader.php' => $reader($path)],
			$map,
		))->check();
	}
}
