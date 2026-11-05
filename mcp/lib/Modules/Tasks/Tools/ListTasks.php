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
use OCA\Mcp\Modules\Tasks\Reading\ListTasksHandler;

/** Publishes tasks_list_tasks through its existing work classes. */
final class ListTasks implements Tool {
	/**
	 * @param ListTasksHandler $handler existing task call
	 */
	public function __construct(private ListTasksHandler $handler) {
	}

	/** @return ToolDefinition the unchanged task definition */
	public static function definition(): ToolDefinition {
		return TasksSchema::definition(
			'tasks_list_tasks', 'read',
			'List tasks, including undated tasks, in one visible task calendar, optionally only those with a category or only the subtasks of one task.',
			[
				'calendar' => TasksSchema::calendar(),
				'include_completed' => ['type' => 'boolean', 'default' => false, 'description' => 'Include completed tasks.'],
				'category' => DavCollectionSchema::text('Only list tasks with this category, compared exactly but ignoring case. Omit the field to apply no category filter; the value is not a pattern.', 1, \OCA\Mcp\Modules\Tasks\Composing\TaskData::MAX_CATEGORY_LENGTH),
				'parent' => DavCollectionSchema::text('Only list the direct subtasks of the task with this UID. Omit the field to list every task.', 1, 255),
				'limit' => DavCollectionSchema::limit(),
				'offset' => DavCollectionSchema::offset(),
			],
			['calendar'],
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
