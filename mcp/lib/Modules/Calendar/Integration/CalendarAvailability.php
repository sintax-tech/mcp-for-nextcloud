<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Integration;

use DateTimeImmutable;
use OCP\Calendar\IManager;
use OCP\IUser;
use Psr\Container\ContainerInterface;

/** Lazy public API adapter; native CalDAV scheduling evaluates the organizer's free/busy privileges. */
final class CalendarAvailability {
	/** @param ContainerInterface $container resolves the calendar manager only when a permitted read actually runs */
	public function __construct(private ContainerInterface $container) {}

	/**
	 * @param DateTimeImmutable $start inclusive start
	 * @param DateTimeImmutable $end exclusive end
	 * @param IUser $organizer authenticated actor
	 * @param list<string> $emails verified unambiguous local account addresses
	 * @return array<string, bool|null> normalized address to verdict; missing or conflicting results remain unknown
	 */
	public function check(DateTimeImmutable $start, DateTimeImmutable $end, IUser $organizer, array $emails): array {
		if ($emails === []) return [];
		$results = $this->container->get(IManager::class)->checkAvailability($start, $end, $organizer, $emails);
		$verdicts = [];
		foreach ($results as $result) {
			$email = strtolower(preg_replace('/^mailto:/i', '', $result->getAttendeeEmail()));
			if (!in_array($email, $emails, true)) continue;
			$value = $result->isAvailable();
			$verdicts[$email] = array_key_exists($email, $verdicts) && $verdicts[$email] !== $value ? null : $value;
		}
		return $verdicts;
	}
}
