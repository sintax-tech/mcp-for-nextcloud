<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Platform\Sharing;

use OCP\Collaboration\Collaborators\ISearch;
use OCP\Share\IShare;

/**
 * Whether the caller may name another account, decided the way the sharing dialog and people search decide it.
 *
 * The lookup is the very call of the people search tool (`ISearch::search($term, [IShare::TYPE_USER], false, …)`,
 * Modules/People/SearchPeopleHandler.php:71): the administrator's account enumeration and the restriction of sharing
 * to one's own groups apply, as the session user. Only an exact hit whose share target is the very account ID counts.
 * An account the search does not return is answered like one that does not exist: nothing here asks the user manager,
 * so no caller learns whether a hidden account exists.
 */
final class ShareableAccount {
	/** Matches read from one search; the exact hit of an account ID is among the first. */
	private const LIMIT = 25;

	/** @param ISearch $search the sharing search of the current session */
	public function __construct(private ISearch $search) {
	}

	/**
	 * @param string $uid account ID the caller wants to name
	 * @return string|null the display name the search shows for it, or null when the search does not return it
	 */
	public function displayName(string $uid): ?string {
		if ($uid === '') {
			return null;
		}
		try {
			[$result] = $this->search->search($uid, [IShare::TYPE_USER], false, self::LIMIT, 0);
		} catch (\Throwable) {
			return null;
		}
		foreach ((array)($result['exact']['users'] ?? []) as $match) {
			if (is_array($match) && (string)($match['value']['shareWith'] ?? '') === $uid) {
				return (string)($match['label'] ?? $uid);
			}
		}
		return null;
	}
}
