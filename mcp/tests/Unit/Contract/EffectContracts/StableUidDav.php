<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Tests\Unit\Tools\Calendar\FakeCalendarDav;
use OCA\Mcp\Tools\Calendar\CalendarDav;
use OCA\Mcp\Tools\Calendar\DavResult;

/**
 * CalendarDav in front of {@see FakeCalendarDav} that makes a created object deterministic for the effect goldens.
 *
 * A create (event or task) draws a random UID and names the object after it. On the PUT of such an object the UID
 * inside the iCalendar text becomes `effect-uid-<n>`, numbered by first appearance, before the fake store keeps it
 * under the URI the handler chose; the handler reads the object back by that same URI and reports the stable UID.
 * Sabre also stamps every component it creates with defaults with a DTSTAMP of the real clock, which the frozen clock
 * of the module cannot reach: on any PUT or update such a value (within a day of the real now) becomes
 * {@see self::WALL_CLOCK}, so the line stays visible in the golden and the ETag (a hash of the text) stays stable.
 * Every dispatched request is also logged with the stable name in place of the random URI, so the observed effect
 * of a run is the same on every run.
 */
final class StableUidDav implements CalendarDav {
	/** What a DTSTAMP read from the real clock is stored as. */
	public const WALL_CLOCK = '20000101T000000Z';

	/** @var list<string> dispatched requests as "<operation> <calendar>/<object>", random object names replaced */
	public array $log = [];
	/** @var array<string, string> stable UID by the random UID the handler drew */
	private array $stable = [];

	/** @param FakeCalendarDav $inner the fake the requests are applied to */
	public function __construct(private FakeCalendarDav $inner) {
	}

	/** @inheritDoc */
	public function put(string $userId, string $calendarUri, string $objectUri, string $ics, bool $scheduling = false, ?int $sizeLimit = null, array $acceptedStatuses = [201]): DavResult {
		$uid = preg_match('/^UID:(.+?)\r?$/m', $ics, $match) === 1 ? $match[1] : '';
		$stable = $this->stable[$uid] ??= 'effect-uid-' . (count($this->stable) + 1);
		$this->log[] = 'put ' . $calendarUri . '/' . str_replace($uid, $stable, $objectUri);
		$ics = preg_replace('/^UID:' . preg_quote($uid, '/') . '(\r?)$/m', 'UID:' . $stable . '$1', $ics) ?? $ics;
		return $this->inner->put($userId, $calendarUri, $objectUri, self::frozen($ics), $scheduling, $sizeLimit, $acceptedStatuses);
	}

	/** @inheritDoc */
	public function update(string $userId, string $calendarUri, string $objectUri, string $etag, string $ics, bool $scheduling = false, ?int $sizeLimit = null, array $acceptedStatuses = [204]): DavResult {
		$this->log[] = 'update ' . $calendarUri . '/' . $this->name($objectUri);
		return $this->inner->update($userId, $calendarUri, $objectUri, $etag, self::frozen($ics), $scheduling, $sizeLimit, $acceptedStatuses);
	}

	/** @inheritDoc */
	public function delete(string $userId, string $calendarUri, string $objectUri, ?string $etag = null, bool $scheduling = false, array $acceptedStatuses = [204]): DavResult {
		$this->log[] = 'delete ' . $calendarUri . '/' . $this->name($objectUri);
		return $this->inner->delete($userId, $calendarUri, $objectUri, $etag, $scheduling, $acceptedStatuses);
	}

	/** @inheritDoc */
	public function move(string $userId, string $fromCalendarUri, string $objectUri, string $toCalendarUri, ?string $etag = null, array $acceptedStatuses = [201]): DavResult {
		$this->log[] = 'move ' . $fromCalendarUri . '/' . $this->name($objectUri) . ' ' . $toCalendarUri;
		return $this->inner->move($userId, $fromCalendarUri, $objectUri, $toCalendarUri, $etag, $acceptedStatuses);
	}

	/** @inheritDoc */
	public function mkcalendar(string $userId, string $calendarUri, string $displayName): DavResult {
		$this->log[] = 'mkcalendar ' . $calendarUri;
		return $this->inner->mkcalendar($userId, $calendarUri, $displayName);
	}

	/** @inheritDoc */
	public function deleteCalendar(string $userId, string $calendarUri): DavResult {
		$this->log[] = 'deleteCalendar ' . $calendarUri;
		return $this->inner->deleteCalendar($userId, $calendarUri);
	}

	/** @inheritDoc */
	public function probeWrite(string $actorUid, string $ownerUid, string $calendarUri, string $objectUri, string $ics): DavResult {
		return $this->inner->probeWrite($actorUid, $ownerUid, $calendarUri, $objectUri, $ics);
	}

	/**
	 * @param string $ics iCalendar text as the handler built it
	 * @return string the same text with every DTSTAMP of the real clock replaced by {@see self::WALL_CLOCK}
	 */
	private static function frozen(string $ics): string {
		return preg_replace_callback('/^DTSTAMP:(\d{8}T\d{6}Z)(\r?)$/m', static function (array $match): string {
			$at = \DateTimeImmutable::createFromFormat('Ymd\THis\Z', $match[1], new \DateTimeZone('UTC'));
			return $at !== false && abs($at->getTimestamp() - time()) < 86400 ? 'DTSTAMP:' . self::WALL_CLOCK . $match[2] : $match[0];
		}, $ics) ?? $ics;
	}

	/**
	 * @param string $objectUri object URI as the handler addressed it
	 * @return string the same URI with every random UID seen on a PUT replaced by its stable name
	 */
	private function name(string $objectUri): string {
		return strtr($objectUri, $this->stable);
	}
}
