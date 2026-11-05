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
use OCA\Mcp\Modules\Tasks\Deletion\DeleteTaskHandler;
use OCA\Mcp\Modules\Tasks\Deletion\DeleteTaskPreview;

/** Publishes tasks_delete_task through its existing work classes. */
final class DeleteTask implements WriteTool {
	/**
	 * @param DeleteTaskHandler $handler existing task call
	 * @param DeleteTaskPreview $plans existing task plan
	 */
	public function __construct(private DeleteTaskHandler $handler, private DeleteTaskPreview $plans) {
	}

	/** @return ToolDefinition the unchanged task definition */
	public static function definition(): ToolDefinition {
		return TasksSchema::definition(
			'tasks_delete_task', 'delete',
			'Move a simple task to the calendar trash. Refused when calendar retention is zero.',
			TasksSchema::identity() + ['etag' => DavCollectionSchema::etag()],
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

	/**
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @param ToolContext $context authenticated caller
	 * @return array<string, mixed> before/after plan without an effect
	 */
	public function preview(array $arguments, ToolContext $context): array {
		return $this->plans->plan($arguments, $context->userId);
	}
}
