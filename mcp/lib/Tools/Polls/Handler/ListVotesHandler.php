<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Polls\Handler;

use OCA\Mcp\Tools\Polls\{PollsContext, PollsContentPage, PollsContentRequest, PollsMessages};
use OCA\Mcp\Tools\ToolResult;

/** Independent read handler for polls_list_votes; there is no confirmation or mutation path. */
final class ListVotesHandler {
	/** Published tool identifier. */
	public const TOOL = 'polls_list_votes';
	/**
	 * Receives the checked native context and focused content reader.
	 * @param PollsContext $context authenticated native scope
	 * @param PollsContentPage $reader native read/projection flow
	 * @return void
	 */
	public function __construct(private PollsContext $context, private PollsContentPage $reader) {
	}

	/**
	 * Validates, checks the exact grant, projects and cleans before encoding the response.
	 * @param array<string,mixed> $arguments input
	 * @param string $userId authenticated actor
	 * @return array<string,mixed> untrusted MCP data
	 * @throws \InvalidArgumentException for invalid input
	 * @throws \OCA\Mcp\Tools\Polls\PollsRefusal for a refused read
	 */
	public function handle(array $arguments, string $userId): array {
		$request = PollsContentRequest::of($arguments, true);
		$result = $this->context->run($userId, fn (object $session, string $version): array =>
			$this->reader->read('votes', self::TOOL, $request, $userId, $session, $version), 'results');
		return ToolResult::json(['untrustedPollsData' => true, 'notice' => PollsMessages::NOTICE] + $result);
	}
}
