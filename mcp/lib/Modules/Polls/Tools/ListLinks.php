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
use OCA\Mcp\Modules\Polls\Reading\ListLinksHandler;

/** `polls_list_links`: the existing direct participation links of one poll, for its editors, under the links grant. */
final class ListLinks implements Tool {
	/**
	 * @param ListLinksHandler $reads the read of this tool
	 */
	public function __construct(
		private ListLinksHandler $reads,
	) {
	}

	/**
	 * @return ToolDefinition the published definition of polls_list_links
	 */
	public static function definition(): ToolDefinition {
		return PollsSchema::content(ListLinksHandler::TOOL, 'links', PollsContentMessages::LINKS, false);
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
