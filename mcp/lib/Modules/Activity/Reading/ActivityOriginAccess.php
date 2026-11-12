<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Activity\Reading;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Guests\GuestRestrictions;
use OCA\Mcp\Platform\Compat\AppEnablement;
use OCP\App\IAppManager;
use OCP\IUserSession;

/** Independent source grants, Guests and per-user optional-app admission, before any object lookup. */
final class ActivityOriginAccess {
	/**
	 * @param GrantPolicy $policy current operation's grant snapshot
	 * @param GuestRestrictions $guests the same app boundary used by the tool registry
	 * @param IAppManager $apps native per-user enablement
	 * @param IUserSession $session request already bound to the authenticated actor
	 */
	public function __construct(private GrantPolicy $policy, private GuestRestrictions $guests, private IAppManager $apps, private IUserSession $session) {}
	/**
	 * @param string $actor authenticated account
	 * @param string $module source grant module
	 * @param string|null $app optional native app, null for core Files
	 * @return bool no source access is implied by activity.read
	 */
	public function allows(string $actor, string $module, ?string $app = null): bool {
		if (!$this->policy->granted($actor, $module, 'read') || !$this->guests->allowsApp($actor, $app ?? $module)) return false;
		$user = $this->session->getUser();
		if ($user === null || $user->getUID() !== $actor) return false;
		return $app === null || AppEnablement::forUser($this->apps, $app, $user);
	}
}
