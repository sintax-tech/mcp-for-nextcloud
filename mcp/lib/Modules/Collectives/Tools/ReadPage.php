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
use OCA\Mcp\Modules\Collectives\Reading\ReadPageHandler;
use OCA\Mcp\Platform\FileAccess\NodeAccess;

/** `collectives_read`: one page of a collective, whole. */
final class ReadPage implements Tool {
	/**
	 * @param ReadPageHandler $handler the read
	 */
	public function __construct(
		private ReadPageHandler $handler,
	) {
	}

	/**
	 * @return ToolDefinition the published definition of collectives_read
	 */
	public static function definition(): ToolDefinition {
		return CollectivesSchema::definition('collectives_read', 'read', CollectivesMessages::TOOL_READ_DESCRIPTION, [
			'collective_id' => CollectivesSchema::collectiveId(),
			'page_id' => CollectivesSchema::pageId(),
		], ['collective_id', 'page_id']);
	}

	/**
	 * @param array<string, mixed> $arguments validated collectives_read arguments
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the answer as JSON
	 * @throws ToolFailure when the call is refused or Collectives fails
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return NodeAccess::run(fn (): array => ToolResult::json($this->handler->handle($context->userId, $arguments)));
	}
}
