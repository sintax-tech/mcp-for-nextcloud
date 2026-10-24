<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Tasks;

use Closure;
use OCA\Mcp\Tools\Calendar\CalendarDav;
use OCA\Mcp\Tools\Calendar\DavResult;

/**
 * The container's real CalDAV write port with one hook: the first update runs a closure just before it reaches
 * DAV. A test uses it to put a second, complete MCP write between the check and the write of the first one, the
 * interleaving two concurrent requests can produce, without any timing.
 */
final class InterleavingDav implements CalendarDav {
	/**
	 * Wraps the real port.
	 *
	 * @param CalendarDav $inner the container's CalendarDav
	 * @param Closure|null $beforeFirstUpdate run once, before the first update is sent
	 * @return void
	 */
	public function __construct(
		private CalendarDav $inner,
		private ?Closure $beforeFirstUpdate = null,
	) {
	}

	/** {@inheritDoc} */
	public function put(string $userId, string $calendarUri, string $objectUri, string $ics, bool $scheduling = false, ?int $sizeLimit = null, array $acceptedStatuses = [201]): DavResult {
		return $this->inner->put($userId, $calendarUri, $objectUri, $ics, $scheduling, $sizeLimit, $acceptedStatuses);
	}

	/** {@inheritDoc} */
	public function update(string $userId, string $calendarUri, string $objectUri, string $etag, string $ics, bool $scheduling = false, ?int $sizeLimit = null, array $acceptedStatuses = [204]): DavResult {
		$hook = $this->beforeFirstUpdate;
		$this->beforeFirstUpdate = null;
		if ($hook !== null) {
			$hook();
		}
		return $this->inner->update($userId, $calendarUri, $objectUri, $etag, $ics, $scheduling, $sizeLimit, $acceptedStatuses);
	}

	/** {@inheritDoc} */
	public function delete(string $userId, string $calendarUri, string $objectUri, ?string $etag = null, bool $scheduling = false, array $acceptedStatuses = [204]): DavResult {
		return $this->inner->delete($userId, $calendarUri, $objectUri, $etag, $scheduling, $acceptedStatuses);
	}

	/** {@inheritDoc} */
	public function move(string $userId, string $fromCalendarUri, string $objectUri, string $toCalendarUri, ?string $etag = null, array $acceptedStatuses = [201]): DavResult {
		return $this->inner->move($userId, $fromCalendarUri, $objectUri, $toCalendarUri, $etag, $acceptedStatuses);
	}

	/** {@inheritDoc} */
	public function mkcalendar(string $userId, string $calendarUri, string $displayName): DavResult {
		return $this->inner->mkcalendar($userId, $calendarUri, $displayName);
	}

	/** {@inheritDoc} */
	public function deleteCalendar(string $userId, string $calendarUri): DavResult {
		return $this->inner->deleteCalendar($userId, $calendarUri);
	}

	/** {@inheritDoc} */
	public function probeWrite(string $actorUid, string $ownerUid, string $calendarUri, string $objectUri, string $ics): DavResult {
		return $this->inner->probeWrite($actorUid, $ownerUid, $calendarUri, $objectUri, $ics);
	}
}
