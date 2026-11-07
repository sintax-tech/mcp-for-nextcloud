<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Addition;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Collectives\Integration\TeamsAdmission;
use OCA\Mcp\Modules\Collectives\Membership\MemberTarget;
use OCA\Mcp\Modules\Collectives\Presentation\MembershipMessages;
use OCA\Mcp\Modules\Collectives\Writing\PlanConfirmation;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * The plan of `collectives_add_member`, read now and only read: the actor is an admin of the collective, the account
 * or group exists on this server and is not the actor, and what Teams will make of it (`SingleMemberAdd`, 35
 * `:264-298`): a member, an invitation in a team that requires them, or an accepted request. A group in such a team,
 * an existing member or invitation and a blocked account are refused. The person or group is not resolved in Teams
 * here; that may record internal identities, which the plan declares and only the confirmed call does.
 */
class AddMemberPlan {
	/** Tool the plan belongs to. */
	public const TOOL = 'collectives_add_member';

	/**
	 * @param MemberTarget $targets the collective's team, read now
	 * @param TeamsAdmission $identities whether Teams knows an account already
	 * @param IUserManager $users accounts of this server
	 * @param IGroupManager $groups groups of this server
	 * @param PlanConfirmation $confirmation plan states
	 */
	public function __construct(
		private MemberTarget $targets,
		private TeamsAdmission $identities,
		private IUserManager $users,
		private IGroupManager $groups,
		private PlanConfirmation $confirmation,
	) {
	}

	/**
	 * @param string $uid authenticated actor
	 * @param array<string, mixed> $arguments validated collectives_add_member arguments
	 * @return array{plan:array<string, mixed>, team_id:string, type:string, id:string, before:array<string, mixed>|null, outcome:string}
	 *     the signed plan, the target and what is expected
	 * @throws ToolFailure when the call is refused
	 */
	public function build(string $uid, array $arguments): array {
		$context = $this->targets->context($uid, (int)$arguments['collective_id'], true);
		if ($context['fixed']) {
			throw new ToolFailure(MembershipMessages::fixedTeam());
		}
		$type = (string)$arguments['type'];
		$id = (string)$arguments['id'];
		$group = $type === 'group' ? $this->groups->get($id) : null;
		$user = $type === 'user' ? $this->users->get($id) : null;
		if ($group === null && $user === null) {
			throw new ToolFailure(MembershipMessages::unknownTarget());
		}
		if ($type === 'user' && $id === $uid) {
			throw new ToolFailure(MembershipMessages::notYourself());
		}
		$before = MemberTarget::find($context['members'], $type, $id);
		$outcome = match ($before['status'] ?? null) {
			'member' => throw new ToolFailure(MembershipMessages::alreadyMember()),
			'invited' => throw new ToolFailure(MembershipMessages::alreadyInvited()),
			'blocked' => throw new ToolFailure(MembershipMessages::blocked()),
			'requesting' => 'member',
			default => $context['invite'] ? ($group !== null ? throw new ToolFailure(MembershipMessages::groupInvite()) : 'invited') : 'member',
		};
		$notices = array_values(array_filter([
			$before !== null ? MembershipMessages::acceptsRequest() : ($outcome === 'invited' ? MembershipMessages::becomesInvitation() : MembershipMessages::joinsDirectly()),
			$group !== null ? MembershipMessages::groupAccess($group->count()) : null,
			MembershipMessages::noDirectEmail(),
			$user !== null && !$this->identities->identityRecorded($id) ? MembershipMessages::identityMayBeRecorded() : null,
			$group !== null ? MembershipMessages::groupMayBeRecorded() : null,
		]));
		$display = $group !== null ? $group->getDisplayName() : $user->getDisplayName();
		$plan = ['action' => self::TOOL, 'collective' => $context['collective'], 'target' => ['type' => $type, 'id' => $id, 'display_name' => $display],
			'outcome' => $outcome, 'notices' => $notices];
		$shown = ['collective_id' => $context['collective']['id'], 'team_id' => $context['team_id'], 'type' => $type, 'id' => $id,
			'outcome' => $outcome, 'before' => $before['status'] ?? null];
		return ['plan' => $this->confirmation->sign(self::TOOL, $uid, $plan, $shown), 'team_id' => $context['team_id'],
			'type' => $type, 'id' => $id, 'before' => $before, 'outcome' => $outcome];
	}
}
