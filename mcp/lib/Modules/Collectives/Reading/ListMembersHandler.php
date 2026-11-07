<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Reading;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Collectives\Membership\MemberTarget;

/**
 * `collectives_list_members`: the direct members of the collective's team as Teams lists them to the actor (accounts
 * and groups, a group counting as one member), with level and status, sorted by level then name; whether the actor
 * may manage them (admin of the collective) and whether new members become invitations. Only reads.
 */
class ListMembersHandler {
	/** Tool name, part of the cursor signature. */
	private const TOOL = 'collectives_list_members';
	/** @var array<string, int> sort order of the levels, highest first */
	private const ORDER = ['owner' => 0, 'admin' => 1, 'moderator' => 2, 'member' => 3, 'none' => 4];

	/**
	 * @param MemberTarget $targets the collective's team, read now
	 * @param PageCursor $cursors listing cursors
	 */
	public function __construct(
		private MemberTarget $targets,
		private PageCursor $cursors,
	) {
	}

	/**
	 * @param string $uid authenticated actor
	 * @param array<string, mixed> $arguments validated collectives_list_members arguments
	 * @return array{collective:array{id:int, name:string}, you_can_manage:bool, invite_required:bool, members:list<array<string, mixed>>, next_cursor:?string}
	 *     one slice of the members
	 * @throws ToolFailure when the call is not admitted, the collective is unknown or the cursor is not valid
	 */
	public function handle(string $uid, array $arguments): array {
		$slice = $this->cursors->slice($arguments, $uid, self::TOOL);
		$context = $this->targets->context($uid, (int)$arguments['collective_id'], false);
		$members = $context['members'];
		usort($members, static fn (array $a, array $b): int => [self::ORDER[$a['level']] ?? 5, mb_strtolower($a['display_name']), $a['member_id']]
			<=> [self::ORDER[$b['level']] ?? 5, mb_strtolower($b['display_name']), $b['member_id']]);
		$page = $this->cursors->page($members, $slice, $arguments, $uid, self::TOOL);
		return ['collective' => $context['collective'], 'you_can_manage' => $context['actor_level'] >= MemberTarget::ADMIN,
			'invite_required' => $context['invite'], 'members' => $page['items'], 'next_cursor' => $page['next_cursor']];
	}
}
