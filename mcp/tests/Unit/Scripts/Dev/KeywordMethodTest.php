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
 * A method named after an include keyword (`PlanState::require()`) reads no file, so a moved class that declares or
 * calls one is not refused as a location-dependent include; a real include in the moved file still is.
 */
final class KeywordMethodTest extends TestCase {
	/**
	 * Declaring and calling methods named require/include passes the move.
	 *
	 * @return void
	 */
	public function testMethodsNamedAfterIncludeKeywordsPass(): void {
		$body = 'public static function require(array $a): void { $g = $a[0] ?? null; if ($g === null) { throw new \Exception(); } }'
			. ' public function include(): void { self::require([1]); $this->include(); $this?->include(); }';
		$this->assertSame([], $this->check($body));
	}

	/**
	 * A relative require inside the moved class is still refused.
	 *
	 * @return void
	 */
	public function testARealIncludeStillFails(): void {
		$problems = $this->check("public function load(): void { require 'helper.php'; }");
		$this->assertCount(1, $problems);
		$this->assertStringContainsString("relative require 'helper.php'", $problems[0]);
	}

	/**
	 * Compares a one-class move whose class holds `$body`.
	 *
	 * @param string $body class body
	 * @return list<string> diagnostics
	 */
	private function check(string $body): array {
		$map = new FqcnMap([['path' => 'lib/A/Probe.php', 'fqcn' => 'Acme\\A\\Probe', 'target' => 'Acme\\B\\Probe', 'owner' => 'x', 'ticket' => 'RE-X']], 'Acme\\', 'lib/');
		return (new MoveEquivalence(
			['lib/A/Probe.php' => "<?php\nnamespace Acme\\A;\nclass Probe {\n$body\n}\n"],
			['lib/B/Probe.php' => "<?php\nnamespace Acme\\B;\nclass Probe {\n$body\n}\n"],
			$map,
		))->check();
	}
}
