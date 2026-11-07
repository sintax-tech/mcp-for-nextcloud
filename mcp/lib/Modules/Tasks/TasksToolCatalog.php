<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tasks;

/** The existing Tasks tools in tools/list order. */
final class TasksToolCatalog {
	/** @var list<class-string<\OCA\Mcp\Mcp\Tools\Tool>> published tool classes */
	public const TOOLS = [
		\OCA\Mcp\Modules\Tasks\Tools\ListTaskCalendars::class,
		\OCA\Mcp\Modules\Tasks\Tools\ListTasks::class,
		\OCA\Mcp\Modules\Tasks\Tools\ReadTask::class,
		\OCA\Mcp\Modules\Tasks\Tools\CreateTask::class,
		\OCA\Mcp\Modules\Tasks\Tools\EditTask::class,
		\OCA\Mcp\Modules\Tasks\Tools\CompleteTask::class,
		\OCA\Mcp\Modules\Tasks\Tools\DeleteTask::class,
	];

	/** Constant holder only. */
	private function __construct() {
	}
}
