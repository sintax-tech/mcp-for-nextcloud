<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Tools;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Modules\Collectives\Creation\CreatePageHandler;
use OCA\Mcp\Modules\Collectives\Creation\CreatePagePreview;
use OCA\Mcp\Modules\Collectives\Presentation\CollectivesMessages;
use OCA\Mcp\Modules\Collectives\Presentation\CollectivesSchema;
use OCA\Mcp\Platform\FileAccess\NodeAccess;

/** `collectives_create`: one empty page under a parent page of a collective. */
final class CreatePage implements WriteTool {
	/**
	 * @param CreatePageHandler $handler the confirmed creation
	 * @param CreatePagePreview $preview the plan of the creation
	 */
	public function __construct(
		private CreatePageHandler $handler,
		private CreatePagePreview $preview,
	) {
	}

	/**
	 * @return ToolDefinition the published definition of collectives_create
	 */
	public static function definition(): ToolDefinition {
		return CollectivesSchema::definition('collectives_create', 'create', CollectivesMessages::TOOL_CREATE_DESCRIPTION, [
			'collective_id' => CollectivesSchema::collectiveId(),
			'parent_id' => ['type' => 'integer', 'minimum' => 0, 'description' => CollectivesMessages::PARAM_CREATE_PARENT],
			'title' => CollectivesSchema::title(),
			PlanState::ARGUMENT => PlanState::property(),
		], ['collective_id', 'parent_id', 'title']);
	}

	/**
	 * @param array<string, mixed> $arguments validated collectives_create arguments
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the receipt as JSON
	 * @throws ToolFailure when the call is refused before the attempt
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return NodeAccess::run(fn (): array => ToolResult::json($this->handler->handle($context->userId, $arguments)));
	}

	/**
	 * @param array<string, mixed> $arguments validated collectives_create arguments
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the plan
	 * @throws ToolFailure when the collective or the parent is not admitted, or the actor may not edit
	 */
	public function preview(array $arguments, ToolContext $context): array {
		return NodeAccess::run(fn (): array => $this->preview->plan($context->userId, $arguments));
	}
}
