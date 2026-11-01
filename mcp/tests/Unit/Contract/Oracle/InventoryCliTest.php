<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\Oracle;

use PHPUnit\Framework\TestCase;

/** Exercises the real inventory CLI with synthetic reports of the versioned baseline. */
final class InventoryCliTest extends TestCase {
	/** Omitting the map uses the committed cumulative pairs for the current tree. */
	public function testCurrentNamesPassWithoutAnExplicitMap(): void {
		$map = InventoryRenames::load(__DIR__ . '/../baseline/test-renames.tsv');
		$this->assertArrayHasKey('OCA\\Mcp\\Tests\\Unit\\Service\\PromptCatalogTest', $map);
		$this->assertArrayHasKey('OCA\\Mcp\\Tests\\Unit\\UserTimezoneTest', $map);
		[$exit, $output] = $this->runCli($map);
		$this->assertSame(0, $exit, $output);
	}

	/** An explicit empty map still checks reports with the original baseline identities. */
	public function testExplicitMapOverridesTheDefault(): void {
		[$exit, $output] = $this->runCli([], '');
		$this->assertSame(0, $exit, $output);
		[$exit, $output] = $this->runCli([]);
		$this->assertSame(1, $exit, $output);
		$this->assertStringContainsString('missing from the run:', $output);
	}

	/** A malformed override fails closed rather than silently dropping its renames. */
	public function testMalformedMapFails(): void {
		[$exit, $output] = $this->runCli([], "Old\\Class\t\n");
		$this->assertSame(1, $exit, $output);
		$this->assertStringContainsString('Invalid test rename pair', $output);
	}

	/**
	 * Builds a synthetic complete baseline report, invokes the CLI in a subprocess and removes its files.
	 *
	 * @param array<string,string> $names class renames to apply to report identities
	 * @param string|null $override explicit map bytes, or null to omit the argument
	 * @return array{int,string} process exit status and combined output
	 */
	private function runCli(array $names, ?string $override = null): array {
		$baseline = __DIR__ . '/../baseline';
		$root = dirname(__DIR__, 4);
		$dir = $root . '/build/inventory-cli-' . bin2hex(random_bytes(6));
		mkdir($dir, 0700, true);
		try {
			$failureRows = file($baseline . '/environment-failures.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
			$failures = array_fill_keys(array_map(static fn (string $row): string => explode("\t", $row)[0], $failureRows), true);
			$xml = new \SimpleXMLElement('<testsuites><testsuite/></testsuites>');
			foreach (file($baseline . '/tests-inventory.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $id) {
				[$class, $method] = explode('::', $id, 2);
				$case = $xml->testsuite->addChild('testcase');
				$case->addAttribute('class', $names[$class] ?? $class);
				$case->addAttribute('name', $method);
				if (isset($failures[$id])) {
					$case->addChild('failure');
				}
			}
			$xml->asXML($dir . '/report.xml');
			$command = [PHP_BINARY, $baseline . '/check-inventory.php', $dir . '/report.xml'];
			if ($override !== null) {
				file_put_contents($dir . '/override.tsv', $override);
				$command[] = $dir . '/override.tsv';
			}
			$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $dir . '/output.log', 'w'], 2 => ['redirect', 1]], $pipes);
			if (!is_resource($process)) {
				throw new \RuntimeException('Cannot start inventory CLI');
			}
			fclose($pipes[0]);
			$exit = proc_close($process);
			return [$exit, (string)file_get_contents($dir . '/output.log')];
		} finally {
			foreach (glob($dir . '/*') as $file) {
				unlink($file);
			}
			rmdir($dir);
		}
	}
}
