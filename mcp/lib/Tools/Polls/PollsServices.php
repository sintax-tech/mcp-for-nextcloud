<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Polls;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCP\App\IAppManager;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;

/** Native services resolved only under app eligibility and the exact operation grant, without a parallel backend. */
class PollsServices {
	/**
	 * Receives native dependencies and the MCP grant policy.
	 * @param IAppManager $apps per-user availability/version
	 * @param IUserManager $users resolves the authenticated actor
	 * @param ContainerInterface $container native app service container
	 * @param GrantPolicy $policy operation grants, also checked on direct handler calls
	 * @param PollsSurface $surface compatibility guard
	 * @return void
	 */
	public function __construct(private IAppManager $apps, private IUserManager $users,
		private ContainerInterface $container, private GrantPolicy $policy, private PollsSurface $surface) {
	}

	/**
	 * Checks availability before resolving any Polls class, returning the installed version for cursor binding.
	 * @param string $uid authenticated actor
	 * @param string $operation exact grant; defaults to the unchanged PO-1 read operation
	 * @return string actual app version
	 * @throws PollsRefusal when access/surface is unavailable
	 */
	public function prepare(string $uid, string $operation = 'read'): string {
		return PollsOperationAccess::prepare($uid, $operation, $this->apps, $this->users, $this->policy, $this->surface);
	}

	/**
	 * Gets a native dependency; callers choose only the explicitly authorized public read operation.
	 * @param string $class native public dependency
	 * @return object native instance
	 * @throws PollsRefusal for the wrong dependency instance
	 */
	public function resolve(string $class): object {
		$service = $this->container->get($class);
		if (!$service instanceof $class) {
			throw PollsRefusal::unavailable();
		}
		return $service;
	}

	/**
	 * Resolves the session and proves that the native global helper returns the identical object.
	 * @return object native UserSession
	 * @throws PollsRefusal when local and global resolutions disagree
	 */
	public function session(): object {
		$native = $this->resolve(PollsSurface::SESSION);
		$helper = PollsSurface::CONTAINER;
		if ($helper::queryClass(PollsSurface::SESSION) !== $native) {
			throw PollsRefusal::unavailable();
		}
		return $native;
	}

	/**
	 * A fresh native OptionService with a fresh Poll (the native service caches Poll by id).
	 * For PO-1 VoteService is only a constructor dependency; PO-2 invokes its list through its own protected-read gate.
	 * @param object $session the checked native global session
	 * @return object native OptionService
	 */
	public function options(object $session): object {
		$poll = PollsSurface::POLL;
		$dependencies = [];
		foreach (PollsSurface::DEPENDENCIES as $class) {
			$dependencies[] = match ($class) {
				PollsSurface::SESSION => $session,
				PollsSurface::POLL => new $poll(),
				default => $this->resolve($class),
			};
		}
		$options = PollsSurface::OPTIONS;
		return new $options(...$dependencies);
	}
}
