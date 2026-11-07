<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Logs;

use OCA\Mcp\Access\Logs\LogFileNames;
use OCA\Mcp\Access\Logs\LogsAccess;
use OCA\Mcp\Modules\Logs\LogAudit;
use OCA\Mcp\Modules\Logs\LogsModule;
use OCA\Mcp\Modules\Logs\Reading\AnalyzeLogsHandler;
use OCA\Mcp\Modules\Logs\Reading\ListLogsHandler;
use OCA\Mcp\Modules\Logs\Reading\LogScanner;
use OCA\Mcp\Modules\Logs\Reading\LogsReadWindow;
use OCA\Mcp\Modules\Logs\Tools\AnalyzeLogs;
use OCA\Mcp\Modules\Logs\Tools\ListLogs;
use OCP\IConfig;
use Psr\Container\ContainerInterface;

/**
 * Builds the Logs catalog module from the services its unit tests used to hand the legacy constructor, wiring each
 * tool the way the app container autowires it.
 */
final class LogsTestModule {
	public static function build(
		LogsAccess $access,
		LogScanner $scanner,
		LogAudit $audit,
		IConfig $config,
		LogFileNames $fileNames,
	): LogsModule {
		$window = new LogsReadWindow($scanner, $config, $fileNames);
		return new LogsModule(self::container([
			ListLogs::class => new ListLogs(new ListLogsHandler($window)),
			AnalyzeLogs::class => new AnalyzeLogs(new AnalyzeLogsHandler($window)),
		]), $access, $audit);
	}

	/**
	 * @param array<string, object> $services instances by id
	 */
	private static function container(array $services): ContainerInterface {
		return new class($services) implements ContainerInterface {
			/** @param array<string, object> $services */
			public function __construct(private array $services) {
			}

			public function get(string $id): mixed {
				return $this->services[$id] ?? throw new \LogicException("no test service $id");
			}

			public function has(string $id): bool {
				return isset($this->services[$id]);
			}
		};
	}
}
