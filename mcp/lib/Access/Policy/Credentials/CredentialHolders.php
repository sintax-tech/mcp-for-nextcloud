<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Policy\Credentials;

use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Db\AuthUsersMapper;
use OCA\Mcp\OAuth\Storage\OAuthStore;

/** Reads the live credential holders compared before and under the policy mutex. */
final class CredentialHolders {
	/**
	 * Composes this participant with the existing credential stores.
	 *
	 * @param OAuthStore $oauth Credential storage.
	 * @param AuthUsersMapper $authUsers Credential generations.
	 */
	public function __construct(
		private OAuthStore $oauth,
		private AuthUsersMapper $authUsers,
	) {}
	/**
	 * H of the holders rule (contracts §2.3): every owner of a live code or grant of the current epoch, with the
	 * highest generation among those rows and the owner's issue_seq and revoked_below, sorted by a digest of the uid.
	 * Read before the external phase and again under the mutex: any difference is an issuance (or a cut) in between.
	 *
	 * @param int $now unix time used to decide which credentials are still live
	 * @param string $epoch the auth epoch the holders are read in
	 * @return array<string, array{uid:string, gen:string, issue_seq:string, revoked_below:string}>
	 * @throws PolicyUnavailable when a holder's generations cannot be read
	 */
	public function holders(int $now, string $epoch): array {
		$holders = [];
		foreach ($this->oauth->liveHolders($now, $epoch) as $key => ['uid' => $uid, 'gen' => $gen]) {
			try {
				$holders[$key] = ['uid' => $uid, 'gen' => $gen] + $this->authUsers->generations($uid);
			} catch (\InvalidArgumentException|\UnexpectedValueException $e) {
				throw new PolicyUnavailable('Cannot read the credential holders', previous: $e);
			}
		}
		ksort($holders, SORT_STRING);
		return $holders;
	}
}
