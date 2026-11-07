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
use OCA\Mcp\Modules\Talk\Attaching\AttachFileHandler;
use OCA\Mcp\Modules\Talk\Attaching\AttachFilePreview;

/** Publishes talk_attach_file through its existing work classes. */
final class AttachFile implements WriteTool {
	/**
	 * @param AttachFileHandler $handler unchanged call and effect
	 * @param AttachFilePreview $plans unchanged side-effect-free plan
	 */
	public function __construct(private AttachFileHandler $handler, private AttachFilePreview $plans) {
	}

	/** @return ToolDefinition the unchanged public definition, without resolving native services */
	public static function definition(): ToolDefinition {
		return TalkSchema::definition(
			'talk_attach_file', 'attach', TalkDescriptions::TOOL_ATTACH_FILE,
			[
				'conversation_token' => TalkSchema::token(),
				'path' => [
				'type' => 'string',
				'minLength' => 1,
				'maxLength' => 4000,
			],
				'message' => TalkSchema::message(),
			],
			['conversation_token', 'path'],
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
