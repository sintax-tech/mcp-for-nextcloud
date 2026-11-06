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
use OCA\Mcp\Modules\Talk\Replying\ReplyHandler;
use OCA\Mcp\Modules\Talk\Replying\ReplyPreview;

/** Publishes talk_reply through its existing work classes. */
final class Reply implements WriteTool {
	/**
	 * @param ReplyHandler $handler unchanged call and effect
	 * @param ReplyPreview $plans unchanged side-effect-free plan
	 */
	public function __construct(private ReplyHandler $handler, private ReplyPreview $plans) {
	}

	/** @return ToolDefinition the unchanged public definition, without resolving native services */
	public static function definition(): ToolDefinition {
		return TalkSchema::definition(
			'talk_reply', 'reply', TalkDescriptions::TOOL_REPLY,
			[
				'conversation_token' => TalkSchema::token(),
				'message' => TalkSchema::message(),
				'reply_to' => TalkSchema::identifier(),
				'reference' => TalkSchema::reference(),
			],
			['conversation_token', 'message'],
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
