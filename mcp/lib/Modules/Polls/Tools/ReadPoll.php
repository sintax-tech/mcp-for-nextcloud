<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Tools;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Polls\Presentation\PollsMessages;
use OCA\Mcp\Modules\Polls\Presentation\PollsSchema;
use OCA\Mcp\Modules\Polls\PollsRefusal;
use OCA\Mcp\Modules\Polls\Reading\ReadPollHandler;

/** `polls_read_poll`: one poll and a page of its visible options. */
final class ReadPoll implements Tool {
	/**
	 * @param ReadPollHandler $reads the read of this tool
	 */
	public function __construct(
		private ReadPollHandler $reads,
	) {
	}

	/**
	 * @return ToolDefinition the published definition of polls_read_poll
	 */
	public static function definition(): ToolDefinition {
		return PollsSchema::read(ReadPollHandler::TOOL, PollsMessages::READ,
			['poll_id' => PollsSchema::pollId()] + PollsSchema::page('options_limit', 'options_cursor', 100), ['poll_id']);
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments
	 * @param ToolContext $context who calls
	 * @return array<string, mixed> the untrusted Polls data as JSON
	 * @throws \InvalidArgumentException for malformed input
	 * @throws PollsRefusal for a refused native read
	 */
	public function execute(array $arguments, ToolContext $context): array {
		return $this->reads->handle($arguments, $context->userId);
	}
}
