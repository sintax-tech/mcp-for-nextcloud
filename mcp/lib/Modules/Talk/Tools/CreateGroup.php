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
use OCA\Mcp\Modules\Talk\Groups\CreateGroupHandler;
use OCA\Mcp\Modules\Talk\Groups\CreateGroupPreview;
use OCA\Mcp\Modules\Talk\Groups\GroupCreator;

/** Publishes talk_create_group through its existing work classes. */
final class CreateGroup implements WriteTool {
	/**
	 * @param CreateGroupHandler $handler unchanged call and effect
	 * @param CreateGroupPreview $plans unchanged side-effect-free plan
	 */
	public function __construct(private CreateGroupHandler $handler, private CreateGroupPreview $plans) {
	}

	/** @return ToolDefinition the unchanged public definition, without resolving native services */
	public static function definition(): ToolDefinition {
		return TalkSchema::definition(
			'talk_create_group', 'create', TalkDescriptions::TOOL_CREATE_GROUP,
			[
				'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => GroupCreator::MAX_NAME_LENGTH],
				'participants' => ['type' => 'array', 'maxItems' => GroupCreator::MAX_PARTICIPANTS, 'items' => TalkSchema::user()],
			],
			['name'],
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
