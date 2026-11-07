<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Editing;

use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Collectives\Integration\TeamsGateway;
use OCA\Mcp\Modules\Collectives\Membership\MemberReceipt;
use OCA\Mcp\Modules\Collectives\Membership\MemberTarget;
use OCA\Mcp\Modules\Collectives\Writing\PlanConfirmation;
use Throwable;

/**
 * The confirmed `collectives_set_member_level`: the plan is rebuilt and must still be the approved one; Teams changes
 * the level once, unless the level is already the one asked for, which is not attempted. The receipt reads the team
 * again.
 */
class SetMemberLevelHandler {
	/**
	 * @param SetMemberLevelPlan $plans the plan, read now
	 * @param PlanConfirmation $confirmation plan states
	 * @param TeamsGateway $teams the collective's team
	 */
	public function __construct(
		private SetMemberLevelPlan $plans,
		private PlanConfirmation $confirmation,
		private TeamsGateway $teams,
	) {
	}

	/**
	 * @param string $uid authenticated actor
	 * @param array<string, mixed> $arguments validated collectives_set_member_level arguments
	 * @return array<string, mixed> the receipt
	 * @throws PlanChanged when the state changed since the plan: nothing was attempted
	 * @throws ToolFailure when the call is refused before the attempt
	 */
	public function handle(string $uid, array $arguments): array {
		$built = $this->plans->build($uid, $arguments);
		$this->confirmation->confirm($arguments, $built['plan']);
		$before = MemberReceipt::state($built['member']);
		if ($built['member']['level'] === $built['level']) {
			return ['status' => 'not_attempted', 'nativeCallAttempted' => false, 'before' => $before];
		}
		$error = null;
		try {
			$this->teams->level($uid, $built['member']['member_id'], SetMemberLevelPlan::LEVELS[$built['level']]);
		} catch (Throwable $e) {
			$error = $e;
		}
		try {
			$found = MemberTarget::find($this->teams->team($uid, $built['team_id'])['members'], $built['member']['type'], $built['member']['id']);
			$observed = MemberReceipt::state($found);
		} catch (ToolFailure) {
			$observed = false;
		}
		return MemberReceipt::of($before, ['status' => 'member', 'level' => $built['level']], $observed, $error);
	}
}
