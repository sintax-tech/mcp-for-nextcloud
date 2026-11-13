<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Availability\Integration;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The actor's own stored absence through AbsenceService::getAbsence, the native GET path. It never calls
 * createOrUpdateAbsence, clearAbsence, the mapper, the job list, the event dispatcher or the coordinator cache.
 */
final class AbsenceGateway {
	/**
	 * @param ContainerInterface $container lazy native DAV services, resolved only after admission
	 * @param AbsenceSurface $surface fail-closed signature check
	 * @param IUserManager $users the actor's account
	 * @param ITimeFactory $time server clock, the one AbsenceService compares with
	 */
	public function __construct(private ContainerInterface $container, private AbsenceSurface $surface, private IUserManager $users, private ITimeFactory $time) {}
	/**
	 * @return bool the instance-wide absence feature switch read by IAvailabilityCoordinator::isEnabled
	 * @throws RuntimeException native surface missing
	 */
	public function enabled(): bool {
		$this->guard();
		return $this->container->get(AbsenceSurface::COORDINATOR)->isEnabled() === true;
	}
	/**
	 * @param string $actor authenticated user ID; never an argument of the tool
	 * @return array{first_day:string,last_day:string,status:string,message:string,replacement_user_id:?string,replacement_display_name:?string,timezone:string,start:int,end:int,in_effect:bool,now:int}|null stored absence, or null when none is configured
	 * @throws RuntimeException missing account, foreign row or unreadable native data
	 */
	public function read(string $actor): ?array {
		$this->guard();
		$user = $this->users->get($actor);
		if ($user === null || $user->getUID() !== $actor) throw new RuntimeException('Unknown actor.');
		$service = $this->container->get(AbsenceSurface::SERVICE);
		$absence = $service->getAbsence($actor);
		if ($absence === null) return null;
		if ($absence->getUserId() !== $actor) throw new RuntimeException('Foreign absence.');
		$zones = $this->container->get(AbsenceSurface::TIMEZONE);
		// The same time zone fallback AbsenceService and its jobs use when converting this absence.
		$timezone = $zones->getUserTimezone($actor) ?? $zones->getDefaultTimezone();
		$data = $absence->toOutOufOfficeData($user, $timezone);
		return ['first_day' => (string)$absence->getFirstDay(), 'last_day' => (string)$absence->getLastDay(), 'status' => (string)$absence->getStatus(),
			'message' => (string)$absence->getMessage(), 'replacement_user_id' => $absence->getReplacementUserId(),
			'replacement_display_name' => $absence->getReplacementUserDisplayName(), 'timezone' => $timezone,
			'start' => $data->getStartDate(), 'end' => $data->getEndDate(), 'in_effect' => $service->isInEffect($data), 'now' => $this->time->getTime()];
	}
	/** @throws RuntimeException when the verified native signatures are gone */
	private function guard(): void {
		if (!$this->surface->supported()) throw new RuntimeException('Absence read path unavailable.');
	}
}
