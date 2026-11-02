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
use OCA\Mcp\Modules\Polls\Presentation\PollsContentMessages;
use OCA\Mcp\Modules\Polls\Presentation\PollsSchema;
use OCA\Mcp\Modules\Polls\PollsRefusal;
use OCA\Mcp\Modules\Polls\Reading\ListVotesHandler;

/** `polls_list_votes`: the authorized votes of one poll, under the results grant. */
final class ListVotes implements Tool {
	/**
	 * @param ListVotesHandler $reads the read of this tool
	 */
	public function __construct(
		private ListVotesHandler $reads,
	) {
	}

	/**
	 * @return ToolDefinition the published definition of polls_list_votes
	 */
	public static function definition(): ToolDefinition {
		return PollsSchema::content(ListVotesHandler::TOOL, 'results', PollsContentMessages::VOTES, true);
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
