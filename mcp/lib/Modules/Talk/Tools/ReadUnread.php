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
use OCA\Mcp\Modules\Talk\Presentation\TalkDescriptions;
use OCA\Mcp\Modules\Talk\Presentation\TalkSchema;
use OCA\Mcp\Modules\Talk\Reading\ReadUnreadHandler;
use OCA\Mcp\Modules\Talk\Reading\UnreadMessages;

/** Publishes talk_read_unread through its read-only work classes. */
final class ReadUnread implements Tool {
	/**
	 * @param ReadUnreadHandler $handler read-only call and effect
	 */
	public function __construct(private ReadUnreadHandler $handler) {
	}

	/** @return ToolDefinition the public definition, without resolving native services */
	public static function definition(): ToolDefinition {
		return TalkSchema::definition(
			'talk_read_unread',
			'read',
			TalkDescriptions::TOOL_READ_UNREAD,
			[
				'conversation_token' => TalkSchema::token(),
				'limit' => [
					'type' => 'integer',
					'minimum' => 1,
					'maximum' => UnreadMessages::MAX_MESSAGES,
					'default' => UnreadMessages::DEFAULT_MESSAGES,
				],
				'cursor' => [
					'type' => 'string',
					'minLength' => 1,
					'maxLength' => UnreadMessages::MAX_CURSOR_LENGTH,
					'description' => 'Continuation from the previous page of this same read: the same user and the same conversation. It continues after the last message already returned, even if the read marker moved meanwhile. Starting again from the current marker means calling without this cursor.',
				],
			],
			['conversation_token'],
		);
	}

	/**
	 * @param array<string, mixed> $arguments validated MCP arguments
	 * @param ToolContext $context authenticated caller
	 * @return array<string, mixed> MCP result envelope
	 * @throws \InvalidArgumentException for a client mistake
	 * @throws \OCA\Mcp\Access\Policy\PolicyUnavailable when scope is unreadable
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->handler->handle($arguments, $context->userId);
	}
}
