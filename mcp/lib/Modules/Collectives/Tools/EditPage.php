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
use OCA\Mcp\Modules\Collectives\Editing\EditPageHandler;
use OCA\Mcp\Modules\Collectives\Editing\EditPagePreview;
use OCA\Mcp\Modules\Collectives\Editing\PageChange;
use OCA\Mcp\Modules\Collectives\Presentation\CollectivesMessages;
use OCA\Mcp\Modules\Collectives\Presentation\CollectivesSchema;
use OCA\Mcp\Platform\FileAccess\NodeAccess;

/** `collectives_edit`: a whole rewrite, an append or one unique replacement in the Markdown of a page. */
final class EditPage implements WriteTool {
	/**
	 * @param EditPageHandler $handler the confirmed edit
	 * @param EditPagePreview $preview the plan of the edit
	 */
	public function __construct(
		private EditPageHandler $handler,
		private EditPagePreview $preview,
	) {
	}

	/**
	 * @return ToolDefinition the published definition of collectives_edit
	 */
	public static function definition(): ToolDefinition {
		return CollectivesSchema::definition('collectives_edit', 'edit', CollectivesMessages::TOOL_EDIT_DESCRIPTION, [
			'collective_id' => CollectivesSchema::collectiveId(),
			'page_id' => CollectivesSchema::pageId(),
			...PageChange::properties(),
			'etag' => CollectivesSchema::etag(),
			PlanState::ARGUMENT => PlanState::property(),
		], ['collective_id', 'page_id']);
	}

	/**
	 * @param array<string, mixed> $arguments validated collectives_edit arguments
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the receipt as JSON
	 * @throws ToolFailure when the etag is missing or stale, or the call is refused before the attempt
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return NodeAccess::run(fn (): array => ToolResult::json($this->handler->handle($context->userId, $arguments)));
	}

	/**
	 * @param array<string, mixed> $arguments validated collectives_edit arguments
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the plan, with the diff and the etag to confirm with
	 * @throws ToolFailure when the page is not admitted, the actor may not edit, or the change is refused
	 */
	public function preview(array $arguments, ToolContext $context): array {
		return NodeAccess::run(fn (): array => $this->preview->plan($context->userId, $arguments));
	}
}
