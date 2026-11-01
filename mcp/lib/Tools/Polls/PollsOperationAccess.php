<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Polls;

use OCA\Mcp\Platform\Compat\AppEnablement;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCP\App\IAppManager;
use OCP\IUserManager;

/** Independent operation admission; a results/links grant never needs or grants read. */
final class PollsOperationAccess {
	/**
	 * Checks the exact grant and the same per-user eligibility before native service resolution.
	 * @param string $uid authenticated actor
	 * @param string $operation exact catalog operation
	 * @param IAppManager $apps native app availability
	 * @param IUserManager $users native users
	 * @param GrantPolicy $policy MCP grants
	 * @param PollsSurface $surface common native surface
	 * @return string installed version
	 * @throws PollsRefusal for unavailable access
	 */
	public static function prepare(string $uid, string $operation, IAppManager $apps, IUserManager $users,
		GrantPolicy $policy, PollsSurface $surface): string {
		if (!in_array($operation, ['read', 'results', 'links'], true) || $uid === '') {
			throw PollsRefusal::unavailable();
		}
		$user = $users->get($uid);
		if (!$policy->granted($uid, 'polls', $operation) || $user === null || !AppEnablement::forUser($apps, 'polls', $user)) {
			throw PollsRefusal::unavailable();
		}
		$surface->assertSupported();
		return $apps->getAppVersion('polls');
	}
}
