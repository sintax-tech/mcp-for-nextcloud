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
use OCA\Mcp\Modules\Polls\Reading\ReadResultsHandler;

/** `polls_read_results`: the native aggregates of one poll, under the results grant. */
final class ReadResults implements Tool {
	/**
	 * @param ReadResultsHandler $reads the read of this tool
	 */
	public function __construct(
		private ReadResultsHandler $reads,
	) {
	}

	/**
	 * @return ToolDefinition the published definition of polls_read_results
	 */
	public static function definition(): ToolDefinition {
		return PollsSchema::content(ReadResultsHandler::TOOL, 'results', PollsContentMessages::RESULTS, false);
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
