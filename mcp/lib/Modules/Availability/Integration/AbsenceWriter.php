<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Availability\Integration;

use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The native POST of an own absence: AbsenceService::createOrUpdateAbsence for the actor, then the coordinator cache
 * reset, exactly as OutOfOfficeController::setOutOfOffice does. The service stores the row, dispatches the scheduled
 * or changed event and schedules this absence's start/end jobs by their own arguments. Nothing here clears an absence
 * or removes jobs.
 */
final class AbsenceWriter {
	/**
	 * @param ContainerInterface $container lazy native DAV services, resolved only after admission
	 * @param AbsenceSurface $surface fail-closed signature check
	 * @param IUserManager $users the actor's account
	 */
	public function __construct(private ContainerInterface $container, private AbsenceSurface $surface, private IUserManager $users) {}
	/**
	 * @param string $actor authenticated user ID; the only account ever written
	 * @param array{first_day:string,last_day:string,status:string,message:string,replacement_user_id:?string,replacement_display_name:?string} $absence validated values
	 * @return void
	 * @throws RuntimeException missing native path or account; native errors propagate unchanged
	 */
	public function write(string $actor, array $absence): void {
		if (!$this->surface->writable()) throw new RuntimeException('Absence write path unavailable.');
		$user = $this->users->get($actor);
		if ($user === null || $user->getUID() !== $actor) throw new RuntimeException('Unknown actor.');
		$this->container->get(AbsenceSurface::SERVICE)->createOrUpdateAbsence($user, $absence['first_day'], $absence['last_day'],
			$absence['status'], $absence['message'], $absence['replacement_user_id'], $absence['replacement_display_name']);
		$this->container->get(AbsenceSurface::COORDINATOR)->clearCache($actor);
	}
}
