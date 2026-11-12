<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Reading;

use DateTimeImmutable;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Modules\Calendar\Failures\CalendarException;
use OCA\Mcp\Modules\Calendar\Integration\CalendarAvailability;
use OCA\Mcp\Modules\Calendar\Presentation\AvailabilityMessages;
use OCP\IUser;
use Throwable;

/** Shared opt-in free/busy read: one policy snapshot, exact local audience, fail-closed native projection. */
final class AvailabilityQuery {
	/**
	 * @param GrantPolicy $policy current operation's scope snapshot
	 * @param AvailabilityParticipants $participants exact local resolution
	 * @param CalendarAvailability $native lazy public API
	 */
	public function __construct(private GrantPolicy $policy, private AvailabilityParticipants $participants, private CalendarAvailability $native) {}

	/**
	 * @param string $userId authenticated actor, never a caller-supplied organizer
	 * @return void
	 * @throws CalendarException when the public API cannot honor collection scope
	 * @throws \OCA\Mcp\Access\Policy\PolicyUnavailable when scope cannot be read
	 */
	public function assertUnrestricted(string $userId): void {
		if ($this->policy->scopeItems($userId, 'calendar') !== null) throw new CalendarException(AvailabilityMessages::restricted());
	}

	/**
	 * @param string $userId authenticated actor
	 * @param mixed $ids supplied exact identifiers
	 * @return array{organizer:IUser, people:array<string, string|null>} resolved once per bounded call, never persisted
	 */
	public function audience(string $userId, mixed $ids): array {
		$people = $this->participants->resolve($ids);
		return ['organizer' => $this->participants->organizer($userId), 'people' => $people];
	}

	/**
	 * @param array{organizer:IUser, people:array<string, string|null>} $audience checked exact local participants
	 * @param DateTimeImmutable $start inclusive start
	 * @param DateTimeImmutable $end exclusive end
	 * @return list<array{participant:string, status:string}> only free/busy/unknown; a batch failure makes everyone unknown
	 */
	public function check(array $audience, DateTimeImmutable $start, DateTimeImmutable $end): array {
		try {
			$verdicts = $this->native->check($start, $end, $audience['organizer'], array_values(array_unique(array_filter($audience['people']))));
		} catch (Throwable) {
			$verdicts = [];
		}
		$rows = [];
		foreach ($audience['people'] as $id => $email) {
			$value = $email === null ? null : ($verdicts[$email] ?? null);
			$rows[] = ['participant' => (string)$id, 'status' => $value === null ? 'unknown' : ($value ? 'free' : 'busy')];
		}
		return $rows;
	}
}
