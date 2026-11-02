<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Contacts\Accounts;

use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;

/**
 * The admin user-enumeration settings of the core, read the way the core reads them, and the group and phone
 * restrictions the contacts manager leaves to its callers.
 */
final class AccountVisibility {
	/** Backend class name of guest accounts, which are never shown in the catalog. */
	public const GUESTS = 'Guests';

	/**
	 * @param IAppConfig $config reads the admin sharing settings of the core app
	 * @param IGroupManager $groups group membership used by the group restriction
	 * @param IUserManager $users resolves the authenticated account
	 */
	public function __construct(
		private IAppConfig $config,
		private IGroupManager $groups,
		private IUserManager $users,
	) {}

	/**
	 * Applies the admin group and phone restrictions, which the manager leaves to its callers.
	 *
	 * @param string $userId authenticated user UID
	 * @param string $accountId UID of the account behind a catalog entry
	 * @return bool whether the user may see that account
	 */
	public function visible(string $userId, string $accountId): bool {
		if ($accountId === $userId) {
			return true;
		}
		$viewer = $this->users->get($userId);
		if ($viewer === null || $viewer->getBackendClassName() === self::GUESTS) {
			return false;
		}
		if (!$this->enumeration()) {
			// Only exact full matches reach this point; the core allows them regardless of groups.
			return true;
		}
		if ($this->flag('shareapi_restrict_user_enumeration_to_group', false)) {
			return $this->sharesGroup($viewer, $accountId);
		}
		// Phone-book enumeration relies on a core service that is not public API: show only the user itself.
		return !$this->flag('shareapi_restrict_user_enumeration_to_phone', false);
	}

	/** @return bool whether the admin allows user name autocompletion (enumeration) */
	public function enumeration(): bool {
		return $this->flag('shareapi_allow_share_dialog_user_enumeration', true);
	}

	/** @return bool whether the admin allows exact matches while enumeration is off */
	public function fullMatch(): bool {
		return $this->flag('shareapi_restrict_user_enumeration_full_match', true);
	}

	/**
	 * Reads a core yes/no setting the way the core does.
	 *
	 * @param string $key setting key of the core app
	 * @param bool $default value when the admin never changed it
	 * @return bool
	 */
	public function flag(string $key, bool $default): bool {
		return $this->config->getValueString('core', $key, $default ? 'yes' : 'no') === 'yes';
	}

	/**
	 * Whether the account belongs to at least one group of the viewer.
	 *
	 * @param IUser $viewer authenticated user
	 * @param string $accountId UID of the other account
	 * @return bool
	 */
	private function sharesGroup(IUser $viewer, string $accountId): bool {
		foreach ($this->groups->getUserGroupIds($viewer) as $groupId) {
			if ($this->groups->isInGroup($accountId, $groupId)) {
				return true;
			}
		}
		return false;
	}
}
