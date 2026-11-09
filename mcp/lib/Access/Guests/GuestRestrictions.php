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
use OCP\IRequest;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The restrictions of the Guests app for the user of an MCP request.
 *
 * Guests sets them up for a guest in two places (guests v4.10.1): on its boot, only when the session already holds the
 * user (`lib/AppInfo/Application.php:66-82`), and on the core login event, without the late part
 * (`lib/Listener/UserLoggedInListener.php`). It checks the request against the apps allowed to guests and hides
 * external storage; the late part, with "hide other accounts", forces "share only with members of my groups", clears
 * the contacts manager and stops the listing of accounts (`lib/RestrictionManager.php:39-95`). An app password logs
 * in after the boot and gets no late part; a Bearer token of this app is validated inside the controller and gets
 * none of it. So every request takes the same steps here, through the Guests services themselves, once its user is
 * the session user.
 *
 * Guests checks only the app of the requested URL, and every MCP request is one of this app. The tools of a module
 * reach the app behind it directly, so {@see self::allowsApp()} answers, per app, what Guests would answer for a URL
 * of that app: the tools, resources and references of a module whose app is not allowed to the guest are left out
 * and refused.
 *
 * Fails closed: when Guests is enabled and the user's status, the list of allowed apps or the restrictions cannot be
 * read or applied, the request, or the module, is refused.
 */
class GuestRestrictions {
	public const APP = 'guests';

	/** @var array<string, array<string, bool>> answers of {@see self::allowsApp()} in this request, by user and app */
	private array $apps = [];
	/** @var array<string, bool> answers of {@see self::hidesExternalStorage()} in this request, by user */
	private array $external = [];

	/**
	 * @param IAppManager $appManager checks whether Guests is enabled before any native call
	 * @param IRequest $request supplies the path at the existing URL-check point
	 * @param NativeGuests $nativeGuests lazy native restrictions, supplied by the owning module
	 * @param LoggerInterface $logger records failures that refuse access
	 */
	public function __construct(
		private IAppManager $appManager,
		private IRequest $request,
		private NativeGuests $nativeGuests,
		private LoggerInterface $logger,
	) {}

	/**
	 * Applies the Guests restrictions to the user of the request, who must already be the session user.
	 *
	 * @param IUser $user authenticated user (app password or token owner)
	 * @return bool false when the request must be refused: a guest calling an app Guests does not allow to guests, or
	 *     restrictions that could not be applied; true for a non-guest, without Guests, or once they are applied
	 */
	public function apply(IUser $user): bool {
		return $this->setUp($user, true);
	}

	/**
	 * Applies the Guests restrictions to the owner of a request that carries no session, a checkout link: the app list
	 * against the requested URL and the filter of external storage, before any of the owner's files is resolved. The
	 * late part (sharing within groups, account listing) has nothing to act on there and needs a session user.
	 *
	 * Guests before v4.8.0 reads the user of `setupRestrictions()` from the session only, so without a session it
	 * would hide nothing: a guest is refused there.
	 *
	 * @param IUser $user owner of the link
	 * @return bool false when the request must be refused; true for a non-guest, without Guests, or once applied
	 */
	public function applyToOwner(IUser $user): bool {
		return $this->setUp($user, false);
	}

	/**
	 * @param IUser $user the user the restrictions are for
	 * @param bool $session whether that user is the session user, so the steps that read the session apply to them
	 * @return bool false when the request must be refused
	 */
	private function setUp(IUser $user, bool $session): bool {
		if (!AppEnablement::forAnyone($this->appManager, self::APP)) {
			return true;
		}
		try {
			if (!$this->nativeGuests->isGuest($user)) {
				return true;
			}
			if (!$this->nativeGuests->isUrlAllowed($user, $this->request->getPathInfo())) {
				return false;
			}
			return $this->nativeGuests->setUp($user, $session);
		} catch (Throwable $e) {
			$this->logger->error('The restrictions of the Guests app could not be applied to an MCP request; it is refused: {exception}', [
				'app' => 'mcp',
				'exception' => ExceptionDetails::describe($e),
			]);
			return false;
		}
	}

	/**
	 * Whether Guests lets the user reach an app, as it would answer for a URL of that app: always for a user who is not
	 * a guest, without Guests or with its list of apps turned off; otherwise only for an app on the list or one Guests
	 * always allows (files, dav...).
	 *
	 * @param string $userId authenticated user
	 * @param string $app app id behind a module (GrantMatrix::MODULE_APPS) or a tool; '' for the core, always allowed
	 * @return bool false also when Guests is enabled and its answer cannot be read
	 */
	public function allowsApp(string $userId, string $app): bool {
		if ($app === '') {
			return true;
		}
		if (isset($this->apps[$userId][$app])) {
			return $this->apps[$userId][$app];
		}
		try {
			$allowed = !AppEnablement::forAnyone($this->appManager, self::APP)
				|| !$this->nativeGuests->isGuest($userId)
				|| $this->nativeGuests->allowsAppOnList($app);
		} catch (Throwable $e) {
			$this->logger->error('The apps the Guests app allows could not be read; the module is refused: {exception}', [
				'app' => 'mcp',
				'exception' => ExceptionDetails::describe($e),
			]);
			$allowed = false;
		}
		return $this->apps[$userId][$app] = $allowed;
	}

	/**
	 * Whether Guests hides external storage from the user: a guest while "allow external storage" is off. Guests does
	 * it with a mount filter, which core applies when it sets up all the mounts of a user, but not when it mounts a
	 * path on demand (`IPartialMountProvider`, files_external from Nextcloud 35 on: `MountProviderCollection::
	 * getUserMountsFromProviderByPath()` of v35.0.1 never calls `filterMounts()`). The Files gate asks this for every
	 * node, so a file on external storage stays out of reach however it is resolved.
	 *
	 * @param string $userId user whose files are reached
	 * @return bool true also when Guests is enabled and its answer cannot be read
	 */
	public function hidesExternalStorage(string $userId): bool {
		if (isset($this->external[$userId])) {
			return $this->external[$userId];
		}
		try {
			$hides = AppEnablement::forAnyone($this->appManager, self::APP)
				&& $this->nativeGuests->isGuest($userId)
				&& !$this->nativeGuests->allowExternalStorage();
		} catch (Throwable $e) {
			$this->logger->error('Whether the Guests app hides external storage could not be read; it is hidden: {exception}', [
				'app' => 'mcp',
				'exception' => ExceptionDetails::describe($e),
			]);
			$hides = true;
		}
		return $this->external[$userId] = $hides;
	}
}
