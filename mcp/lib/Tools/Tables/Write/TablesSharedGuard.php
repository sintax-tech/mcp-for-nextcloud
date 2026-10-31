<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tables\Write;

use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;

/**
 * The shared-resource gate of row writes: it runs after the native permission accepted the write, on a table owned by
 * somebody else, and asks for `confirm_shared: true` before anything is written. Unlike the calendar gate it never looks
 * up or returns the owner: the Tables tools show no other user. Whether the table is the caller's is part of the plan
 * state, so a change of owner makes a new plan.
 */
final class TablesSharedGuard {
	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * The answer asking for the shared confirmation, or null when the write may go on.
	 *
	 * @param RowWritePrepared $prepared the checked write
	 * @param array<string, mixed> $target the target as the plan shows it
	 * @return array{content: list<array{type:string, text:string}>}|null the request, or null
	 */
	public static function confirm(RowWritePrepared $prepared, array $target): ?array {
		if ($prepared->target->ownedByCaller || $prepared->request->confirmShared) {
			return null;
		}
		return ToolResult::json([
			'requiresConfirmation' => true,
			'scope' => 'shared',
			'target' => $target,
			'message' => RowWriteMessages::shared(),
			'advice' => CommonMessages::confirmAdvice(),
		]);
	}
}
