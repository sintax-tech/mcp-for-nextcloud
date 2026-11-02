<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Editing;

use OCA\Mcp\Modules\Tables\Writing\RowWriteFlow;

/**
 * Backs `tables_update_row`: cells of one row changed through a table or view that shows it, by a user who may read and
 * update it, after a plan ({@see RowWriteFlow}).
 */
final class UpdateRowHandler {
	/** MCP tool name this handler serves. */
	public const TOOL = 'tables_update_row';

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
	 * The plan of the update.
	 *
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @param string $userId authenticated user UID
	 * @return array<string, mixed> the plan
	 * @throws \OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException for malformed arguments
	 * @throws \OCA\Mcp\Mcp\Tools\Results\ToolFailure when the update is refused
	 * @throws \OCA\Mcp\Modules\Tables\TablesUnavailableException when Tables is not available for the user
	 */
	public function preview(array $arguments, string $userId): array {
		return $this->flow->preview(self::TOOL, $arguments, $userId, true);
	}

	/**
	 * The confirmed update.
	 *
	 * @param array<string, mixed> $arguments validated tool arguments
	 * @param string $userId authenticated user UID
	 * @return array{content: list<array{type:string, text:string}>} MCP result
	 * @throws \OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException for malformed arguments
	 * @throws \OCA\Mcp\Mcp\Confirmation\PlanChanged when the state differs from the approved plan
	 * @throws \OCA\Mcp\Mcp\Tools\Results\ToolFailure when the update is refused or not confirmed by Tables
	 * @throws \OCA\Mcp\Modules\Tables\TablesUnavailableException when Tables is not available for the user
	 */
	public function handle(array $arguments, string $userId): array {
		return $this->flow->handle(self::TOOL, $arguments, $userId, true);
	}
}
