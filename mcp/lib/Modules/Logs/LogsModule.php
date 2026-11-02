<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Logs;

use OCA\Mcp\Modules\Logs\Presentation\LogEnvelope;
use OCA\Mcp\Modules\Logs\Presentation\LogRedactor;
use OCA\Mcp\Modules\Logs\Presentation\LogsGuideNotes;
use OCA\Mcp\Modules\Logs\Presentation\LogsSchema;
use OCA\Mcp\Modules\Logs\Reading\AnalyzeLogsHandler;
use OCA\Mcp\Modules\Logs\Reading\ListLogsHandler;
use OCA\Mcp\Modules\Logs\Reading\LogReader;
use OCA\Mcp\Modules\Logs\Reading\LogScanner;
use OCA\Mcp\Modules\Logs\Reading\LogsReadWindow;
use InvalidArgumentException;
use OCA\Mcp\Access\Logs\LogFileNames;
use OCA\Mcp\Access\Logs\LogsAccess;
use OCA\Mcp\Mcp\Tools\RestrictedModule;
use OCA\Mcp\Mcp\Guide\ToolGuideNotes;
use OCA\Mcp\Mcp\Tools\ToolModule;
use OCP\IConfig;

/**
 * Reading and analysis of the Nextcloud server log (nextcloud.log), for diagnosis by the IT staff.
 *
 * Read only: nothing here deletes, clears or rotates the log, nor changes the log level. Two gates come before a
 * call: the role of {@see LogsAccess} (an administrator, or a member of a group the administrator listed), checked by
 * the registry as a {@see RestrictedModule}, and then the per-user grant logs.read, which starts denied.
 *
 * The entries come from {@see LogReader} only, are redacted by {@see LogRedactor}, travel in the untrusted envelope of
 * {@see LogEnvelope}, and every call, refused ones included, is handed to audit() by the registry and recorded by
 * {@see LogAudit}. While {@see LogFileNames} is active, the text of every message is hidden whole, URLs keep only
 * their route prefix, and `contains`, which would match the hidden text, is refused.
 */
class LogsModule implements ToolModule, ToolGuideNotes, RestrictedModule {
	/** Default page of logs_list. */
	public const DEFAULT_LIMIT = LogsReadWindow::DEFAULT_LIMIT;
	/** Largest page of logs_list. */
	public const MAX_LIMIT = LogsReadWindow::MAX_LIMIT;

	public function __construct(
		private LogsAccess $access,
		private LogScanner $scanner,
		private LogAudit $audit,
		private IConfig $config,
		private LogFileNames $fileNames,
	) {}

	/** @param string $userId authenticated user @return bool whether the user passes the role gate and the log is a file */
	public function permits(string $userId): bool {
		return $this->access->permits($userId);
	}

	/**
	 * Records a call with its outcome, as the registry reports it. The arguments are untrusted: only the known filters,
	 * offset and limit are kept, scalars only, each text cut.
	 *
	 * @param string $tool technical name of the tool called
	 * @param string $userId authenticated user
	 * @param string $outcome one of the RestrictedModule outcomes
	 * @param array<string, mixed> $arguments arguments of the call
	 */
	public function audit(string $tool, string $userId, string $outcome, array $arguments): void {
		$this->audit->recordCall($tool, $userId, $outcome, $arguments);
	}

	/** @return list<string> short behaviour notes about this module */
	public function guideNotes(): array {
		return LogsGuideNotes::notes();
	}

	/** @return list<array{name:string, description:string, inputSchema:array<string, mixed>, module:string, operation:string}> */
	public function definitions(): array {
		return [LogsSchema::list()->toArray(), LogsSchema::analyze()->toArray()];
	}

	/**
	 * @param string $name logs_list or logs_analyze
	 * @param array<string, mixed> $arguments arguments validated against the schema, defaults applied
	 * @param string $userId authenticated user
	 * @return array{content: list<array{type:string, text:string}>, structuredContent: array<string, mixed>}
	 * @throws InvalidArgumentException for an unknown tool or an invalid filter, and for contains while messages are
	 *         hidden, before anything is read
	 * @throws \OCA\Mcp\Mcp\Tools\Results\ToolFailure when the log cannot be read
	 */
	public function call(string $name, array $arguments, string $userId): array {
		$window = new LogsReadWindow($this->scanner, $this->config, $this->fileNames);
		if ($name === 'logs_list') {
			return (new ListLogsHandler($window))->handle($arguments);
		}
		if ($name === 'logs_analyze') {
			return (new AnalyzeLogsHandler($window))->handle($arguments);
		}
		throw new InvalidArgumentException('Unknown tool');
	}
}
