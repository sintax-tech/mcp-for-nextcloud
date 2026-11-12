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
use OCA\Mcp\Modules\Talk\Reading\ListUnreadHandler;
use OCA\Mcp\Modules\Talk\Reading\UnreadConversations;

/** Publishes talk_list_unread through its read-only work classes. */
final class ListUnread implements Tool {
	/**
	 * @param ListUnreadHandler $handler read-only call and effect
	 */
	public function __construct(private ListUnreadHandler $handler) {
	}

	/** @return ToolDefinition the public definition, without resolving native services */
	public static function definition(): ToolDefinition {
		return TalkSchema::definition(
			'talk_list_unread',
			'read',
			TalkDescriptions::TOOL_LIST_UNREAD,
			[
				'limit' => [
					'type' => 'integer',
					'minimum' => 1,
					'maximum' => UnreadConversations::MAX_PAGE,
					'default' => UnreadConversations::DEFAULT_PAGE,
				],
				'cursor' => [
					'type' => 'string',
					'minLength' => 1,
					'maxLength' => UnreadConversations::MAX_CURSOR_LENGTH,
					'description' => 'Continuation from the previous page of this same user. The list is not frozen: a conversation that receives a message can change position, and seeing everything again means calling without this cursor.',
				],
			],
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
