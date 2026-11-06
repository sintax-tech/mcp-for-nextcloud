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
use OCA\Mcp\Modules\Collectives\Moving\MovePageHandler;
use OCA\Mcp\Modules\Collectives\Moving\MovePagePreview;
use OCA\Mcp\Modules\Collectives\Presentation\CollectivesMessages;
use OCA\Mcp\Modules\Collectives\Presentation\CollectivesSchema;
use OCA\Mcp\Platform\FileAccess\NodeAccess;

/** `collectives_move`: one page under another parent page of the same collective, renamed or not. */
final class MovePage implements WriteTool {
	/**
	 * @param MovePageHandler $handler the confirmed move
	 * @param MovePagePreview $preview the plan of the move
	 */
	public function __construct(
		private MovePageHandler $handler,
		private MovePagePreview $preview,
	) {
	}

	/**
	 * @return ToolDefinition the published definition of collectives_move
	 */
	public static function definition(): ToolDefinition {
		return CollectivesSchema::definition('collectives_move', 'move', CollectivesMessages::TOOL_MOVE_DESCRIPTION, [
			'collective_id' => CollectivesSchema::collectiveId(),
			'page_id' => CollectivesSchema::pageId(),
			'parent_id' => CollectivesSchema::pageId(CollectivesMessages::PARAM_MOVE_PARENT),
			'title' => CollectivesSchema::title(CollectivesMessages::PARAM_NEW_TITLE),
			'index' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10000, 'description' => CollectivesMessages::PARAM_INDEX],
			PlanState::ARGUMENT => PlanState::property(),
		], ['collective_id', 'page_id']);
	}

	/**
	 * @param array<string, mixed> $arguments validated collectives_move arguments
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the receipt as JSON
	 * @throws ToolFailure when the call is refused before the attempt
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return NodeAccess::run(fn (): array => ToolResult::json($this->handler->handle($context->userId, $arguments)));
	}

	/**
	 * @param array<string, mixed> $arguments validated collectives_move arguments
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the plan
	 * @throws ToolFailure when a page is not admitted, the actor may not edit, or the move is refused
	 */
	public function preview(array $arguments, ToolContext $context): array {
		return NodeAccess::run(fn (): array => $this->preview->plan($context->userId, $arguments));
	}
}
