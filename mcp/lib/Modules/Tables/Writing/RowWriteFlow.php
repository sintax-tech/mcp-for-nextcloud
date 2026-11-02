<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Writing;

use OCA\Mcp\Modules\Tables\Presentation\RowWriteMessages;

use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Tables\Presentation\TablesMessages;
use OCA\Mcp\Modules\Tables\TablesUnavailableException;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;

/**
 * The plan and the confirmed call of a row write, shared by the two row handlers:
 * - plan (no `confirm`): {@see RowWritePreflight} and {@see RowWritePlan}; nothing is written;
 * - confirmed: the whole preflight again, its plan state compared with the one given back (a different state gives the
 *   new plan through {@see PlanChanged}), the shared-table gate ({@see TablesSharedGuard}), then {@see RowWriter} once.
 * The answer of a write holds the target, the receipt of the cells sent and the fixed notes.
 */
class RowWriteFlow {
	/**
	 * Receives the preflight, the plan and the writer.
	 *
	 * @param RowWritePreflight $preflight the checks before any write
	 * @param RowWritePlan $plans the plan and its state
	 * @param RowWriter $writer the one call that writes
	 * @return void
	 */
	public function __construct(
		private RowWritePreflight $preflight,
		private RowWritePlan $plans,
		private RowWriter $writer,
	) {
	}

	/**
	 * The plan of a write.
	 *
	 * @param string $tool the tool
	 * @param array<string, mixed> $arguments validated arguments
	 * @param string $userId authenticated user UID
	 * @param bool $update whether the tool updates a row
	 * @return array<string, mixed> the plan
	 * @throws ArgumentValidationException for malformed arguments
	 * @throws ToolFailure when the write is refused; nothing was written
	 * @throws TablesUnavailableException when Tables is not available for the user
	 */
	public function preview(string $tool, array $arguments, string $userId, bool $update): array {
		$request = RowWriteRequest::of($arguments, $update);
		return $this->plans->plan($tool, $this->preflight->prepare($request, $userId), $userId);
	}

	/**
	 * The confirmed write.
	 *
	 * @param string $tool the tool
	 * @param array<string, mixed> $arguments validated arguments, with confirm and plan_state
	 * @param string $userId authenticated user UID
	 * @param bool $update whether the tool updates a row
	 * @return array{content: list<array{type:string, text:string}>} MCP result
	 * @throws ArgumentValidationException for malformed arguments or a missing plan_state
	 * @throws PlanChanged when the state differs from the approved plan; nothing was written
	 * @throws ToolFailure when the write is refused (nothing written) or not confirmed by Tables (uncertain)
	 * @throws TablesUnavailableException when Tables is not available for the user
	 */
	public function handle(string $tool, array $arguments, string $userId, bool $update): array {
		$request = RowWriteRequest::of($arguments, $update);
		PlanState::require($arguments);
		$prepared = $this->preflight->prepare($request, $userId);
		if (!PlanState::matches($this->plans->state($tool, $prepared, $userId), $arguments)) {
			throw new PlanChanged($this->plans->plan($tool, $prepared, $userId, true));
		}
		$target = $this->plans->target($prepared);
		$shared = TablesSharedGuard::confirm($prepared, $target);
		if ($shared !== null) {
			return $shared;
		}
		$receipt = $this->writer->write($prepared, $userId);
		$target['rowId'] = $receipt['rowId'];
		$notes = array_map(RowWritePlan::noteText(...), RowWritePlan::notes($prepared));
		if ($receipt['differs']) {
			$notes[] = RowWriteMessages::differs();
		}
		return ToolResult::json([
			'target' => $target,
			'cells' => $receipt['cells'],
		] + ($notes === [] ? [] : ['notes' => $notes]) + ['notice' => TablesMessages::UNTRUSTED_NOTICE]);
	}
}
