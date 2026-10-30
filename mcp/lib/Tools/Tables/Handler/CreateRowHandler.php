<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tables\Handler;

use OCA\Mcp\Tools\Tables\Write\RowWriteFlow;

/**
 * Backs `tables_create_row`: one row created in a table or view the user may add rows to, after a plan
 * ({@see RowWriteFlow}).
 */
final class CreateRowHandler {
	/** MCP tool name this handler serves. */
	public const TOOL = 'tables_create_row';

	/**
	 * Receives the write flow.
	 *
	 * @param RowWriteFlow $flow the plan and the confirmed write
	 * @return void
	 */
	public function __construct(
		private RowWriteFlow $flow,
	) {
	}

	/**
	 * The plan of the creation.
	 *
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @param string $userId authenticated user UID
	 * @return array<string, mixed> the plan
	 * @throws \OCA\Mcp\Tools\ArgumentValidationException for malformed arguments
	 * @throws \OCA\Mcp\Tools\ToolFailure when the creation is refused
	 * @throws \OCA\Mcp\Tools\Tables\TablesUnavailableException when Tables is not available for the user
	 */
	public function preview(array $arguments, string $userId): array {
		return $this->flow->preview(self::TOOL, $arguments, $userId, false);
	}

	/**
	 * The confirmed creation.
	 *
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @param string $userId authenticated user UID
	 * @return array{content: list<array{type:string, text:string}>} MCP result
	 * @throws \OCA\Mcp\Tools\ArgumentValidationException for malformed arguments
	 * @throws \OCA\Mcp\Tools\PlanChanged when the state differs from the approved plan
	 * @throws \OCA\Mcp\Tools\ToolFailure when the creation is refused or not confirmed by Tables
	 * @throws \OCA\Mcp\Tools\Tables\TablesUnavailableException when Tables is not available for the user
	 */
	public function handle(array $arguments, string $userId): array {
		return $this->flow->handle(self::TOOL, $arguments, $userId, false);
	}
}
