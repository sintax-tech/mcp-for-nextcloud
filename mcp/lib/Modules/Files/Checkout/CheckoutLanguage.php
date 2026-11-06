<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Checkout;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\L10n\UserL10n;
use OCA\Mcp\OAuth\Storage\TokenHasher;
use OCP\IUserManager;
use OCP\IUserSession;

/** The sole owner of checkout user language selection. */
final class CheckoutLanguage {
	/**
	 * @param CheckoutTokenStore $store Existing token lookups.
	 * @param TokenHasher $hasher Token digest.
	 * @param IUserSession $userSession Session agreement.
	 * @param ?IUserManager $userManager Owner lookup.
	 * @param ?UserL10n $l10n Owner translator.
	 */
	public function __construct(
		private CheckoutTokenStore $store,
		private TokenHasher $hasher,
		private IUserSession $userSession,
		private ?IUserManager $userManager = null,
		private ?UserL10n $l10n = null,
	) {
	}

	/**
	 * Configures the translator for the token owner before body or permission checks,
	 * so early refusals are delivered in the user language when the token is known.
	 * @param \Closure(): ?string $routeToken Route-only token lookup, evaluated after the language guards.
	 * @return void
	 */
	public function configureUserL10n(\Closure $routeToken): void {
		if ($this->l10n === null || $this->userManager === null) {
			return;
		}
		$token = $routeToken();
		if ($token === null) {
			return;
		}
		$row = $this->store->find($this->hasher->hash($token));
		if ($row === null) {
			return;
		}
		$session = $this->userSession->getUser();
		if ($session !== null && $session->getUID() !== $row->userId) {
			return;
		}
		$user = $this->userManager->get($row->userId);
		if ($user !== null) {
			Translator::use($this->l10n->forUser($user));
		}
	}

	/**
	 * @param string $uid Token owner.
	 * @return void
	 */
	public function forOwner(string $uid): void {
		if ($this->l10n !== null && $this->userManager !== null && ($user = $this->userManager->get($uid)) !== null) {
			Translator::use($this->l10n->forUser($user));
		}
	}
}
