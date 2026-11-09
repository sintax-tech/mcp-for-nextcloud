<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\Guests;

use OCA\Mcp\Platform\Compat\AppEnablement;
use OCA\Mcp\Platform\Logging\ExceptionDetails;
use OCP\App\IAppManager;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Whether an account is a guest account of the Guests app, for an action a native app refuses to guests whatever
 * apps they may reach (Collectives 4.7.1 refuses guests creating a collective, `CollectiveController.php:57-58,91-92`).
 *
 * This is identification, not {@see GuestRestrictions::apply()}: applying the restrictions succeeds for a guest who is
 * allowed the request. Fails closed: when Guests is enabled for anyone and the answer cannot be read, the account
 * counts as a guest, so the action is refused.
 */
class GuestIdentity {
	/**
	 * @param IAppManager $appManager whether Guests is enabled at all
	 * @param NativeGuests $nativeGuests lazy native identification, supplied by the owning module
	 * @param LoggerInterface $logger records failures that refuse the action
	 */
	public function __construct(
		private IAppManager $appManager,
		private NativeGuests $nativeGuests,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param IUser $user the account to identify
	 * @return bool true for a guest account, and when Guests is enabled but the answer cannot be read
	 */
	public function isGuestAccount(IUser $user): bool {
		try {
			return AppEnablement::forAnyone($this->appManager, GuestRestrictions::APP) && $this->nativeGuests->isGuest($user);
		} catch (Throwable $e) {
			$this->logger->error('The Guests app could not tell whether an account is a guest; the action is refused: {exception}', [
				'app' => 'mcp',
				'exception' => ExceptionDetails::describe($e),
			]);
			return true;
		}
	}
}
