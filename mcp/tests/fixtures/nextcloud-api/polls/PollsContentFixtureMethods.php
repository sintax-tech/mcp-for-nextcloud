<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/** Additional static source evidence for protected reads, without executing native code. */
final class PollsContentFixtureMethods {
	/** Relative source paths and exact methods, common to the four pinned releases. */
	public const MAP = [
		'Service/VoteService.php' => ['list'],
		'Service/ShareService.php' => ['list', 'sortByCategory', 'validateShareType'],
		'Db/Vote.php' => ['jsonSerialize'],
		'Db/EntityWithUser.php' => ['getCurrentUserIsEntityUser', 'getEntityAnonymization', 'getUser'],
		'Model/User/Anon.php' => ['getDisplayName'],
		'Model/UserBase.php' => ['getHashedUserId', 'getSafeType'],
		'Helper/Hash.php' => ['getUserIdHash'],
	];
	/**
	 * Names the native predicate in the fixed source release; it is not a reimplementation of expiration.
	 * @param string $version pinned source release
	 * @return string method present in that pin
	 */
	public static function expiration(string $version): string {
		return version_compare($version, '9.0.0', '<') ? 'getExpired' : 'getIsExpired';
	}

}
