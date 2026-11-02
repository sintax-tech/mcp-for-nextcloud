<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Editing;

use OCA\Mcp\Mcp\Tools\Results\ToolResult;
use OCA\Mcp\L10n\Translator;

/** Implements the explicit module-level shared confirmation after native EDIT, never through an unrelated Files guard. */
final class EditSharedGuard {
	/** Refuses the sink unless shared impact was explicitly confirmed under the matched plan.
	 * @param array<string,mixed> $plan Current authenticated plan.
	 * @param array<string,mixed> $args Submitted confirmation.
	 * @return array<string,mixed>|null Confirmation response or permission to continue.
	 */
	public static function confirm(array $plan, array $args): ?array {
		if (!$plan['shared'] || ($args['confirm_shared'] ?? null) === true) { return null; }
		return ToolResult::json(['requiresConfirmation' => true, 'scope' => 'shared', 'target' => $plan['target'], 'plan_state' => $plan['plan_state'],
			'message' => Translator::t('This edit affects a shared form and renews its native editing lock. Review the plan and confirm_shared before confirming.')]);
	}
}
