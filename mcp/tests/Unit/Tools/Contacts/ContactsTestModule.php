<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <jhonatan@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Contacts;

use OCA\Mcp\Modules\Contacts\Accounts\AccountEntry;
use OCA\Mcp\Modules\Contacts\Accounts\AccountVisibility;
use OCA\Mcp\Modules\Contacts\Accounts\SystemContacts;
use OCP\Contacts\IManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Builds the Contacts services from the collaborators its unit tests used to hand the legacy constructors, wiring
 * each the way the app container autowires it.
 */
final class ContactsTestModule {
	/**
	 * @param IManager $contacts official contacts manager holding the registered address books
	 * @param IAppConfig $config reads the admin sharing settings of the core app
	 * @param IGroupManager $groups group membership used by the group restriction
	 * @param IUserManager $users resolves the accounts
	 * @return SystemContacts the catalog with its visibility rules and public entry
	 */
	public static function system(IManager $contacts, IAppConfig $config, IGroupManager $groups, IUserManager $users): SystemContacts {
		return new SystemContacts($contacts, new AccountVisibility($config, $groups, $users), new AccountEntry($users));
	}
}
