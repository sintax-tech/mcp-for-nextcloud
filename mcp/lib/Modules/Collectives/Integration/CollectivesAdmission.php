<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Integration;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Guests\GuestRestrictions;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Collectives\Presentation\CollectivesMessages;
use OCA\Mcp\Platform\Compat\AppEnablement;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * What every Collectives call proves before a native class is resolved, again on each stage of a confirmed write:
 * the exact grant, the app enabled for the user in a supported version, Guests, the Nextcloud session acting for the
 * same account (native listeners read the session, not the user id we pass), the user's Collectives folder already
 * configured (resolving it the first time writes a user setting, which a plan must not do) and the proven shape.
 */
class CollectivesAdmission {
	/**
	 * @param GrantPolicy $policy MCP grants, re-read on every stage
	 * @param IAppManager $apps per-user availability and version
	 * @param IUserManager $users the actor's account
	 * @param IUserSession $session the Nextcloud session native listeners read
	 * @param IConfig $config the user's Collectives folder setting, read only
	 * @param IAppConfig $appConfig the administrator's default Collectives folder, read only
	 * @param CollectivesSurface $surface the proven native shape
	 * @param GuestRestrictions|null $guests the apps a guest may use; null when the Guests port is not wired
	 */
	public function __construct(
		private GrantPolicy $policy,
		private IAppManager $apps,
		private IUserManager $users,
		private IUserSession $session,
		private IConfig $config,
		private IAppConfig $appConfig,
		private CollectivesSurface $surface,
		private ?GuestRestrictions $guests = null,
	) {
	}

	/**
	 * @param string $uid authenticated actor
	 * @param string $operation exact grant operation of the call
	 * @throws ToolFailure when any condition fails; the answer never says which, so nothing about the account leaks
	 */
	public function admit(string $uid, string $operation): void {
		$user = $uid === '' ? null : $this->users->get($uid);
		if ($user === null || !$this->policy->granted($uid, 'collectives', $operation)
			|| !AppEnablement::forUser($this->apps, 'collectives', $user)
			|| ($this->guests !== null && !$this->guests->allowsApp($uid, 'collectives'))
			|| $this->session->getUser()?->getUID() !== $uid || !$this->surface->supported()) {
			throw new ToolFailure(CollectivesMessages::unavailable());
		}
		$default = $this->appConfig->getValueString('collectives', 'default_user_folder', '');
		if ($this->config->getUserValue($uid, 'collectives', 'user_folder', $default) === '') {
			throw new ToolFailure(CollectivesMessages::folderNotReady());
		}
	}
}
