<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Polls\Handler;

use OCA\Mcp\Tools\Polls\{PollsContext, PollsReading, PollsMessages, PollsRequest};
use OCA\Mcp\Mcp\Tools\Results\ToolResult;

/** Handler for polls_read_poll, with no write/preview path. */
final class ReadPollHandler {
	/** Published MCP tool name. */
	public const TOOL = 'polls_read_poll';
	/**
	 * Receives the context and this tool's reader.
	 * @param PollsContext $context native authenticated scope
	 * @param PollsReading $reader native read flow
	 * @return void
	 */
	public function __construct(private PollsContext $context, private PollsReading $reader) {
	}

	/**
	 * Validates, reads, projects and cleans the native context before JSON encoding.
	 * @param array<string,mixed> $arguments tool arguments
	 * @param string $userId authenticated actor
	 * @return array{content:list<array{type:string,text:string}>} MCP answer
	 * @throws \InvalidArgumentException for malformed input
	 * @throws \OCA\Mcp\Tools\Polls\PollsRefusal for a refused native read
	 */
	public function handle(array $arguments, string $userId): array {
		$request = PollsRequest::of($arguments, true);
		$result = $this->context->run($userId, fn (object $session, string $version): array => $this->reader->page($request, $userId, $session, $version));
		return ToolResult::json(['untrustedPollsData' => true, 'notice' => PollsMessages::NOTICE] + $result);
	}
}
