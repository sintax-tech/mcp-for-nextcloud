<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tasks\Handler;

use InvalidArgumentException;
use OCA\Mcp\Tools\Tasks\TaskParentStep;
use OCA\Mcp\Tools\Tasks\TaskWriteFlow;
use OCA\Mcp\Tools\ToolFailure;
use OCA\Mcp\Tools\ToolResult;
use OCA\Mcp\Tools\WriteGate;
use RuntimeException;

/**
 * The preview and the confirmed call shared by the two task writes that take `parent`: the plan of the write flow
 * with the parent check, and, once confirmed, the same check and the write under the hierarchy lock when a parent
 * is set, followed by the loop check of the chain read back.
 */
final class ParentTaskWrite {
	/**
	 * Receives the write flow and the parent step.
	 *
	 * @param TaskWriteFlow $flow plan and native write of task writes
	 * @param TaskParentStep $parent hierarchy check, titles, lock and warning
	 * @return void
	 */
	public function __construct(
		private TaskWriteFlow $flow,
		private TaskParentStep $parent,
	) {
	}

	/**
	 * The plan of the write, with the parent checked and named.
	 *
	 * @param string $tool tasks_create_task or tasks_edit_task
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @param string $userId authenticated user UID
	 * @param bool $existing whether the task already exists (edit)
	 * @return array<string, mixed> the plan
	 * @throws ToolFailure when access, concurrency, recovery or parent checks refuse the operation
	 * @throws InvalidArgumentException when the supplied fields are invalid
	 */
	public function preview(string $tool, array $arguments, string $userId, bool $existing): array {
		return $this->parent->plan($this->flow->prepare($tool, $arguments, $userId), $arguments, $userId, $existing)['plan'];
	}

	/**
	 * The plan without confirm; with confirm, the checked write.
	 *
	 * @param string $tool tasks_create_task or tasks_edit_task
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @param string $userId authenticated user UID
	 * @param bool $existing whether the task already exists (edit)
	 * @return array{content:list<array{type:string, text:string}>, isError?:bool} MCP result
	 * @throws ToolFailure when access, concurrency, recovery, parent or lock checks refuse the operation
	 * @throws InvalidArgumentException when the supplied fields are invalid
	 * @throws RuntimeException when the native write or its read-back verification fails
	 */
	public function handle(string $tool, array $arguments, string $userId, bool $existing): array {
		if (!WriteGate::confirmed($arguments)) {
			return ToolResult::json($this->preview($tool, $arguments, $userId, $existing));
		}
		$calendar = $this->flow->writableCalendar($userId, $arguments);
		return ToolResult::json($this->parent->guarded($calendar, $arguments, function () use ($tool, $arguments, $userId, $existing): array {
			$prepared = $this->parent->plan($this->flow->prepare($tool, $arguments, $userId), $arguments, $userId, $existing);
			return $this->parent->afterWrite($this->flow->commit($prepared, $arguments, $userId), $prepared['calendar'], $arguments);
		}));
	}
}
