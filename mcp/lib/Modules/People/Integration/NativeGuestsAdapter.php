<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\People\Integration;

use OCA\Mcp\Access\Guests\NativeGuests;
use OCP\IUser;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/** Resolves Guests only when asked; native failures propagate to the request policy's existing catches. */
final class NativeGuestsAdapter implements NativeGuests {
	/**
	 * @param ContainerInterface $container the app container, without resolving Guests at construction
	 * @param LoggerInterface $logger records the refusal of unsupported sessionless restrictions
	 */
	public function __construct(
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {}

	/** {@inheritDoc} */
	public function isGuest(IUser|string $user): bool {
		return (bool)$this->container->get('OCA\Guests\GuestManager')->isGuest($user);
	}

	/** {@inheritDoc} */
	public function isUrlAllowed(IUser $user, string|false $url): bool {
		return (bool)$this->container->get('OCA\Guests\AppWhitelist')->isUrlAllowed($user, $url);
	}

	/** {@inheritDoc} */
	public function setUp(IUser $user, bool $session): bool {
		$restrictions = $this->container->get('OCA\Guests\RestrictionManager');
		if (!$session && (new ReflectionMethod($restrictions, 'setupRestrictions'))->getNumberOfParameters() === 0) {
			$this->logger->warning('This Guests release applies its restrictions only to a session user; a checkout link of a guest is refused', ['app' => 'mcp']);
			return false;
		}
		$restrictions->setupRestrictions($user);
		if ($session) {
			$restrictions->lateSetupRestrictions();
		}
		return true;
	}

	/** {@inheritDoc} */
	public function allowsAppOnList(string $app): bool {
		$whitelist = $this->container->get('OCA\Guests\AppWhitelist');
		return !$whitelist->isWhitelistEnabled() || $whitelist->isAppWhitelisted($app);
	}

	/** {@inheritDoc} */
	public function allowExternalStorage(): bool {
		return (bool)$this->container->get('OCA\Guests\Config')->allowExternalStorage();
	}
}
