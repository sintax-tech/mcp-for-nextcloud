<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Recipients;

use OCP\IUser;

/**
 * Native identity reads only; every unavailable or incomplete result must throw.
 */
interface RecipientDirectory {
	/**
	 * Read every ACL row after the caller has authorized the source.
	 *
	 * @param int $boardId Freshly authorized board.
	 * @return list<array{type:int,participant:string,edit:bool,share:bool,manage:bool}> Complete scalar ACLs.
	 */
	public function acls(int $boardId): array;

	/**
	 * Resolve an existing account without falling back to a display name.
	 *
	 * @param string $uid Native account identity.
	 * @return IUser Existing native user, including a resolved guest recipient.
	 */
	public function user(string $uid): IUser;

	/**
	 * Expand all current native members of an existing group.
	 *
	 * @param string $groupId Stable native group identity.
	 * @param RecipientExpansion $budget Operation-local time and work accounting, including native entries.
	 * @return iterable<IUser> Complete membership; errors must propagate.
	 */
	public function group(string $groupId, \OCA\Mcp\Modules\Deck\Recipients\RecipientExpansion $budget): iterable;

	/**
	 * Expand current local readers of an existing circle, refusing unsupported effective members.
	 *
	 * @param string $circleId Stable native circle identity.
	 * @param RecipientExpansion $budget Operation-local time and work accounting, including structural entries.
	 * @return iterable<IUser> Complete native inherited local membership.
	 */
	public function circle(string $circleId, \OCA\Mcp\Modules\Deck\Recipients\RecipientExpansion $budget): iterable;
}
