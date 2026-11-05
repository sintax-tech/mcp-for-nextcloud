<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tasks\Editing;

use OCA\Mcp\Access\Visibility\OutputReferenceFilter;

use InvalidArgumentException;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
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
	 * @param \OCA\Mcp\Modules\Tasks\Writing\ParentTaskWrite $write plan and confirmed write of the task writes that take parent
	 * @return void
	 * @param OutputReferenceFilter $references availability gate before any calendar or task lookup
	 */
	public function __construct(
		private \OCA\Mcp\Modules\Tasks\Writing\ParentTaskWrite $write,
		private OutputReferenceFilter $references,
	) {
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
		$this->references->assertAvailable($userId);
		return $this->write->handle(self::TOOL, $arguments, $userId, true);
	}
}
