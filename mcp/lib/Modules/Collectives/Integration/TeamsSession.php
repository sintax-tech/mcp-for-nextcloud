<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives\Integration;

use Closure;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Collectives\Presentation\CollectivesMessages;
use Psr\Container\ContainerInterface;

/**
 * The Teams (Circles) manager, reached only here and only in a session of the actor. Every use ends with
 * `stopSession()` in a `finally`, whatever happens inside, including a failure to resolve the actor before the
 * session starts. The same closing wraps native Collectives calls that open Teams sessions themselves: the Collectives
 * helper closes them only after its `try/catch` (`CircleHelper.php:198-231`), so an exception may leave the current
 * user or the privileged bypass set on the shared Teams service for the rest of the request. MCP itself never starts a
 * privileged, app or occ session.
 *
 * Resolving a group makes Teams set the Teams app as the current app of the request, which `stopSession()` does not
 * clear (`FederatedUserService::getGroupCircle`, 35 `:1159-1194`; `CirclesManager::stopSession`, 35 `:197-201`). So the
 * session is sealed before that resolution: afterwards no native call of the module runs in this request, through
 * {@see self::asActor()} or {@see self::closingAfter()} alike, except the readback of the same call, in the actor's
 * session, where Teams prefers the current user to the current app. An MCP request carries one call only.
 */
class TeamsSession {
	/** Whether a group resolution may have set the Teams app as the current app of this request. */
	private bool $sealed = false;

	/** @param ContainerInterface $container server container the Teams manager is shared in */
	public function __construct(private ContainerInterface $container) {
	}

	/**
	 * @template T
	 * @param string $uid actor; the session acts as this account and never as another one
	 * @param Closure(object): T $work what to do with the Teams manager while the session is open
	 * @param bool $readback whether this is the readback of the call that sealed the session
	 * @return T what the work returned
	 * @throws ToolFailure when the session is sealed and this is not that readback
	 * @throws \Throwable whatever the resolution or the work throws, after the session was closed
	 */
	public function asActor(string $uid, Closure $work, bool $readback = false): mixed {
		$this->assertOpen($readback);
		$teams = $this->manager();
		try {
			$teams->startSession($teams->getLocalFederatedUser($uid));
			return $work($teams);
		} finally {
			$teams->stopSession();
		}
	}

	/**
	 * Runs native code that may open Teams sessions on its own, and closes the shared session afterwards.
	 * @template T
	 * @param Closure(): T $work the native call
	 * @return T what it returned
	 * @throws ToolFailure when the session is sealed
	 * @throws \Throwable whatever it threw, after the session was closed
	 */
	public function closingAfter(Closure $work): mixed {
		$this->assertOpen(false);
		$teams = $this->manager();
		try {
			return $work();
		} finally {
			$teams->stopSession();
		}
	}

	/**
	 * @param bool $readback whether this is the readback of the call that sealed the session
	 * @throws ToolFailure when the session is sealed and this is not that readback
	 */
	private function assertOpen(bool $readback): void {
		if ($this->sealed && !$readback) {
			throw new ToolFailure(CollectivesMessages::unavailable());
		}
	}

	/** Seals the session before a resolution that may set the Teams app as the current app of the request. */
	public function seal(): void {
		$this->sealed = true;
	}

	/**
	 * @return object the shared Teams manager
	 * @throws ToolFailure when the container answers something else
	 */
	private function manager(): object {
		$teams = $this->container->get(FoundingSurface::TEAMS);
		if (!is_a($teams, FoundingSurface::TEAMS)) {
			throw new ToolFailure(CollectivesMessages::unavailable());
		}
		return $teams;
	}
}
