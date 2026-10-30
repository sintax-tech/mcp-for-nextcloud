<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Polls\Create;

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Service\Compat\AppEnablement;
use OCA\Mcp\Tools\Polls\{PollsRefusal,PollsSurface};
use OCP\{IUserManager};
use OCP\App\IAppManager;

/** Independent write admission; no read/results/links prerequisite or implicit concession. */
class CreateAdmission {
	/**
	 * Receives existing app/user/grant sources.
	 * @param IAppManager $apps native per-user enablement/version
	 * @param IUserManager $users resolves the authenticated actor
	 * @param GrantPolicy $grants exact MCP policy
	 * @param PollsSurface $surface unchanged common native surface
	 * @return void
	 */
	public function __construct(private IAppManager $apps, private IUserManager $users,
		private GrantPolicy $grants, private PollsSurface $surface) {
	}
	/**
	 * Rechecks the exact create grant and eligibility, never changes policy.
	 * @param string $uid authenticated actor
	 * @return string actual installed version
	 * @throws PollsRefusal for unavailable admission
	 */
	public function check(string $uid): string {
		$user = $uid === '' ? null : $this->users->get($uid);
		if ($user === null || !$this->grants->granted($uid, 'polls', 'create') || !AppEnablement::forUser($this->apps, 'polls', $user)) {
			throw PollsRefusal::unavailable();
		}
		$this->surface->assertSupported();
		return $this->apps->getAppVersion('polls');
	}
}
