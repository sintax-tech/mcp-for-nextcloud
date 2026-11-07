<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tasks\Tools;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\Schema\DavCollectionSchema;
use OCA\Mcp\Modules\Tasks\Presentation\TasksSchema;
use OCA\Mcp\Modules\Tasks\Reading\ReadTaskHandler;

/** Publishes tasks_read_task through its existing work classes. */
final class ReadTask implements Tool {
	/**
	 * @param ReadTaskHandler $handler existing task call
	 */
	public function __construct(private ReadTaskHandler $handler) {
	}

	/** @return ToolDefinition the unchanged task definition */
	public static function definition(): ToolDefinition {
		return TasksSchema::definition(
			'tasks_read_task', 'read',
			'Read a task by its UID in a visible task calendar.',
			TasksSchema::identity(),
			['calendar', 'uid'],
		);
	}

	/**
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @param ToolContext $context authenticated caller
	 * @return array<string, mixed> MCP result
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->handler->handle($arguments, $context->userId);
	}
}
