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
use OCA\Mcp\Modules\Collectives\Reading\ListCollectivesHandler;
use OCA\Mcp\Platform\FileAccess\NodeAccess;

/** `collectives_list`: the collectives the user is a member of. */
final class ListCollectives implements Tool {
	/**
	 * @param ListCollectivesHandler $handler the listing
	 */
	public function __construct(
		private ListCollectivesHandler $handler,
	) {
	}

	/**
	 * @return ToolDefinition the published definition of collectives_list
	 */
	public static function definition(): ToolDefinition {
		return CollectivesSchema::definition('collectives_list', 'read', CollectivesMessages::TOOL_LIST_DESCRIPTION, [...CollectivesSchema::paging()]);
	}

	/**
	 * @param array<string, mixed> $arguments validated collectives_list arguments
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the answer as JSON
	 * @throws ToolFailure when the call is refused or Collectives fails
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return NodeAccess::run(fn (): array => ToolResult::json($this->handler->handle($context->userId, $arguments)));
	}
}
