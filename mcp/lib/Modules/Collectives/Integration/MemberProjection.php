<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Integration;

/**
 * A Teams member as this module shows it: the id Teams uses for the membership, the kind and id of the account or
 * group, its display name, its level by name (`none` for a pending invitation or request, which Teams keeps at level
 * 0) and its status. Instances, single ids and federation data never leave Integration.
 *
 * Teams stores a group as a membership of its system team, never as a group: the membership has the team type and the
 * group's display name, and is based on the team `group:<id>` of source group (`FederatedUserService::getGroupCircle`
 * and `FederatedUser::importFromCircle`, 33 to 35; members are listed with that team joined). It is shown as the group
 * by that id, which is what the person names and what the tools compare.
 */
final class MemberProjection {
	/** @var array<int, string> level names by `Member::LEVEL_*` value, checked by {@see TeamsSurface} */
	public const LEVELS = [0 => 'none', 1 => 'member', 4 => 'moderator', 8 => 'admin', 9 => 'owner'];
	/** Name prefix of a group's system team (`getGroupCircle`). */
	private const GROUP_TEAM = 'group:';
	/** @var array<string, string> status names by `Member::STATUS_*` value */
	public const STATUSES = ['Member' => 'member', 'Invited' => 'invited', 'Requesting' => 'requesting', 'Blocked' => 'blocked'];

	/** Static helper only; never instantiated. */
	private function __construct() {
	}

	/**
	 * @param object $member native Member
	 * @return array{member_id:string, type:string, id:string, display_name:string, level:string, status:string} the member
	 */
	public static function of(object $member): array {
		$type = (int)$member->getUserType();
		$group = self::group($member);
		return [
			'member_id' => (string)$member->getId(),
			'type' => match (true) {
				$group !== null, $type === TeamsGateway::TYPE_GROUP => 'group',
				$type === TeamsGateway::TYPE_USER => 'user',
				default => 'other',
			},
			'id' => $group ?? (string)$member->getUserId(),
			'display_name' => (string)$member->getDisplayName(),
			'level' => self::LEVELS[(int)$member->getLevel()] ?? 'other',
			'status' => self::STATUSES[(string)$member->getStatus()] ?? 'other',
		];
	}

	/**
	 * @param object $member native Member
	 * @return string|null the group the membership stands for, null when it is not a group's system team
	 */
	private static function group(object $member): ?string {
		if ((int)$member->getUserType() !== TeamsGateway::TYPE_TEAM || !$member->hasBasedOn()) {
			return null;
		}
		$team = $member->getBasedOn();
		$name = is_object($team) ? (string)$team->getName() : '';
		return is_object($team) && (int)$team->getSource() === TeamsGateway::TYPE_GROUP && str_starts_with($name, self::GROUP_TEAM)
			? substr($name, strlen(self::GROUP_TEAM)) : null;
	}
}
