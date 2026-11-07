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
 */
final class MemberProjection {
	/** @var array<int, string> level names by `Member::LEVEL_*` value, checked by {@see TeamsSurface} */
	public const LEVELS = [0 => 'none', 1 => 'member', 4 => 'moderator', 8 => 'admin', 9 => 'owner'];
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
		return [
			'member_id' => (string)$member->getId(),
			'type' => match ($type) {
				TeamsGateway::TYPE_USER => 'user',
				TeamsGateway::TYPE_GROUP => 'group',
				default => 'other',
			},
			'id' => (string)$member->getUserId(),
			'display_name' => (string)$member->getDisplayName(),
			'level' => self::LEVELS[(int)$member->getLevel()] ?? 'other',
			'status' => self::STATUSES[(string)$member->getStatus()] ?? 'other',
		];
	}
}
