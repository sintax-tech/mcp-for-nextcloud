<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Editing;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Collectives\Membership\MemberTarget;
use OCA\Mcp\Modules\Collectives\Presentation\MembershipMessages;
use OCA\Mcp\Modules\Collectives\Writing\PlanConfirmation;

/**
 * The plan of `collectives_set_member_level`, read now: the actor is an admin, the member exists, is not the actor,
 * is not the owner (in Teams the owner level is a transfer of ownership, `MemberLevel.php` 35 `:85-88`, decision D3)
 * and has accepted its membership (Teams needs a member, `:140`). The level asked for is member, moderator or admin;
 * the same level gives a plan where nothing changes.
 */
class SetMemberLevelPlan {
	/** Tool the plan belongs to. */
	public const TOOL = 'collectives_set_member_level';
	/** @var array<string, int> levels that can be asked for, by name, as `Member::LEVEL_*` */
	public const LEVELS = ['member' => 1, 'moderator' => 4, 'admin' => 8];

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
	 * @param array<string, mixed> $arguments validated collectives_set_member_level arguments
	 * @return array{plan:array<string, mixed>, team_id:string, member:array<string, mixed>, level:string} the signed plan, the member and the level
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
		if ($member['status'] !== 'member') {
			throw new ToolFailure(MembershipMessages::pendingLevel());
		}
		$level = (string)$arguments['level'];
		$same = $member['level'] === $level;
		$notices = array_values(array_filter([
			$same ? MembershipMessages::levelUnchanged() : MembershipMessages::levelChange($member['level'], $level),
			$level === 'admin' && !$same ? MembershipMessages::adminPowers() : null,
		]));
		$plan = ['action' => self::TOOL, 'collective' => $context['collective'], 'member' => $member, 'level' => $level,
			'changes' => !$same, 'notices' => $notices];
		$shown = ['collective_id' => $context['collective']['id'], 'team_id' => $context['team_id'], 'member' => $member, 'level' => $level];
		return ['plan' => $this->confirmation->sign(self::TOOL, $uid, $plan, $shown), 'team_id' => $context['team_id'], 'member' => $member, 'level' => $level];
	}
}
