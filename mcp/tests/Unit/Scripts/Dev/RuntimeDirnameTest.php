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

/** Keeps the RE-04 runtime dirname proof narrow without weakening the path safety controls. */
final class RuntimeDirnameTest extends TestCase {
	/** A node path is a runtime value; moving the PHP file changes neither its operand nor dirname. */
	public function testRuntimeNodePathPasses(): void {
		foreach (['dirname', '\\dirname'] as $call) {
			$this->assertSame([], $this->check("return $call((string)\$node->getPath());"));
			$this->assertSame([], $this->check("return $call(\$node->getPath());"));
		}
	}

	/** Unknown variables, source-dependent operands and callable overrides remain refused. */
	public function testUnprovedOperandsStillFail(): void {
		foreach (['dirname($this->getPath())', 'dirname((string)$this->getPath())', 'dirname((string)$node?->getPath())',
			'dirname((string)$node->getName())', 'dirname((string)$node->getPath(), 2)', 'dirname((string)$node->getPath() . __DIR__)', 'dirname($path)', 'dirname($node->getPath(__DIR__))', 'dirname($node->getPath() . __DIR__)',
			'dirname($node?->getPath())', 'dirname(__DIR__ . "/child")'] as $expression) {
			$this->assertNotSame([], $this->check("return $expression;"), $expression);
		}
		$this->assertNotSame([], $this->check('return dirname($node->getPath());', 'use function Other\\dirname;'));
		$this->assertNotSame([], $this->check('return dirname((string)$node->getPath());', 'use function Other\\dirname;'));
	}

	/** Token comparison still rejects a changed runtime operand. */
	public function testChangedOperandFails(): void {
		$this->assertNotSame([], $this->check('return dirname($node->getPath());', '', 'return dirname($other->getPath());'));
		$this->assertNotSame([], $this->check('return dirname((string)$node->getPath());', '', 'return dirname((string)$other->getPath());'));
	}

	/**
	 * Runs the real comparator for a class moving one directory deeper.
	 *
	 * @param string $body original method body
	 * @param string $imports optional function alias in both namespaces
	 * @param string|null $after changed body, or the original body
	 * @return list<string> comparator diagnostics
	 */
	private function check(string $body, string $imports = '', ?string $after = null): array {
		$map = new FqcnMap([['path' => 'lib/A/Probe.php', 'fqcn' => 'Acme\\A\\Probe', 'target' => 'Acme\\Deep\\A\\Probe', 'owner' => 'x', 'ticket' => 'RE-X']], 'Acme\\', 'lib/');
		return (new MoveEquivalence(
			['lib/A/Probe.php' => "<?php namespace Acme\\A; $imports class Probe { function run(\$node, \$other, \$path) { $body } }"],
			['lib/Deep/A/Probe.php' => "<?php namespace Acme\\Deep\\A; $imports class Probe { function run(\$node, \$other, \$path) { " . ($after ?? $body) . ' } }'],
			$map,
		))->check();
	}
}
