<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <jhonatan@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Contacts;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Contacts\Accounts\AccountEntry;
use OCA\Mcp\Modules\Contacts\Accounts\AccountVisibility;
use OCA\Mcp\Modules\Contacts\Accounts\SystemContacts;
use OCA\Mcp\Modules\Contacts\Backup\ContactBackup;
use OCA\Mcp\Modules\Contacts\Cards\ContactCard;
use OCA\Mcp\Modules\Contacts\ContactsModule;
use OCA\Mcp\Modules\Contacts\Creation\CreateContactHandler;
use OCA\Mcp\Modules\Contacts\Creation\CreateContactPreview;
use OCA\Mcp\Modules\Contacts\Deletion\DeleteContactHandler;
use OCA\Mcp\Modules\Contacts\Deletion\DeleteContactPreview;
use OCA\Mcp\Modules\Contacts\Editing\EditContactHandler;
use OCA\Mcp\Modules\Contacts\Editing\EditContactPreview;
use OCA\Mcp\Modules\Contacts\Locating\ContactAccess;
use OCA\Mcp\Modules\Contacts\Locating\ContactStore;
use OCA\Mcp\Modules\Contacts\Planning\AddressBookShareNotice;
use OCA\Mcp\Modules\Contacts\Planning\ContactChange;
use OCA\Mcp\Modules\Contacts\Reading\ListAddressBooksHandler;
use OCA\Mcp\Modules\Contacts\Reading\ReadContactHandler;
use OCA\Mcp\Modules\Contacts\Reading\SearchContactsHandler;
use OCA\Mcp\Modules\Contacts\Tools\CreateContact;
use OCA\Mcp\Modules\Contacts\Tools\DeleteContact;
use OCA\Mcp\Modules\Contacts\Tools\EditContact;
use OCA\Mcp\Modules\Contacts\Tools\ListAddressBooks;
use OCA\Mcp\Modules\Contacts\Tools\ReadContact;
use OCA\Mcp\Modules\Contacts\Tools\SearchContacts;
use OCA\Mcp\Modules\Contacts\Writing\ContactDav;
use OCP\Contacts\IManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;

/**
 * Builds the Contacts catalog module from the services its unit tests used to hand the legacy constructor, wiring
 * each tool the way the app container autowires it.
 */
final class ContactsTestModule {
	/**
	 * @param ContactAccess $access collection visibility, write access and contact reader
	 * @param ContactStore $store read-only storage port the search reads
	 * @param ContactDav $dav native CardDAV writer
	 * @param ContactCard $cards vCard parser and builder
	 * @param AddressBookShareNotice $notice notice of an address book owned by somebody else
	 * @param ContactBackup $backup mandatory copy before a deletion
	 * @param SystemContacts $system read-only account catalog
	 * @param PlanState|null $states HMAC of a creation plan; tests that create pass it
	 * @return ContactsModule the module of one request
	 */
	public static function build(
		ContactAccess $access,
		ContactStore $store,
		ContactDav $dav,
		ContactCard $cards,
		AddressBookShareNotice $notice,
		ContactBackup $backup,
		SystemContacts $system,
		?PlanState $states = null,
	): ContactsModule {
		$changes = new ContactChange($access, $cards, $notice, $backup);
		$createPlans = new CreateContactPreview($access, $cards, $notice, $states);
		return new ContactsModule(self::container([
			ListAddressBooks::class => new ListAddressBooks(new ListAddressBooksHandler($access, $system)),
			SearchContacts::class => new SearchContacts(new SearchContactsHandler($access, $store, $cards, $system)),
			ReadContact::class => new ReadContact(new ReadContactHandler($access, $cards, $system)),
			CreateContact::class => new CreateContact(new CreateContactHandler($createPlans, $access, $dav, $cards), $createPlans),
			EditContact::class => new EditContact(new EditContactHandler($changes, $access, $dav, $cards), new EditContactPreview($changes)),
			DeleteContact::class => new DeleteContact(new DeleteContactHandler($access, $dav, $backup, $changes), new DeleteContactPreview($changes)),
		]));
	}

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

	/**
	 * @param array<string, object> $services instances by id
	 * @return ContainerInterface a container that only answers those instances
	 */
	public static function container(array $services): ContainerInterface {
		return new class($services) implements ContainerInterface {
			/** @param array<string, object> $services instances by id */
			public function __construct(private array $services) {
			}

			/** {@inheritDoc} */
			public function get(string $id): mixed {
				return $this->services[$id] ?? throw new \LogicException("no test service $id");
			}

			/** {@inheritDoc} */
			public function has(string $id): bool {
				return isset($this->services[$id]);
			}
		};
	}
}
