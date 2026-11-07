<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tasks\Tools;

use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\Schema\DavCollectionSchema;
use OCA\Mcp\Modules\Tasks\Presentation\TasksSchema;
use OCA\Mcp\Modules\Tasks\Creation\CreateTaskHandler;
use OCA\Mcp\Modules\Tasks\Creation\CreateTaskPreview;

/** Publishes tasks_create_task through its existing work classes. */
final class CreateTask implements WriteTool {
	/**
	 * @param CreateTaskHandler $handler existing task call
	 * @param CreateTaskPreview $plans existing task plan
	 */
	public function __construct(private CreateTaskHandler $handler, private CreateTaskPreview $plans) {
	}

	/** @return ToolDefinition the unchanged task definition */
	public static function definition(): ToolDefinition {
		return TasksSchema::definition(
			'tasks_create_task', 'create',
			'Create a simple task in a writable task calendar.',
			['calendar' => TasksSchema::calendar()] + TasksSchema::fields(),
			['calendar', 'summary'],
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

	/**
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @param ToolContext $context authenticated caller
	 * @return array<string, mixed> before/after plan without an effect
	 */
	public function preview(array $arguments, ToolContext $context): array {
		return $this->plans->plan($arguments, $context->userId);
	}
}
