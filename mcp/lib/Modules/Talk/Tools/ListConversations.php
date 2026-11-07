<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Talk\Tools;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Talk\Presentation\TalkSchema;
use OCA\Mcp\Modules\Talk\Presentation\TalkDescriptions;
use OCA\Mcp\Modules\Talk\Reading\ListConversationsHandler;

/** Publishes talk_list_conversations through its existing work classes. */
final class ListConversations implements Tool {
	/**
	 * @param ListConversationsHandler $handler unchanged call and effect
	 */
	public function __construct(private ListConversationsHandler $handler) {
	}

	/** @return ToolDefinition the unchanged public definition, without resolving native services */
	public static function definition(): ToolDefinition {
		return TalkSchema::definition(
			'talk_list_conversations', 'read', TalkDescriptions::TOOL_LIST_CONVERSATIONS,
			new \stdClass(),
			[],
		);
	}

	/**
	 * @param array<string,mixed> $arguments validated MCP arguments
	 * @param ToolContext $context authenticated caller
	 * @return array<string,mixed> unchanged MCP result envelope
	 * @throws \InvalidArgumentException for a client mistake
	 * @throws \OCA\Mcp\Access\Policy\PolicyUnavailable when scope is unreadable
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->handler->handle($arguments, $context->userId);
	}
}
