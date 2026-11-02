<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Logs\Tools;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Logs\Presentation\LogsSchema;
use OCA\Mcp\Modules\Logs\Reading\ListLogsHandler;

/** `logs_list`: one page of the server log, newest first. */
final class ListLogs implements Tool {
	/**
	 * @param ListLogsHandler $handler the page
	 */
	public function __construct(
		private ListLogsHandler $handler,
	) {
	}

	/**
	 * @return ToolDefinition the published definition of logs_list
	 */
	public static function definition(): ToolDefinition {
		return LogsSchema::list();
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments
	 * @param ToolContext $context who calls; the registry already checked the role and the grant
	 * @return array<string, mixed> the page inside the untrusted envelope
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->handler->handle($arguments);
	}
}
