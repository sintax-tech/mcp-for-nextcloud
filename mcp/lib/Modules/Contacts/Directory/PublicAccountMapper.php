<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Contacts\Directory;

use OCA\Mcp\Modules\Contacts\AddressBooks\ContactAccess;
use OCP\IUserManager;
class PublicAccountMapper
{
    private const GUESTS = 'Guests';
    public function __construct(private IUserManager $users)
    {
    }
    public function item(
        string $userId,
        string $bookName,
        array $row,
    ): ?array
    {
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
    public function text(mixed $value): string
    {
        if (is_array($value)) {
            $value = $value[0] ?? '';
            if (is_array($value)) {
                $value = $value['value'] ?? '';
            }
        }
        return is_string($value) ? $value : '';
    }
    public function emails(mixed $value): array
    {
        $emails = [];
        foreach (is_array($value) ? $value : [$value] as $entry) {
            $email = is_array($entry) ? $entry['value'] ?? '' : $entry;
            if (is_string($email) && $email !== '') {
                $emails[] = $email;
            }
        }
        return $emails;
    }
}
