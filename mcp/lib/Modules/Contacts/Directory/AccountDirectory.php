<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Contacts\Directory;

use OCP\Contacts\IManager;
use OCP\IAddressBook;
class AccountDirectory
{
    private const GUESTS = 'Guests';
    public function __construct(
        private IManager $contacts,
        private DirectoryVisibility $visibility,
        private PublicAccountMapper $mapper,
    )
    {
    }
    public const MIN_QUERY = 2;
    public const CAP = 200;
    public function bookName(string $userId): ?string
    {
        $book = $this->book();
        if ($book === null || !$this->visibility->enumeration() && !$this->visibility->fullMatch()) {
            return null;
        }
        return (string) $book->getDisplayName();
    }
    public function search(string $userId, string $query): array
    {
        $query = trim($query);
        $name = $this->bookName($userId);
        if ($name === null || mb_strlen($query) < self::MIN_QUERY) {
            return [];
        }
        $enumeration = $this->visibility->enumeration();
        $properties = [
            'FN',
            'EMAIL',
            'NICKNAME',
            'ORG',
        ];
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
            $item = $this->mapper->item($userId, $name, $row);
            if ($item !== null && $this->visibility->visible($userId, $item['accountId'])) {
                $items[$item['accountId']] = $item;
            }
        }
        $items = array_values($items);
        usort($items, static fn(array $a, array $b): int => strcmp(mb_strtolower($a['name']), mb_strtolower($b['name'])));
        return $items;
    }
    public function find(string $userId, string $uri): ?array
    {
        if (preg_match('/^(?!Guests:)[^:\/\\\\\\x00]+:([^\/\\\\\\x00]+)\.vcf$/', $uri, $match) !== 1) {
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
            $item = $this->mapper->item($userId, $name, $row);
            if ($item !== null && $item['uri'] === $uri && $item['accountId'] === $uid && $this->visibility->visible($userId, $uid)) {
                return $item;
            }
        }
        return null;
    }
    public function fromSystemBook(array $rows): array
    {
        $keys = [];
        foreach ($this->contacts->getUserAddressBooks() as $key => $book) {
            if ($book->isSystemAddressBook()) {
                $keys[(string) $book->getKey()] = true;
            }
        }
        return array_values(
            array_filter(
                $rows,
                static fn($row): bool => is_array($row) && isset($keys[(string) ($row['addressbook-key'] ?? '')])
            )
        );
    }
    public function book(): ?IAddressBook
    {
        foreach ($this->contacts->getUserAddressBooks() as $book) {
            if ($book->isSystemAddressBook()) {
                return $book;
            }
        }
        return null;
    }
}
