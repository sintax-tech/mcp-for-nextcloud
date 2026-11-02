<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Contacts\Accounts;

use OCA\Mcp\Modules\Contacts\Locating\ContactAccess;
use OCP\IUserManager;

/** The public entry of one catalog row: a fixed set of fields, each an explicit copy, and nothing else. */
final class AccountEntry {
	/** @param IUserManager $users resolves the account behind each catalog entry */
	public function __construct(private IUserManager $users) {}

	/**
	 * Converts a manager row into the public entry; every field is an explicit copy, nothing else leaves.
	 *
	 * @param string $userId authenticated user UID
	 * @param string $bookName display name of the catalog
	 * @param array<string, mixed> $row raw manager row
	 * @return array<string, mixed>|null null for guests, unusable rows and accounts that no longer exist or are disabled
	 */
	public function item(string $userId, string $bookName, array $row): ?array {
		$uid = isset($row['UID']) && is_string($row['UID']) ? $row['UID'] : '';
		$uri = isset($row['URI']) && is_string($row['URI']) ? $row['URI'] : '';
		if ($uid === '' || $uri === '' || str_starts_with($uri, AccountVisibility::GUESTS . ':')) {
			return null;
		}
		$account = $this->users->get($uid);
		if ($account === null || !$account->isEnabled() || $account->getBackendClassName() === AccountVisibility::GUESTS) {
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
