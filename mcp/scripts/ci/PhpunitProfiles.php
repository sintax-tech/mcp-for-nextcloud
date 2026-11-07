<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Scripts\Ci;

/** Explicit startup profiles for the same complete PHPUnit inventory. */
final class PhpunitProfiles {
	/** @var list<string> Frozen flows whose dates were recorded without intl. */
	private const ORACLE = [
		'CalendarEffectsContractTest::testCreationOfAnEvent',
		'CalendarWritesEffectsContractTest::testMovingAnEvent',
		'CalendarWritesEffectsContractTest::testDeletingAnEvent',
		'CalendarWritesEffectsContractTest::testTransferringAnEvent',
		'TasksEffectsContractTest::testCreationOfATask',
		'TasksWritesEffectsContractTest::testCompletionOfATask',
	];
	/** @var list<string> Recordings whose request-body limit is part of their frozen output. */
	private const RECORDINGS = [
		'AdminSettingsEffectsContractTest::testCheckoutLimit',
		'FilesUploadRestoreEffectsContractTest::testUploadAndTheCreateRoute',
	];

	/** @return array<string,string> Complementary PHPUnit filters, evaluated again against actual JUnit output. */
	public static function filters(): array {
		$prefix = 'OCA\\Mcp\\Tests\\Unit\\Contract\\EffectContracts\\';
		$methods = static fn (array $names): string => '(?:' . implode('|', array_map(static fn (string $name): string => preg_quote($prefix . $name, '~'), $names)) . ')(?:$| with data set )';
		$oracle = $methods(self::ORACLE);
		$recordings = $methods(self::RECORDINGS);
		$dev = preg_quote('OCA\\Mcp\\Tests\\Unit\\Scripts\\Dev\\', '~');
		return ['oracle' => '~^' . $oracle . '~', 'recordings' => '~^' . $recordings . '~',
			'dev' => '~^' . $dev . '~', 'supported' => '~^(?!(?:' . $oracle . '|' . $recordings . '|' . $dev . '))~'];
	}

	/**
	 * Preserves the repository configuration while making relative paths absolute and bounding each process at 2 GiB.
	 * @param string $app App directory.
	 * @param string $work Disposable report directory.
	 * @return void
	 */
	public static function configure(string $app, string $work): void {
		$xml = simplexml_load_file($app . '/phpunit.xml');
		if ($xml === false) { throw new \RuntimeException('Cannot read PHPUnit configuration'); }
		$xml['bootstrap'] = $app . '/' . $xml['bootstrap'];
		$xml['cacheDirectory'] = $work . '/cache';
		foreach ($xml->xpath('//directory|//exclude|//file') as $path) { $path[0] = $app . '/' . (string)$path; }
		foreach ($xml->xpath('//php/ini[@name="memory_limit"]') as $ini) { $ini['value'] = '2G'; }
		$xml->asXML($work . '/phpunit.xml');
		mkdir($work . '/without-intl');
		foreach (array_filter(array_map('trim', explode(',', php_ini_scanned_files() ?: ''))) as $i => $path) {
			$ini = (string)file_get_contents($path);
			$ini = (string)preg_replace('~^\h*extension\h*=\h*["\x27]?(?:[^\s"\x27]*/)?(?:php_)?intl(?:\.so|\.dll)?["\x27]?\h*(?:;[^\n]*)?$~mi', '; intl disabled only for the frozen oracle', $ini);
			file_put_contents(sprintf('%s/without-intl/%03d.ini', $work, $i), $ini);
		}
	}

	/**
	 * Runs one isolated profile; the canonical wrapper also checks that intl was actually disabled.
	 * @param string $app App directory.
	 * @param string $work Disposable report directory.
	 * @param string $profile Explicit profile name.
	 * @return int Exact PHPUnit or wrapper status.
	 */
	public static function run(string $app, string $work, string $profile): int {
		$filter = self::filters()[$profile] ?? throw new \RuntimeException('Unknown PHPUnit profile');
		$environment = getenv();
		if ($profile === 'oracle') {
			$environment['PHP_INI_SCAN_DIR'] = $work . '/without-intl';
			$command = [PHP_BINARY, $app . '/tests/Unit/Contract/Oracle/OracleUnitRunner.php'];
		} else {
			foreach (['intl', 'gd', 'zip', 'mbstring', 'pdo_sqlite'] as $extension) {
				if (!extension_loaded($extension)) { throw new \RuntimeException('Missing extension: ' . $extension); }
			}
			$limit = $profile === 'recordings' ? '8M' : '50M';
			$command = [PHP_BINARY, '-d', 'post_max_size=' . $limit, '-d', 'upload_max_filesize=' . $limit, $app . '/vendor/bin/phpunit',
				'-c', $work . '/phpunit.xml', '--do-not-cache-result'];
		}
		return self::execute([...$command, '--filter', $filter,
			'--log-junit', $work . '/' . $profile . '.xml'], $environment);
	}

	/**
	 * Streams a child process without a shell or pipe buffering; preserves inherited environment by default.
	 * @param list<string> $command Executable and arguments.
	 * @param array<string,string>|null $environment Child environment.
	 * @return int Child exit status.
	 */
	public static function execute(array $command, ?array $environment = null): int {
		$process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, null, $environment);
		if (!is_resource($process)) { throw new \RuntimeException('Cannot start PHP process'); }
		return proc_close($process);
	}
}
