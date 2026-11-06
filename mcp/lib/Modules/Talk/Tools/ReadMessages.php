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
use OCA\Mcp\Modules\Talk\Reading\ReadMessagesHandler;
use OCA\Mcp\Modules\Talk\Reading\ConversationReader;

/** Publishes talk_read_messages through its existing work classes. */
final class ReadMessages implements Tool {
	/**
	 * @param ReadMessagesHandler $handler unchanged call and effect
	 */
	public function __construct(private ReadMessagesHandler $handler) {
	}

	/** @return ToolDefinition the unchanged public definition, without resolving native services */
	public static function definition(): ToolDefinition {
		return TalkSchema::definition(
			'talk_read_messages', 'read', TalkDescriptions::TOOL_READ_MESSAGES,
			[
				'conversation_token' => TalkSchema::token(),
				'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => ConversationReader::MAX_MESSAGES, 'default' => ConversationReader::DEFAULT_MESSAGES],
			],
			['conversation_token'],
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
