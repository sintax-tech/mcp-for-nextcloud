<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls\Integration;

use OCA\Mcp\Modules\Polls\PollsRefusal;

/** Fresh public service construction, restricted to the native types the mutation surface checks. */
class PollsNativeFactory {
	/**
	 * @param PollsServices $services Existing global identity/dependency resolver.
	 * @return void
	 */
	public function __construct(private PollsServices $services) {
	}

	/**
	 * @param string $class Allowlisted native class.
	 * @param object $session Checked global native session.
	 * @return object Fresh native service, never reused across reads.
	 * @throws \Throwable If validation or a called collaborator fails.
	 */
	public function make(string $class, object $session): object {
		if (!in_array(
			$class,
			[
				PollsSurface::POLLS,
				PollsSurface::OPTIONS,
				PollsMutationSurface::SHARES,
				PollsMutationSurface::POLL_MAPPER,
				PollsMutationSurface::OPTION_MAPPER,
				PollsMutationSurface::SHARE_MAPPER,
				PollsMutationSurface::USER_MAPPER,
				CreateSurface::SETTINGS,
			],
			true
		)) {
			throw PollsRefusal::unavailable();
		}
		$arguments = [];
		foreach ((new \ReflectionClass($class))->getConstructor()->getParameters() as $parameter) {
			$type = $parameter->getType()->getName();
			$arguments[] = match ($type) {
				PollsSurface::SESSION => $session,
				PollsSurface::POLL => new \OCA\Polls\Db\Poll(),
				PollsMutationSurface::SHARE => new \OCA\Polls\Db\Share(),
				PollsMutationSurface::POLL_MAPPER,
					PollsMutationSurface::OPTION_MAPPER,
					PollsMutationSurface::SHARE_MAPPER,
					PollsMutationSurface::USER_MAPPER,
					CreateSurface::SETTINGS => $this->make($type,
					$session),

				default => $this->services->resolve($type),
			};
		}
		return new $class(...$arguments);
	}
}
