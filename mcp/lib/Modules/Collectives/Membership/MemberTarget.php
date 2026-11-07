<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Membership;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Collectives\Integration\TeamsAdmission;
use OCA\Mcp\Modules\Collectives\Integration\TeamsGateway;
use OCA\Mcp\Modules\Collectives\Presentation\MembershipMessages;

/**
 * The collective, its team and its members as the actor sees them now, after the admission of every member tool. To
 * manage members the actor must be an admin of the collective, the rule of the Collectives interface, which shows
 * "Manage members" only from that level (`src/stores/collectives.js` 4.7.1 `:124-126`); Teams checks its own rules
 * again on every write.
 */
class MemberTarget {
	/** `Member::LEVEL_ADMIN`. */
	public const ADMIN = 8;

	/**
	 * @param TeamsAdmission $admission what every member call proves first
	 * @param TeamsGateway $teams the collective's team
	 */
	public function __construct(
		private TeamsAdmission $admission,
		private TeamsGateway $teams,
	) {
	}

	/**
	 * @param string $uid authenticated actor
	 * @param int $cid collective id
	 * @param bool $manage whether the call changes members, which needs an admin
	 * @return array{collective:array{id:int, name:string}, team_id:string, actor_level:int, fixed:bool, invite:bool, members:list<array<string, mixed>>}
	 *     what the actor sees now
	 * @throws ToolFailure when the call is not admitted, the collective is unknown or the actor may not manage members
	 */
	public function context(string $uid, int $cid, bool $manage): array {
		$this->admission->admit($uid, TeamsAdmission::MEMBERS);
		$collective = $this->teams->collective($cid, $uid);
		if ($manage && $collective['actor_level'] < self::ADMIN) {
			throw new ToolFailure(MembershipMessages::adminsOnly());
		}
		$team = $this->teams->team($uid, $collective['team_id']);
		return ['collective' => ['id' => $collective['id'], 'name' => $collective['name']], 'team_id' => $collective['team_id'],
			'actor_level' => $collective['actor_level'], 'fixed' => $team['fixed'], 'invite' => $team['invite'], 'members' => $team['members']];
	}

	/**
	 * @param array{members:list<array<string, mixed>>} $context what {@see self::context()} returned
	 * @param string $memberId member id as Teams gives it
	 * @return array<string, mixed> that member
	 * @throws ToolFailure when the team has no such member
	 */
	public function member(array $context, string $memberId): array {
		foreach ($context['members'] as $member) {
			if ($member['member_id'] === $memberId) {
				return $member;
			}
		}
		throw new ToolFailure(MembershipMessages::unknownMember());
	}

	/**
	 * @param list<array<string, mixed>> $members members of a team
	 * @param string $type user or group
	 * @param string $id account or group id
	 * @return array<string, mixed>|null the membership of that account or group, null when it has none
	 */
	public static function find(array $members, string $type, string $id): ?array {
		foreach ($members as $member) {
			if ($member['type'] === $type && $member['id'] === $id) {
				return $member;
			}
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $member a member
	 * @param string $uid authenticated actor
	 * @return bool whether that member is the actor's own account
	 */
	public static function isActor(array $member, string $uid): bool {
		return $member['type'] === 'user' && $member['id'] === $uid;
	}
}
