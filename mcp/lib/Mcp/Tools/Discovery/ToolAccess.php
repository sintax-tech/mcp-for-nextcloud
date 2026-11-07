<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Mcp\Tools\Discovery;

use InvalidArgumentException;
use OCA\Mcp\Platform\Compat\AppEnablement;
use OCA\Mcp\Access\Grants\GrantMatrix;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Guests\GuestRestrictions;
use OCA\Mcp\Access\Policy\GrantUnknown;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCP\App\IAppManager;
use OCP\IUserManager;

/**
 * Whether one user may see and call one tool now, for {@see \OCA\Mcp\Mcp\Tools\ToolRegistry}, in a fixed order:
 * the installation capabilities the tool requires, then the grant, Guests and the optional app.
 *
 * A tool whose capabilities are missing does not exist on this installation: it is hidden and uncallable whatever
 * the grant says, before the policy is read, as a legacy module hides a tool by leaving it out of its definitions.
 */
final class ToolAccess {
	/**
	 * @param GrantPolicy $policy grants of the user
	 * @param IAppManager $appManager app enablement and versions
	 * @param IUserManager $userManager resolves the user for the app gate
	 * @param GuestRestrictions|null $guests the apps Guests allows a guest; null only in tests without Guests
	 * @param CapabilityRegistry $capabilities installation capabilities of the request
	 */
	public function __construct(
		private readonly GrantPolicy $policy,
		private readonly IAppManager $appManager,
		private readonly IUserManager $userManager,
		private readonly ?GuestRestrictions $guests,
		private readonly CapabilityRegistry $capabilities,
	) {
	}

	/**
	 * The grant decides after the capabilities: the tool's operation, or any of the alternatives it lists in
	 * `grantAnyOf` when the kind of write depends on the arguments (files_share shares with a person under `share`
	 * and creates a public link under `link`). A tool listing alternatives must check the one each call needs itself,
	 * before planning and again before writing; the registry only lets the call in. With no alternative granted and
	 * one unknown, the answer is unknown (PolicyUnavailable, 503), never a denial.
	 *
	 * @param array{module:string, operation:string, grantAnyOf?:list<string>, app?:string, requires?:list<string>} $definition tool definition
	 * @param string $userId authenticated user
	 * @return bool whether the user may see and call the tool
	 * @throws GrantUnknown when the grant of the tool is unknown for this user
	 * @throws PolicyUnavailable when the policy or a role cannot be read
	 */
	public function allowed(array $definition, string $userId): bool {
		if (!$this->capabilities->supportsAll($definition['requires'] ?? [])) {
			return false;
		}
		try {
			// Three-valued "any of": a known Allow wins over an unknown alternative, whatever their order.
			$granted = $this->policy->grantedAny($userId, $definition['module'], $definition['grantAnyOf'] ?? [$definition['operation']]);
		} catch (InvalidArgumentException) {
			return false;
		}
		if (!$granted) {
			return false;
		}
		// A guest reaches a module only when Guests allows the app behind it, and the tool's own app when it names one.
		if ($this->guests !== null && (!$this->guests->allowsApp($userId, GrantMatrix::MODULE_APPS[$definition['module']] ?? '')
			|| !$this->guests->allowsApp($userId, $definition['app'] ?? ''))) {
			return false;
		}
		if (isset($definition['app'])) {
			$user = $this->userManager->get($userId);
			// An optional app in a version older than MCP supports counts as off (AppEnablement::MINIMUM_VERSIONS).
			return $user !== null && AppEnablement::forUser($this->appManager, $definition['app'], $user);
		}
		return true;
	}
}
