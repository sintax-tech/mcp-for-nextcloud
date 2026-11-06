<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Tools;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Collectives\Presentation\CollectivesMessages;
use OCA\Mcp\Modules\Collectives\Presentation\CollectivesSchema;
use OCA\Mcp\Modules\Collectives\Reading\SearchPagesHandler;
use OCA\Mcp\Platform\FileAccess\NodeAccess;

/** `collectives_search`: pages of one collective by title or content. */
final class SearchPages implements Tool {
	/**
	 * @param SearchPagesHandler $handler the search
	 */
	public function __construct(
		private SearchPagesHandler $handler,
	) {
	}

	/**
	 * @return ToolDefinition the published definition of collectives_search
	 */
	public static function definition(): ToolDefinition {
		return CollectivesSchema::definition('collectives_search', 'read', CollectivesMessages::TOOL_SEARCH_DESCRIPTION, [
			'collective_id' => CollectivesSchema::collectiveId(),
			'query' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200, 'description' => CollectivesMessages::PARAM_QUERY],
			'scope' => ['type' => 'string', 'enum' => ['title', 'content'], 'default' => 'title', 'description' => CollectivesMessages::PARAM_SCOPE],
			...CollectivesSchema::paging(),
		], ['collective_id', 'query']);
	}

	/**
	 * @param array<string, mixed> $arguments validated collectives_search arguments
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the answer as JSON
	 * @throws ToolFailure when the call is refused or Collectives fails
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return NodeAccess::run(fn (): array => ToolResult::json($this->handler->handle($context->userId, $arguments)));
	}
}
