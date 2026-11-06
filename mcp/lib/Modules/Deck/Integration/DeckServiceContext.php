<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Integration;

use OCA\Deck\Service\BoardService;
use OCP\ISession;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use OCA\Mcp\Tools\Deck\DeckSessionException;

/** Request-local lazy native services and caller binding shared by the Deck facade's collaborators. */
final class DeckServiceContext {
	/** @var array<string, object> Services resolved so far in this request, as in the original gateway. */
	private array $resolved = [];

	/**
	 * Receive the same container/session supplied to the facade; no global locator or native eager DI.
	 *
	 * @param ContainerInterface $container Injected Nextcloud container resolving optional Deck classes lazily.
	 * @param ISession|null $session Native Deck session, checked before binding or writing for a caller.
	 */
	public function __construct(private ContainerInterface $container, private ?ISession $session = null) {
	}

	/**
	 * Resolves the Deck board service and binds it to the caller.
	 *
	 * `BoardService::setUserId()` also calls `PermissionService::setUserId()`, which clears the
	 * permission cache, so every Deck ACL decision in this request is taken for the authenticated
	 * user rather than for any state left over by the session.
	 *
	 * `setUserId()` exists on the board service only: `CardService`, `AssignmentService` and the
	 * `ActivityManager` keep the `userId` the DI container injected, which is `ISession::get('user_id')`.
	 * When it is not the caller, Deck v1.17.5 writes the card and then fails in `enrichCards()`, so the
	 * session is checked first and the call is refused before anything of the Deck runs.
	 *
	 * @param string $userId UID of the authenticated caller.
	 * @return BoardService Deck board service bound to that user.
	 * @throws DeckSessionException When the session the Deck services read is not the caller's.
	 */
	public function bindUser(string $userId): BoardService {
		if ($this->session !== null && $this->session->get('user_id') !== $userId) {
			throw new DeckSessionException('The session user is not the authenticated caller');
		}

		/** @var BoardService $boardService */
		$boardService = $this->get(BoardService::class);
		$boardService->setUserId($userId);

		return $boardService;
	}

	/**
	 * Resolves a Deck service once per request.
	 *
	 * @param string $class FQCN of the Deck class, used as the cache key.
	 * @return object The resolved Deck service.
	 */
	public function get(string $class): object {
		if (!isset($this->resolved[$class])) {
			$this->resolved[$class] = $this->container->get($class);
		}

		return $this->resolved[$class];
	}

	/**
	 * Display name of a user, falling back to the uid when the account is gone or has none.
	 *
	 * @param string $userId UID whose name is shown to the caller.
	 * @return string Name to put in a confirmation message.
	 */
	public function displayName(string $userId): string {
		/** @var IUserManager $userManager */
		$userManager = $this->get(IUserManager::class);

		return $userManager->get($userId)?->getDisplayName() ?: $userId;
	}
}
