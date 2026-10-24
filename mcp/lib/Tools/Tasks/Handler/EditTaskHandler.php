<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tasks\Handler;

use InvalidArgumentException;
use OCA\Mcp\Tools\ToolFailure;
use RuntimeException;

/**
 * Backs `tasks_edit_task`: edits the supplied fields of a simple task; `parent` moves it, with its subtasks, under
 * another task of the same calendar, and null makes it top-level. The plan comes first; the confirmed call checks
 * everything again before the write.
 */
final class EditTaskHandler {
	/** MCP tool name this handler serves. */
	public const TOOL = 'tasks_edit_task';

	/**
	 * Receives the shared parent-aware write.
	 *
	 * @param ParentTaskWrite $write plan and confirmed write of the task writes that take parent
	 * @return void
	 */
	public function __construct(
		private ParentTaskWrite $write,
	) {
	}

	/**
	 * The plan of the write.
	 *
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @param string $userId authenticated user UID
	 * @return array<string, mixed> the plan
	 * @throws ToolFailure when access, concurrency, recovery or parent checks refuse the operation
	 * @throws InvalidArgumentException when the supplied fields are invalid
	 */
	public function preview(array $arguments, string $userId): array {
		return $this->write->preview(self::TOOL, $arguments, $userId, true);
	}

	/**
	 * The plan without confirm; with confirm, the checked write.
	 *
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @param string $userId authenticated user UID
	 * @return array{content:list<array{type:string, text:string}>, isError?:bool} MCP result
	 * @throws ToolFailure when access, concurrency, recovery, parent or lock checks refuse the operation
	 * @throws InvalidArgumentException when the supplied fields are invalid
	 * @throws RuntimeException when the native write or its read-back verification fails
	 */
	public function handle(array $arguments, string $userId): array {
		return $this->write->handle(self::TOOL, $arguments, $userId, true);
	}
}
