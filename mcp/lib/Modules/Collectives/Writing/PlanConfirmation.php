<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Writing;

use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Collectives\Presentation\CollectivesMessages;

/**
 * Ties a plan to the state it showed and the confirmed call to that plan. The plan carries the {@see PlanState} of
 * everything the write depends on; the confirmed call rebuilds the plan from a fresh read and only proceeds when the
 * value it was given back is still that one. Nothing is stored on the server.
 */
class PlanConfirmation {
	/** @param PlanState $states the instance's HMAC */
	public function __construct(private PlanState $states) {
	}

	/**
	 * @param string $tool tool the plan belongs to
	 * @param string $uid authenticated actor
	 * @param array<string, mixed> $plan the plan as the preview shows it, without its state
	 * @param array<string, mixed> $shown what the write depends on, every argument that changes the effect included
	 * @return array<string, mixed> the plan with its plan_state and the notice to show it first
	 */
	public function sign(string $tool, string $uid, array $plan, array $shown): array {
		return $plan + ['message' => CollectivesMessages::planNotice(), PlanState::ARGUMENT => $this->states->of($tool, $uid, $shown)];
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments of the confirmed call
	 * @param array<string, mixed> $plan the plan of the state found now, signed by {@see self::sign()}
	 * @throws PlanChanged when the call gives back another state: nothing is written and the answer is this plan
	 */
	public function confirm(array $arguments, array $plan): void {
		PlanState::require($arguments);
		if (!PlanState::matches((string)$plan[PlanState::ARGUMENT], $arguments)) {
			throw new PlanChanged($plan);
		}
	}
}
