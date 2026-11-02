<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Logs;

use OCA\Mcp\Access\Logs\LogFileNames;
use OCA\Mcp\Access\Logs\LogsAccess;
use OCA\Mcp\Mcp\Tools\CatalogModule;
use OCA\Mcp\Mcp\Tools\RestrictedModule;
use OCA\Mcp\Modules\Logs\Presentation\LogEnvelope;
use OCA\Mcp\Modules\Logs\Presentation\LogRedactor;
use OCA\Mcp\Modules\Logs\Presentation\LogsGuideNotes;
use OCA\Mcp\Modules\Logs\Reading\LogReader;
use OCA\Mcp\Modules\Logs\Reading\LogsReadWindow;
use Psr\Container\ContainerInterface;

/**
 * Reading and analysis of the Nextcloud server log (nextcloud.log), for diagnosis by the IT staff.
 *
 * Read only: nothing here deletes, clears or rotates the log, nor changes the log level. Two gates come before a
 * call: the role of {@see LogsAccess} (an administrator, or a member of a group the administrator listed), checked by
 * the registry as a {@see RestrictedModule}, and then the per-user grant logs.read, which starts denied.
 *
 * The tools are {@see LogsToolCatalog}. The entries come from {@see LogReader} only, are redacted by
 * {@see LogRedactor}, travel in the untrusted envelope of {@see LogEnvelope}, and every call, refused ones included,
 * is handed to audit() by the registry and recorded by {@see LogAudit}. While {@see LogFileNames} is active, the text
 * of every message is hidden whole, URLs keep only their route prefix, and `contains`, which would match the hidden
 * text, is refused. The module binds nothing, so it has no registration.
 */
final class LogsModule extends CatalogModule implements RestrictedModule {
	/** Default page of logs_list. */
	public const DEFAULT_LIMIT = LogsReadWindow::DEFAULT_LIMIT;
	/** Largest page of logs_list. */
	public const MAX_LIMIT = LogsReadWindow::MAX_LIMIT;

	/**
	 * @param ContainerInterface $container the app container the tools are resolved from
	 * @param LogsAccess $access the role gate
	 * @param LogAudit $audit where every call is recorded
	 */
	public function __construct(
		ContainerInterface $container,
		private LogsAccess $access,
		private LogAudit $audit,
	) {
		parent::__construct($container);
	}

	/**
	 * @return list<class-string<\OCA\Mcp\Mcp\Tools\Tool>> the Logs tools, in tools/list order
	 */
	public static function catalog(): array {
		return LogsToolCatalog::TOOLS;
	}

	/**
	 * @param string $userId authenticated user
	 * @return bool whether the user passes the role gate and the log is a file
	 */
	public function permits(string $userId): bool {
		return $this->access->permits($userId);
	}

	/**
	 * Records a call with its outcome, as the registry reports it.
	 *
	 * @param string $tool technical name of the tool called
	 * @param string $userId authenticated user
	 * @param string $outcome one of the RestrictedModule outcomes
	 * @param array<string, mixed> $arguments arguments of the call
	 */
	public function audit(string $tool, string $userId, string $outcome, array $arguments): void {
		$this->audit->recordCall($tool, $userId, $outcome, $arguments);
	}

	/**
	 * @return list<string> what the schemas cannot say about a log read
	 */
	public function guideNotes(): array {
		return LogsGuideNotes::notes();
	}
}
