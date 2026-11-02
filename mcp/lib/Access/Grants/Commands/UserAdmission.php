<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Grants\Commands;

use OCA\Mcp\Access\Policy\PolicyEventPayload;
use OCP\IUserManager;

/** External admission through the official user directory. */
final class UserAdmission {
	/**
	 * Composes this participant with the existing official services.
	 *
	 * @param IUserManager $users Official account directory.
	 */
	public function __construct(
		private IUserManager $users,
	) {}

	/**
	 * Refuses a user id absent from the official account directory.
	 *
	 * @param string $uid Nextcloud user id.
	 * @throws \InvalidArgumentException For an invalid or unknown account.
	 */
	public function assertUser(string $uid): void {
		if (!PolicyEventPayload::validUid($uid) || $this->users->get($uid) === null) {
			throw new \InvalidArgumentException('Unknown user');
		}
	}
}
