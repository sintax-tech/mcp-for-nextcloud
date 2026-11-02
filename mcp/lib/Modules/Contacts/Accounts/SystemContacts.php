<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Contacts\Accounts;

use OCP\Contacts\IManager;
use OCP\IAddressBook;

/**
 * Read-only view of the system address book ("Accounts") through the official OCP contacts API.
 *
 * Source of the enumeration rule (Nextcloud 33.0.2): {@see IManager::search()} only turns the admin settings into
 * matching behaviour (`enumeration` off means exact matches, no `enumeration` and no `fullmatch` skips the
 * catalog). The group and phone restrictions are NOT applied by the manager: the core applies them in the sharee
 * plugins and in the DAV system address book, so {@see AccountVisibility} applies the same rule from the same `core`
 * settings. Only the fields of {@see AccountEntry} leave this class, and the catalog is never listed without a search term.
 */
class SystemContacts {
	/** Shortest search term that reaches the catalog. */
	public const MIN_QUERY = 2;

	/** Most rows asked from the manager for one search. */
	public const CAP = 200;

	/**
	 * Receives the core service that exposes the catalog, the admin settings and the public entry.
	 *
	 * @param IManager $contacts official contacts manager holding the registered address books
	 * @param AccountVisibility $visibility the admin enumeration settings and restrictions
	 * @param AccountEntry $entries the public entry of one catalog row
	 * @return void
	 */
	public function __construct(
		private IManager $contacts,
		private AccountVisibility $visibility,
		private AccountEntry $entries,
	) {}

	/**
	 * Name of the catalog when it exists and the admin settings expose it to the user.
	 *
	 * @param string $userId authenticated user UID
	 * @return string|null display name, null when the catalog is absent or hidden by the admin
	 */
	public function bookName(string $userId): ?string {
		$book = $this->book();
		if ($book === null || !$this->visibility->enumeration() && !$this->visibility->fullMatch()) {
			return null;
		}
		return (string) $book->getDisplayName();
	}

	/**
	 * Searches accounts by a non-trivial term, honouring the admin enumeration settings.
	 *
	 * @param string $userId authenticated user UID
	 * @param string $query search text; shorter than {@see MIN_QUERY} characters reaches nothing
	 * @return list<array<string, mixed>> public account entries sorted by name
	 */
	public function search(string $userId, string $query): array {
		$query = trim($query);
		$name = $this->bookName($userId);
		if ($name === null || mb_strlen($query) < self::MIN_QUERY) {
			return [];
		}
		$enumeration = $this->visibility->enumeration();
		$properties = ['FN', 'EMAIL', 'NICKNAME', 'ORG'];
		if (!$enumeration) {
			// Core allows exact matches on the display name, the user ID and the e-mail, each by its own setting.
			$properties = ['FN'];
			if ($this->visibility->flag('shareapi_restrict_user_enumeration_full_match_email', true)) {
				$properties[] = 'EMAIL';
			}
			if ($this->visibility->flag('shareapi_restrict_user_enumeration_full_match_userid', true)) {
				$properties[] = 'UID';
			}
		}
		$rows = $this->contacts->search(
			$query,
			$properties,
			[
				'enumeration' => $enumeration,
				'fullmatch' => $this->visibility->fullMatch(),
				'strict_search' => !$enumeration,
				'limit' => self::CAP,
			]
		);
		$items = [];
		foreach ($this->fromSystemBook($rows) as $row) {
			$item = $this->entries->item($userId, $name, $row);
			if ($item !== null && $this->visibility->visible($userId, $item['accountId'])) {
				$items[$item['accountId']] = $item;
			}
		}
		$items = array_values($items);
		usort($items, static fn (array $a, array $b): int => strcmp(mb_strtolower($a['name']), mb_strtolower($b['name'])));
		return $items;
	}

	/**
	 * Reads one catalog entry by the URI a search returned, through an exact UID search under the same rules.
	 *
	 * @param string $userId authenticated user UID
	 * @param string $uri contact object URI such as "Database:pedro.vcf"
	 * @return array<string, mixed>|null public account entry, null when absent, malformed or not visible
	 */
	public function find(string $userId, string $uri): ?array {
		if (preg_match('/^(?!Guests:)[^:\/\\\\\x00]+:([^\/\\\\\x00]+)\.vcf$/', $uri, $match) !== 1) {
			return null;
		}
		$uid = $match[1];
		$own = $uid === $userId;
		$name = $this->bookName($userId);
		if ($name === null && $own) {
			$name = (string) $this->book()?->getDisplayName();
		}
		if ($name === null || $name === '') {
			return null;
		}
		$enumeration = $this->visibility->enumeration();
		if (!$own && !$enumeration && !$this->visibility->flag('shareapi_restrict_user_enumeration_full_match_userid', true)) {
			return null;
		}
		$rows = $this->contacts->search(
			$uid,
			['UID'],
			[
				'enumeration' => $own ? true : $enumeration,
				'fullmatch' => $own ? true : $this->visibility->fullMatch(),
				'strict_search' => true,
				'limit' => self::CAP,
			]
		);
		foreach ($this->fromSystemBook($rows) as $row) {
			$item = $this->entries->item($userId, $name, $row);
			if ($item !== null && $item['uri'] === $uri && $item['accountId'] === $uid && $this->visibility->visible($userId, $uid)) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * Keeps only the rows that come from the system address book.
	 *
	 * @param array<int, mixed> $rows rows returned by the manager for every registered address book
	 * @return list<array<string, mixed>>
	 */
	private function fromSystemBook(array $rows): array {
		$keys = [];
		foreach ($this->contacts->getUserAddressBooks() as $key => $book) {
			if ($book->isSystemAddressBook()) {
				$keys[(string) $book->getKey()] = true;
			}
		}
		return array_values(
			array_filter(
				$rows,
				static fn ($row): bool => is_array($row) && isset($keys[(string) ($row['addressbook-key'] ?? '')])
			)
		);
	}

	/** @return IAddressBook|null the registered system address book */
	private function book(): ?IAddressBook {
		foreach ($this->contacts->getUserAddressBooks() as $book) {
			if ($book->isSystemAddressBook()) {
				return $book;
			}
		}
		return null;
	}
}
