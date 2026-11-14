<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Routines\Preparation;

use OCP\Collaboration\Collaborators\ISearch;
use OCP\Share\IShare;

/**
 * The local account of one address, only when the people search has a single exact hit for that address.
 *
 * The search itself is a fragment lookup. A partial hit, a display name and a second exact account are not a UID.
 */
final class AccountEmailMatch {
	/** Matches read from one search; an exact address hit is among the first. */
	private const LIMIT = 25;

	/** @param ISearch $search the sharing search of the current session */
	public function __construct(private ISearch $search) {
	}

	/**
	 * @param string $email the address taken from the event, already one address
	 * @return string|null the only account id whose exact hit is that address
	 */
	public function soleUid(string $email): ?string {
		try {
			[$result] = $this->search->search($email, [IShare::TYPE_USER], false, self::LIMIT, 0);
		} catch (\Throwable) {
			return null;
		}
		$uids = [];
		foreach ((array)($result['exact']['users'] ?? []) as $match) {
			if (!is_array($match)) {
				continue;
			}
			$uid = (string)($match['value']['shareWith'] ?? '');
			$found = ExactEmail::normalize((string)($match['subline'] ?? ''));
			if ($uid !== '' && $found !== null && ExactEmail::same($found, $email) && !in_array($uid, $uids, true)) {
				$uids[] = $uid;
			}
		}
		return count($uids) === 1 ? $uids[0] : null;
	}
}
