<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Deck\Labels;

use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;

/** Binds new-object requests to their exact contents and native destination without storing approvals. */
final class LabelCreationPlan {
	/** @param PlanState $states Instance-secret fingerprints. */
	public function __construct(private PlanState $states) {
	}

	/** @param string $tool Tool name. @param string $uid Actor. @param array $plan Visible plan. @return array Signed plan. */
	public function sign(string $tool, string $uid, array $plan): array {
		return $plan + [PlanState::ARGUMENT => $this->states->of($tool, $uid, $plan)];
	}

	/** @param array $arguments Confirmed request. @param array $plan Fresh signed plan. @return void */
	public static function require(array $arguments, array $plan): void {
		PlanState::require($arguments);
		if (!PlanState::matches($plan[PlanState::ARGUMENT], $arguments)) {
			throw new PlanChanged($plan);
		}
	}
}
