<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Service;

use OCP\App\IAppManager;
use OCP\IUserManager;

/** Effective Files copies: suspending them requires positively established native versioning enablement. */
final class BackupCopies {
	/**
	 * @param BackupLocation $location live backup document
	 * @param IAppManager $apps official app enablement, including group restrictions
	 * @param IUserManager $users local acting-user resolution
	 */
	public function __construct(private BackupLocation $location, private IAppManager $apps, private IUserManager $users) {
	}

	/**
	 * @param string|null $uid actor, or null for the global admin admission check
	 * @return bool whether the Versions app is positively enabled; no per-file version guarantee
	 */
	public function versionsAvailable(?string $uid = null): bool {
		try {
			if ($uid === null) {
				return Compat\AppEnablement::forAnyone($this->apps, 'files_versions');
			}
			$user = $this->users->get($uid);
			return $user !== null && $this->apps->isEnabledForUser('files_versions', $user);
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * @param string $uid user about to write
	 * @return bool true on any uncertainty; the historical Versions precondition remains independent
	 */
	public function required(string $uid): bool {
		try {
			return $this->location->current()['copies'] || !$this->versionsAvailable($uid);
		} catch (\Throwable) {
			return true;
		}
	}
}
