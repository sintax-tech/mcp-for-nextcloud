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
use OCA\Mcp\Modules\Tasks\Editing\EditTaskHandler;
use OCA\Mcp\Modules\Tasks\Editing\EditTaskPreview;

/** Publishes tasks_edit_task through its existing work classes. */
final class EditTask implements WriteTool {
	/**
	 * @param EditTaskHandler $handler existing task call
	 * @param EditTaskPreview $plans existing task plan
	 */
	public function __construct(private EditTaskHandler $handler, private EditTaskPreview $plans) {
	}

	/** @return ToolDefinition the unchanged task definition */
	public static function definition(): ToolDefinition {
		return TasksSchema::definition(
			'tasks_edit_task', 'edit',
			'Edit supplied fields of a simple task, preserving other properties.',
			TasksSchema::identity() + ['etag' => DavCollectionSchema::etag()] + TasksSchema::fields(),
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
