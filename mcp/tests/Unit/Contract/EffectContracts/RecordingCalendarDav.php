<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Tests\Unit\Tools\Calendar\FakeCalendarDav;
use OCA\Mcp\Modules\Calendar\Writing\CalendarDav;
use OCA\Mcp\Modules\Calendar\Writing\DavResult;

/**
 * CalendarDav of the Calendar and Tasks effect contracts: every request goes unchanged to {@see FakeCalendarDav}, so
 * the store keeps and gives back exactly the bytes the code under test sent (its random UID, its DTSTAMP), and the
 * request is logged for the observed state. Nothing is rewritten here; random and wall-clock values are replaced only
 * in the transcript, by {@see DavRandomness}.
 */
final class RecordingCalendarDav implements CalendarDav {
	/** @var list<string> requests dispatched, as "verb calendar/object" */
	public array $log = [];

	/** @param FakeCalendarDav $inner the DAV fake over the store of the contract */
	public function __construct(private FakeCalendarDav $inner) {
	}

	/** {@inheritDoc} */
	public function put(string $userId, string $calendarUri, string $objectUri, string $ics, bool $scheduling = false, ?int $sizeLimit = null, array $acceptedStatuses = [201]): DavResult {
		$this->log[] = 'put ' . $calendarUri . '/' . $objectUri;
		return $this->inner->put($userId, $calendarUri, $objectUri, $ics, $scheduling, $sizeLimit, $acceptedStatuses);
	}

	/** {@inheritDoc} */
	public function update(string $userId, string $calendarUri, string $objectUri, string $etag, string $ics, bool $scheduling = false, ?int $sizeLimit = null, array $acceptedStatuses = [204]): DavResult {
		$this->log[] = 'update ' . $calendarUri . '/' . $objectUri;
		return $this->inner->update($userId, $calendarUri, $objectUri, $etag, $ics, $scheduling, $sizeLimit, $acceptedStatuses);
	}

	/** {@inheritDoc} */
	public function delete(string $userId, string $calendarUri, string $objectUri, ?string $etag = null, bool $scheduling = false, array $acceptedStatuses = [204]): DavResult {
		$this->log[] = 'delete ' . $calendarUri . '/' . $objectUri;
		return $this->inner->delete($userId, $calendarUri, $objectUri, $etag, $scheduling, $acceptedStatuses);
	}

	/** {@inheritDoc} */
	public function move(string $userId, string $fromCalendarUri, string $objectUri, string $toCalendarUri, ?string $etag = null, array $acceptedStatuses = [201]): DavResult {
		$this->log[] = 'move ' . $fromCalendarUri . '/' . $objectUri . ' ' . $toCalendarUri;
		return $this->inner->move($userId, $fromCalendarUri, $objectUri, $toCalendarUri, $etag, $acceptedStatuses);
	}

	/** {@inheritDoc} */
	public function mkcalendar(string $userId, string $calendarUri, string $displayName): DavResult {
		$this->log[] = 'mkcalendar ' . $calendarUri;
		return $this->inner->mkcalendar($userId, $calendarUri, $displayName);
	}

	/** {@inheritDoc} */
	public function deleteCalendar(string $userId, string $calendarUri): DavResult {
		$this->log[] = 'deleteCalendar ' . $calendarUri;
		return $this->inner->deleteCalendar($userId, $calendarUri);
	}

	/** {@inheritDoc} */
	public function probeWrite(string $actorUid, string $ownerUid, string $calendarUri, string $objectUri, string $ics): DavResult {
		return $this->inner->probeWrite($actorUid, $ownerUid, $calendarUri, $objectUri, $ics);
	}
}
