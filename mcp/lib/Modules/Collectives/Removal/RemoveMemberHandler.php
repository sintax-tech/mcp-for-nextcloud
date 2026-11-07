<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Removal;

use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Collectives\Integration\TeamsGateway;
use OCA\Mcp\Modules\Collectives\Membership\MemberReceipt;
use OCA\Mcp\Modules\Collectives\Membership\MemberTarget;
use OCA\Mcp\Modules\Collectives\Writing\PlanConfirmation;
use Throwable;

/**
 * The confirmed `collectives_remove_member`: the plan is rebuilt and must still be the approved one; Teams removes the
 * membership once, never a page, a file or the collective. The receipt reads the team again.
 */
class RemoveMemberHandler {
	/**
	 * @param RemoveMemberPlan $plans the plan, read now
	 * @param PlanConfirmation $confirmation plan states
	 * @param TeamsGateway $teams the collective's team
	 */
	public function __construct(
		private RemoveMemberPlan $plans,
		private PlanConfirmation $confirmation,
		private TeamsGateway $teams,
	) {
	}

	/**
	 * @param string $uid authenticated actor
	 * @param array<string, mixed> $arguments validated collectives_remove_member arguments
	 * @return array<string, mixed> the receipt
	 * @throws PlanChanged when the state changed since the plan: nothing was attempted
	 * @throws ToolFailure when the call is refused before the attempt
	 */
	public function handle(string $uid, array $arguments): array {
		$built = $this->plans->build($uid, $arguments);
		$this->confirmation->confirm($arguments, $built['plan']);
		$error = null;
		try {
			$this->teams->remove($uid, $built['member']['member_id']);
		} catch (Throwable $e) {
			$error = $e;
		}
		try {
			$observed = MemberReceipt::state(MemberTarget::find($this->teams->team($uid, $built['team_id'])['members'], $built['member']['type'], $built['member']['id']));
		} catch (ToolFailure) {
			$observed = false;
		}
		return MemberReceipt::of(MemberReceipt::state($built['member']), null, $observed, $error);
	}
}
