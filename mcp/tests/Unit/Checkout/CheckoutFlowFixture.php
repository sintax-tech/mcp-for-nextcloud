<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Checkout;

use OCA\Mcp\Modules\Files\Checkout\CheckoutTokenStore;
use OCA\Mcp\L10n\UserL10n;
use OCA\Mcp\OAuth\Storage\TokenHasher;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Guests\GuestRestrictions;
use OCA\Mcp\Platform\FileAccess\LockAwareWrite;
use OCA\Mcp\Platform\FileAccess\NodeAccessInfo;
use OCA\Mcp\Platform\FileAccess\SharedWriteGuard;
use OCA\Mcp\Modules\Files\Checkout\CheckoutService;
use OCA\Mcp\Modules\Files\Backups\FileBackup;
use OCA\Mcp\Modules\Files\Creation\FileCreation;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;
use OCP\ITempManager;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use OCA\Mcp\Modules\Files\Checkout\{CheckoutFlow, CheckoutLanguage, CheckoutPolicy, CheckoutResolver, CheckoutDownload, BodyReader, CheckoutRefusals, CheckoutWriter};

/** Builds the extracted graph over the existing checkout fixtures, preserving their optional services. */
final class CheckoutFlowFixture {
	/**
	 * @param IRootFolder $rootFolder Existing node fixture.
	 * @param IUserSession $userSession Existing session.
	 * @param ITempManager $tempManager Existing temporary producer.
	 * @param ITimeFactory $time Existing clock.
	 * @param GrantPolicy $policy Existing grants.
	 * @param CheckoutTokenStore $store Existing token rows.
	 * @param TokenHasher $hasher Existing hash secret.
	 * @param CheckoutService $checkout Existing checkout issuance and limits.
	 * @param FileBackup $backup Existing internal backup service.
	 * @param SharedWriteGuard $guard Existing shared confirmation.
	 * @param NodeAccessInfo $accessInfo Existing ownership description.
	 * @param LoggerInterface $logger Existing diagnostics.
	 * @param UserL10n|null $l10n Existing optional translator.
	 * @param IUserManager|null $userManager Existing optional account lookup.
	 * @param \OCA\Mcp\Access\ResourceScope\FilesAccessGuard|null $visibilityGuard Existing optional scope.
	 * @param FileCreation|null $creation Existing optional create service.
	 * @param LockAwareWrite|null $locks Existing optional locks.
	 * @param GuestRestrictions|null $guests Existing optional Guests restrictions.
	 * @param \OCA\Mcp\Access\FileTypes\FileTypeGuard|null $fileTypes Existing optional type policy.
	 * @return CheckoutFlow The same collaborators wired into the focused services.
	 */
	public static function build(
		IRootFolder $rootFolder,
		IUserSession $userSession,
		ITempManager $tempManager,
		ITimeFactory $time,
		GrantPolicy $policy,
		CheckoutTokenStore $store,
		TokenHasher $hasher,
		CheckoutService $checkout,
		FileBackup $backup,
		SharedWriteGuard $guard,
		NodeAccessInfo $accessInfo,
		LoggerInterface $logger,
		?UserL10n $l10n = null,
		?IUserManager $userManager = null,
		?\OCA\Mcp\Access\ResourceScope\FilesAccessGuard $visibilityGuard = null,
		?FileCreation $creation = null,
		?LockAwareWrite $locks = null,
		?GuestRestrictions $guests = null,
		?\OCA\Mcp\Access\FileTypes\FileTypeGuard $fileTypes = null,
	): CheckoutFlow {
		$refusals = new CheckoutRefusals($logger);
		$language = new CheckoutLanguage($store, $hasher, $userSession, $userManager, $l10n);
		$admission = new CheckoutPolicy($refusals, $store, $policy, $userSession, $time, $userManager, $guests, $visibilityGuard);
		$resolver = new CheckoutResolver($admission, $language, $refusals, $rootFolder, $visibilityGuard);
		$download = new CheckoutDownload($resolver, $admission, $refusals);
		$writer = new CheckoutWriter($resolver, $admission, $language, $refusals, $rootFolder, $backup, $guard, $accessInfo, $logger, $creation, $visibilityGuard, $locks, $fileTypes);
		return new CheckoutFlow($language, $admission, $download, $writer, $checkout, new BodyReader($tempManager, $refusals), $refusals, $store, $hasher);
	}
}
