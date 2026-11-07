<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Compat;

use OCA\Mcp\Tests\Integration\Compat\LegacyPolicyCommand;
use PHPUnit\Framework\Assert;
use RuntimeException;

/** Exercises the CLI boundary in child PHP processes, independently of PHPUnit's own exception handler. */
final class LegacyPolicyCommandAssertions {
	/** @return void Historical reads never invoke the installed-release probe; script exceptions cannot return success. */
	public static function isolation(): void {
		$file = (new \ReflectionClass(LegacyPolicyCommand::class))->getFileName();
		$boot = 'require ' . var_export($file, true) . '; '
			. 'set_exception_handler(static function (Throwable $e): void { exit(0); }); '
			. '\\OCA\\Mcp\\Tests\\Integration\\Compat\\LegacyPolicyCommand::prepare("read", "/unused-snapshot.json"); ';
		[$status, $stdout, $stderr] = self::process($boot
			. 'echo json_encode(["candidateLoaded" => class_exists("OCA\\\\Mcp\\\\Tests\\\\Integration\\\\Compat\\\\ReleaseUpgradeProbe", false), '
			. '"serverLoaded" => class_exists("OCP\\\\Server", false)], JSON_THROW_ON_ERROR);');
		Assert::assertSame(0, $status, $stderr);
		Assert::assertSame('', $stderr);
		Assert::assertSame(['candidateLoaded' => false, 'serverLoaded' => false], json_decode($stdout, true, 512, JSON_THROW_ON_ERROR));
		[$status, $stdout, $stderr] = self::process($boot . 'throw new RuntimeException("Synthetic baseline read failed");');
		Assert::assertSame(1, $status, 'A core-style success handler must not mask a failed baseline read');
		Assert::assertSame('', $stdout);
		Assert::assertStringContainsString('RuntimeException: Synthetic baseline read failed', $stderr);
		[$status, $stdout, $stderr] = self::process('require ' . var_export($file, true) . '; '
			. '\\OCA\\Mcp\\Tests\\Integration\\Compat\\LegacyPolicyCommand::prepare("unknown", "/unused-snapshot.json");');
		Assert::assertSame(1, $status);
		Assert::assertSame('', $stdout);
		Assert::assertStringContainsString('InvalidArgumentException: Unknown legacy policy command mode', $stderr);
		[$status, $stdout, $stderr] = self::process('namespace OCP { final class Server { '
			. 'public static function get(string $class): never { throw new \RuntimeException("Seed reached installed baseline"); } } } '
			. 'namespace { require ' . var_export($file, true) . '; '
			. '\\OCA\\Mcp\\Tests\\Integration\\Compat\\LegacyPolicyCommand::prepare("seed", "/unused-snapshot.json"); }');
		Assert::assertSame(1, $status);
		Assert::assertSame('', $stdout);
		Assert::assertStringContainsString('RuntimeException: Seed reached installed baseline', $stderr);
		$destination = tempnam(sys_get_temp_dir(), 'mcp-it-snapshot-write-');
		if ($destination === false) {
			throw new RuntimeException('Cannot allocate the snapshot write proof');
		}
		try {
			$snapshot = ['source' => 'historical-baseline', 'raw' => []];
			LegacyPolicyCommand::write($destination, $snapshot);
			Assert::assertSame($snapshot, json_decode((string)file_get_contents($destination), true, 512, JSON_THROW_ON_ERROR));
			// A regular file cannot be a parent directory: this deterministically forces file_put_contents to fail.
			[$status, , $stderr] = self::process($boot
				. '\\OCA\\Mcp\\Tests\\Integration\\Compat\\LegacyPolicyCommand::write('
				. var_export($destination . '/snapshot.json', true) . ', []);');
			Assert::assertSame(1, $status, 'A failed checkpoint write must fail the CLI process');
			Assert::assertStringContainsString('RuntimeException: Cannot write the legacy policy snapshot', $stderr);
		} finally {
			unlink($destination);
		}
	}

	/**
	 * @param string $program PHP source without opening tag; no server or container is started.
	 * @return array{int,string,string} Exit code, stdout and stderr from the isolated interpreter.
	 * @throws RuntimeException when the local PHP subprocess cannot start
	 */
	private static function process(string $program): array {
		$file = tempnam(sys_get_temp_dir(), 'mcp-it-legacy-command-');
		if ($file === false) {
			throw new RuntimeException('Cannot allocate the legacy command proof');
		}
		try {
			if (file_put_contents($file, "<?php\n" . $program) === false) {
				throw new RuntimeException('Cannot write the legacy command proof');
			}
			$process = proc_open([PHP_BINARY, $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
			if (!is_resource($process)) {
				throw new RuntimeException('Cannot start the legacy command proof');
			}
			$stdout = stream_get_contents($pipes[1]);
			$stderr = stream_get_contents($pipes[2]);
			fclose($pipes[1]);
			fclose($pipes[2]);
			return [proc_close($process), $stdout, $stderr];
		} finally {
			unlink($file);
		}
	}
}
