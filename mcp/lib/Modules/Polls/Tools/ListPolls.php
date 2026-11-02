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
use OCA\Mcp\Modules\Polls\Reading\ListPollsHandler;

/** `polls_list_polls`: the polls the caller may open, newest first. */
final class ListPolls implements Tool {
	/**
	 * @param ListPollsHandler $reads the read of this tool
	 */
	public function __construct(
		private ListPollsHandler $reads,
	) {
	}

	/**
	 * @return ToolDefinition the published definition of polls_list_polls
	 */
	public static function definition(): ToolDefinition {
		return PollsSchema::read(ListPollsHandler::TOOL, PollsMessages::LIST, PollsSchema::page('limit', 'cursor', 25));
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
