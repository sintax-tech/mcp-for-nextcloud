<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Status\Reading;

use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\Modules\Status\Integration\StatusGateway;
use OCP\AppFramework\Utility\ITimeFactory;

/** Reads the actor's stored status and projects expiration without writing. */
final class ReadOwnStatusHandler {
	/**
	 * @param StatusGateway $native select of the actor's row
	 * @param ITimeFactory $time clock for the projected expired flag
	 * @param StatusSnapshot $snapshot response shape
	 */
	public function __construct(
		private StatusGateway $native,
		private ITimeFactory $time,
		private StatusSnapshot $snapshot,
	) {
	}

	/**
	 * @param string $userId authenticated actor; arguments cannot name anyone else
	 * @return array<string, mixed> MCP result
	 */
	public function handle(string $userId): array {
		return ToolResult::json($this->snapshot->project($this->native->find($userId), $this->time->getTime()));
	}
}
