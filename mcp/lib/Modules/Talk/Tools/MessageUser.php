<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Talk\Tools;

use OCA\Mcp\Mcp\Tools\WriteTool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Talk\Presentation\TalkSchema;
use OCA\Mcp\Modules\Talk\Presentation\TalkDescriptions;
use OCA\Mcp\Modules\Talk\Messaging\MessageUserHandler;
use OCA\Mcp\Modules\Talk\Messaging\MessageUserPreview;

/** Publishes talk_message_user through its existing work classes. */
final class MessageUser implements WriteTool {
	/**
	 * @param MessageUserHandler $handler unchanged call and effect
	 * @param MessageUserPreview $plans unchanged side-effect-free plan
	 */
	public function __construct(private MessageUserHandler $handler, private MessageUserPreview $plans) {
	}

	/** @return ToolDefinition the unchanged public definition, without resolving native services */
	public static function definition(): ToolDefinition {
		return TalkSchema::definition(
			'talk_message_user', 'reply', TalkDescriptions::TOOL_MESSAGE_USER,
			[
				'user' => TalkSchema::user(),
				'message' => TalkSchema::message(),
			],
			['user', 'message'],
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
	/**
	 * @param array<string,mixed> $arguments validated MCP arguments
	 * @param ToolContext $context authenticated caller
	 * @return array<string,mixed> unchanged confirmation plan
	 * @throws \InvalidArgumentException for a client mistake
	 * @throws \OCA\Mcp\Access\Policy\PolicyUnavailable when scope is unreadable
	 * @throws \OCA\Mcp\Mcp\Tools\Results\ToolFailure when the confirmed call would be refused
	 */
	public function preview(array $arguments, ToolContext $context): array {
		return $this->plans->plan($arguments, $context->userId);
	}
}
