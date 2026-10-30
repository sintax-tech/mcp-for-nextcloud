<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Polls;

/** The pinned 8.6 expiration API was renamed in 9.x; both calls remain explicit and surface-checked. */
final class PollsExpiration {
	/**
	 * Calls the native predicate rather than duplicating its expiry rules, without arbitrary dynamic dispatch.
	 * @param object $poll authorized native Poll
	 * @param PollsSessionGuard $guard authenticated context guard
	 * @param object $session native global session
	 * @param string $uid actor
	 * @return bool native expiration state
	 * @throws PollsRefusal for an unsupported public signature
	 */
	public static function read(object $poll, PollsSessionGuard $guard, object $session, string $uid): bool {
		$surface = new PollsSurface();
		$guard->native($session, $uid);
		if (method_exists($poll, 'getIsExpired')) {
			$surface->assertMethod(PollsSurface::POLL, 'getIsExpired', [], 'bool');
			return $poll->getIsExpired();
		}
		$surface->assertMethod(PollsSurface::POLL, 'getExpired', [], 'bool');
		return $poll->getExpired();
	}
}
