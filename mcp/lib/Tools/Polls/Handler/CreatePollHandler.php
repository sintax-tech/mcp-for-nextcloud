<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Polls\Handler;

use OCA\Mcp\Tools\Polls\Create\CreateFlow;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;

/** One handler for the independently granted, confirmed private creation tool. */
final class CreatePollHandler {
	/**
	 * Receives the focused effect-aware flow.
	 * @param CreateFlow $flow preview and confirmed attempt
	 * @return void
	 */
	public function __construct(private CreateFlow $flow) {
	}
	/**
	 * Describes without writing.
	 * @param array<string,mixed> $arguments input
	 * @param string $userId authenticated actor
	 * @return array<string,mixed> structured plan
	 */
	public function preview(array $arguments, string $userId): array {
		return $this->flow->run($arguments,$userId,true);
	}
	/**
	 * Executes only an explicitly confirmed matching plan; never retries.
	 * @param array<string,mixed> $arguments input with confirm/plan_state
	 * @param string $userId authenticated actor
	 * @return array<string,mixed> MCP envelope with honest receipt
	 */
	public function handle(array $arguments, string $userId): array {
		return ToolResult::json($this->flow->run($arguments,$userId,false));
	}
}
