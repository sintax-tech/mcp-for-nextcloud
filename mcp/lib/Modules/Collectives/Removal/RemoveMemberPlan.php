<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Removal;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Collectives\Membership\MemberTarget;
use OCA\Mcp\Modules\Collectives\Presentation\MembershipMessages;
use OCA\Mcp\Modules\Collectives\Writing\PlanConfirmation;

/**
 * The plan of `collectives_remove_member`, read now: the actor is an admin, the member exists and is neither the actor
 * (leaving is not offered here) nor the owner, the one owner Teams keeps and never removes (`MemberRemove.php` 35
 * `:63`). Removing a pending member cancels the invitation or the request.
 */
class RemoveMemberPlan {
	/** Tool the plan belongs to. */
	public const TOOL = 'collectives_remove_member';

	/**
	 * @param MemberTarget $targets the collective's team, read now
	 * @param PlanConfirmation $confirmation plan states
	 */
	public function __construct(
		private MemberTarget $targets,
		private PlanConfirmation $confirmation,
	) {
	}

	/**
	 * @param string $uid authenticated actor
	 * @param array<string, mixed> $arguments validated collectives_remove_member arguments
	 * @return array{plan:array<string, mixed>, team_id:string, member:array<string, mixed>} the signed plan and the member
	 * @throws ToolFailure when the call is refused
	 */
	public function build(string $uid, array $arguments): array {
		$context = $this->targets->context($uid, (int)$arguments['collective_id'], true);
		$member = $this->targets->member($context, (string)$arguments['member_id']);
		if (MemberTarget::isActor($member, $uid)) {
			throw new ToolFailure(MembershipMessages::notYourself());
		}
		if ($member['level'] === 'owner') {
			throw new ToolFailure(MembershipMessages::ownerUnchanged());
		}
		$notices = array_values(array_filter([
			$member['status'] === 'member' ? MembershipMessages::losesAccess() : MembershipMessages::cancelsPending(),
			$member['type'] === 'group' ? MembershipMessages::groupLosesAccess() : null,
		]));
		$plan = ['action' => self::TOOL, 'collective' => $context['collective'], 'member' => $member, 'notices' => $notices];
		$shown = ['collective_id' => $context['collective']['id'], 'team_id' => $context['team_id'], 'member' => $member];
		return ['plan' => $this->confirmation->sign(self::TOOL, $uid, $plan, $shown), 'team_id' => $context['team_id'], 'member' => $member];
	}
}
