<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reading;

use OCP\App\IAppManager;
use OCP\FullTextSearch\IFullTextSearchManager;
use OCP\IUserManager;

/** Checks per-user app availability and whether the Files full-text provider is indexed. */
final class FullTextAvailability {
	/**
	 * @param IUserManager $userManager user lookup for enabled-app checks
	 * @param ?IAppManager $appManager optional per-user app availability service
	 * @param ?IFullTextSearchManager $ftsManager optional full-text search provider
	 */
	public function __construct(
		private IUserManager $userManager,
		private ?IAppManager $appManager = null,
		private ?IFullTextSearchManager $ftsManager = null,
	) {
	}

	/**
	 * Checks whether the user's full-text apps and indexed Files provider are available.
	 *
	 * @param string $userId authenticated user whose enabled apps are checked
	 * @return bool false when the service is absent or its availability check fails
	 */
	public function available(string $userId): bool {
		if ($this->appManager === null || $this->ftsManager === null) {
			return false;
		}
		$user = $this->userManager->get($userId);
		if (!$this->appManager->isEnabledForUser('fulltextsearch', $user)
			|| !$this->appManager->isEnabledForUser('files_fulltextsearch', $user)) {
			return false;
		}
		try {
			return $this->ftsManager->isAvailable() && $this->ftsManager->isProviderIndexed('files');
		} catch (\Throwable) {
			return false;
		}
	}
}
