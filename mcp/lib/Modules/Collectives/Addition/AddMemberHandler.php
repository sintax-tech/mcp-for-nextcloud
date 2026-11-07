<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Addition;

use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Collectives\Integration\TeamsAdmission;
use OCA\Mcp\Modules\Collectives\Integration\TeamsGateway;
use OCA\Mcp\Modules\Collectives\Membership\MemberReceipt;
use OCA\Mcp\Modules\Collectives\Membership\MemberTarget;
use OCA\Mcp\Modules\Collectives\Writing\PlanConfirmation;
use Throwable;

/**
 * The confirmed `collectives_add_member`: the plan is rebuilt from a fresh read (the team's settings included) and
 * must still be the approved one; Teams then adds the account or group once. The receipt reads the team again, and
 * says apart whether Teams recorded the account's internal identity on the way: read before and after for an
 * account, unknown for a group, whose hidden internal team cannot be read without a privileged session.
 */
class AddMemberHandler {
	/**
	 * @param AddMemberPlan $plans the plan, read now
	 * @param PlanConfirmation $confirmation plan states
	 * @param TeamsGateway $teams the collective's team
	 * @param TeamsAdmission $identities whether Teams knows an account
	 */
	public function __construct(
		private AddMemberPlan $plans,
		private PlanConfirmation $confirmation,
		private TeamsGateway $teams,
		private TeamsAdmission $identities,
	) {
	}

	/**
	 * @param string $uid authenticated actor
	 * @param array<string, mixed> $arguments validated collectives_add_member arguments
	 * @return array<string, mixed> the receipt
	 * @throws PlanChanged when the state changed since the plan: nothing was attempted
	 * @throws ToolFailure when the call is refused before the attempt
	 */
	public function handle(string $uid, array $arguments): array {
		$built = $this->plans->build($uid, $arguments);
		$this->confirmation->confirm($arguments, $built['plan']);
		$user = $built['type'] === 'user';
		$known = $user ? $this->identities->identityRecorded($built['id']) : null;
		$error = null;
		try {
			$this->teams->add($uid, $built['team_id'], $user ? TeamsGateway::TYPE_USER : TeamsGateway::TYPE_GROUP, $built['id']);
		} catch (Throwable $e) {
			$error = $e;
		}
		$auxiliary = !$user ? 'unknown' : ($known ? 'none' : ($this->identities->identityRecorded($built['id']) ? 'identity_recorded' : 'none'));
		try {
			$observed = MemberReceipt::state(MemberTarget::find($this->teams->team($uid, $built['team_id'], true)['members'], $built['type'], $built['id']));
		} catch (ToolFailure) {
			$observed = false;
		}
		return MemberReceipt::of(MemberReceipt::state($built['before']), ['status' => $built['outcome'], 'level' => $built['outcome'] === 'member' ? 'member' : 'none'],
			$observed, $error, ['auxiliary_effects' => $auxiliary]);
	}
}
