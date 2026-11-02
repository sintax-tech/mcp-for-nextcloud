<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Integration;

use OCP\IUserSession;
use OCA\Mcp\Modules\Polls\PollsRefusal;

/** The TablesSessionGuard pattern plus both identities used by the Polls queries and entity ACLs. */
final class PollsSessionGuard {
	/**
	 * Receives the real endpoint session.
	 * @param IUserSession $session authenticated Nextcloud session
	 * @return void
	 */
	public function __construct(private IUserSession $session) {
	}

	/**
	 * Refuses a background/empty/divergent entry point without changing identity.
	 * @param string $uid explicit authenticated actor
	 * @return void
	 * @throws PollsRefusal when the endpoint session is different
	 */
	public function caller(string $uid): void {
		if ($uid === '' || defined('OC_CONSOLE') || !$this->session->isLoggedIn() || $this->session->getUser()?->getUID() !== $uid) {
			throw PollsRefusal::unavailable();
		}
	}

	/**
	 * Checks both native identities and internal user type before each native operation.
	 * @param object $native the same UserSession used globally by Polls entities
	 * @param string $uid authenticated actor
	 * @return void
	 * @throws PollsRefusal for stale cache/token/foreign identity
	 */
	public function native(object $native, string $uid): void {
		$this->caller($uid);
		$id = $native->getCurrentUserId();
		$user = $native->getCurrentUser();
		$class = PollsSurface::USER;
		if (!$user instanceof $class || $id !== $uid || $user->getId() !== $uid
			|| !in_array($user->getType(), [constant($class . '::TYPE_USER'), constant($class . '::TYPE_ADMIN')], true)
			|| !$native->getIsLoggedIn() || $native->getShareToken() !== '') {
			throw PollsRefusal::unavailable();
		}
	}
}
