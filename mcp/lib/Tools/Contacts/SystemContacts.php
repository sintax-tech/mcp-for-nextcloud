<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Contacts;

use OCP\Contacts\IManager;
use OCP\IAddressBook;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Read-only view of the system address book ("Accounts") through the official OCP contacts API.
 *
 * Source of the enumeration rule (Nextcloud 33.0.2): {@see IManager::search()} only turns the admin settings into
 * matching behaviour (`enumeration` off means exact matches, no `enumeration` and no `fullmatch` skips the
 * catalog). The group and phone restrictions are NOT applied by the manager: the core applies them in the sharee
 * plugins and in the DAV system address book, so this class applies the same rule from the same `core` settings.
 * Only a fixed set of public fields leaves this class, and the catalog is never listed without a search term.
 */
class SystemContacts {
    /** Shortest search term that reaches the catalog. */
    public const MIN_QUERY = 2;

    /** Most rows asked from the manager for one search. */
    public const CAP = 200;

    /** Backend class name of guest accounts, which are never shown in the catalog. */
    private const GUESTS = 'Guests';

    /**
     * Receives the core services that expose the catalog and the admin settings.
     *
     * @param IManager $contacts official contacts manager holding the registered address books
     * @param IAppConfig $config reads the admin sharing settings of the core app
     * @param IGroupManager $groups group membership used by the group restriction
     * @param IUserManager $users resolves the account behind each catalog entry
     * @return void
     */
    public function __construct(
        private IManager $contacts,
        private IAppConfig $config,
        private IGroupManager $groups,
        private IUserManager $users,
    ) {}

    /**
     * Name of the catalog when it exists and the admin settings expose it to the user.
     *
     * @param string $userId authenticated user UID
     * @return string|null display name, null when the catalog is absent or hidden by the admin
     */
    public function bookName(string $userId): ?string {
        $book = $this->book();
        if ($book === null || !$this->enumeration() && !$this->fullMatch()) {
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
        $enumeration = $this->enumeration();
        $properties = ['FN', 'EMAIL', 'NICKNAME', 'ORG'];
        if (!$enumeration) {
            // Core allows exact matches on the display name, the user ID and the e-mail, each by its own setting.
            $properties = ['FN'];
            if ($this->flag('shareapi_restrict_user_enumeration_full_match_email', true)) {
                $properties[] = 'EMAIL';
            }
            if ($this->flag('shareapi_restrict_user_enumeration_full_match_userid', true)) {
                $properties[] = 'UID';
            }
        }
        $rows = $this->contacts->search(
            $query,
            $properties,
            [
                'enumeration' => $enumeration,
                'fullmatch' => $this->fullMatch(),
                'strict_search' => !$enumeration,
                'limit' => self::CAP,
            ]
        );
        $items = [];
        foreach ($this->fromSystemBook($rows) as $row) {
            $item = $this->item($userId, $name, $row);
            if ($item !== null && $this->visible($userId, $item['accountId'])) {
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
        $enumeration = $this->enumeration();
        if (!$own && !$enumeration && !$this->flag('shareapi_restrict_user_enumeration_full_match_userid', true)) {
            return null;
        }
        $rows = $this->contacts->search(
            $uid,
            ['UID'],
            [
                'enumeration' => $own ? true : $enumeration,
                'fullmatch' => $own ? true : $this->fullMatch(),
                'strict_search' => true,
                'limit' => self::CAP,
            ]
        );
        foreach ($this->fromSystemBook($rows) as $row) {
            $item = $this->item($userId, $name, $row);
            if ($item !== null && $item['uri'] === $uri && $item['accountId'] === $uid && $this->visible($userId, $uid)) {
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

    /**
     * Converts a manager row into the public entry; every field is an explicit copy, nothing else leaves.
     *
     * @param string $userId authenticated user UID
     * @param string $bookName display name of the catalog
     * @param array<string, mixed> $row raw manager row
     * @return array<string, mixed>|null null for guests, unusable rows and accounts that no longer exist or are disabled
     */
    private function item(string $userId, string $bookName, array $row): ?array {
        $uid = isset($row['UID']) && is_string($row['UID']) ? $row['UID'] : '';
        $uri = isset($row['URI']) && is_string($row['URI']) ? $row['URI'] : '';
        if ($uid === '' || $uri === '' || str_starts_with($uri, self::GUESTS . ':')) {
            return null;
        }
        $account = $this->users->get($uid);
        if ($account === null || !$account->isEnabled() || $account->getBackendClassName() === self::GUESTS) {
            return null;
        }
        $organization = $this->text($row['ORG'] ?? '');
        return [
            'uid' => $uid,
            'accountId' => $uid,
            'name' => $this->text($row['FN'] ?? ''),
            'organization' => explode(';', $organization)[0],
            'title' => $this->text($row['TITLE'] ?? ''),
            'nickname' => $this->text($row['NICKNAME'] ?? ''),
            'emails' => $this->emails($row['EMAIL'] ?? []),
            'uri' => $uri,
            'addressbook' => ContactAccess::systemPath($userId),
            'addressBook' => ['name' => $bookName, 'system' => true],
            'readOnly' => true,
        ];
    }

    /**
     * Applies the admin group and phone restrictions, which the manager leaves to its callers.
     *
     * @param string $userId authenticated user UID
     * @param string $accountId UID of the account behind a catalog entry
     * @return bool whether the user may see that account
     */
    private function visible(string $userId, string $accountId): bool {
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

    /** @return IAddressBook|null the registered system address book */
    private function book(): ?IAddressBook {
        foreach ($this->contacts->getUserAddressBooks() as $book) {
            if ($book->isSystemAddressBook()) {
                return $book;
            }
        }
        return null;
    }

    /** @return bool whether the admin allows user name autocompletion (enumeration) */
    private function enumeration(): bool {
        return $this->flag('shareapi_allow_share_dialog_user_enumeration', true);
    }

    /** @return bool whether the admin allows exact matches while enumeration is off */
    private function fullMatch(): bool {
        return $this->flag('shareapi_restrict_user_enumeration_full_match', true);
    }

    /**
     * Reads a core yes/no setting the way the core does.
     *
     * @param string $key setting key of the core app
     * @param bool $default value when the admin never changed it
     * @return bool
     */
    private function flag(string $key, bool $default): bool {
        return $this->config->getValueString('core', $key, $default ? 'yes' : 'no') === 'yes';
    }

    /**
     * @param mixed $value raw property value, a string or a list
     * @return string first string value, empty when none
     */
    private function text(mixed $value): string {
        if (is_array($value)) {
            $value = $value[0] ?? '';
            if (is_array($value)) {
                $value = $value['value'] ?? '';
            }
        }
        return is_string($value) ? $value : '';
    }

    /**
     * @param mixed $value EMAIL property, a string, a list of strings or a list of typed values
     * @return list<string>
     */
    private function emails(mixed $value): array {
        $emails = [];
        foreach (is_array($value) ? $value : [$value] as $entry) {
            $email = is_array($entry) ? ($entry['value'] ?? '') : $entry;
            if (is_string($email) && $email !== '') {
                $emails[] = $email;
            }
        }
        return $emails;
    }
}
