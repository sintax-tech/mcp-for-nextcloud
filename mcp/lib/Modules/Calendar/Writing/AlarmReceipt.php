<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Writing;

use Closure;
use OCA\Mcp\Mcp\Tools\Schema\SchemaBuilder;
use OCA\Mcp\Modules\Calendar\Composing\EventAlarms;
use OCA\Mcp\Modules\Calendar\Failures\CalendarException;
use OCA\Mcp\Modules\Calendar\Locating\EventRepository;
use OCA\Mcp\Modules\Calendar\Locating\StoredEvent;
use OCA\Mcp\Modules\Calendar\Presentation\AlarmMessages;
use Sabre\VObject\Component\VCalendar;
use Throwable;

/** Runs one opt-in reminder write and reports only its fresh readback. */
final class AlarmReceipt {
	/**
	 * @param VCalendar $proposed object to write
	 * @param VCalendar|null $before object before update
	 * @param EventRepository $events parser used by the existing write flow
	 * @param Closure(): mixed $write the single DAV mutation
	 * @param Closure(): ?array $read existing store readback
	 * @param Closure(VCalendar, array): array $map existing event/scheduling result
	 * @return array<string, mixed> MCP result; partial is success, uncertain is an explicit non-retry error
	 */
	public static function dispatch(VCalendar $proposed, ?VCalendar $before, EventRepository $events, Closure $write, Closure $read, Closure $map): array {
		$uid = (string)(new StoredEvent(0, '', '', $proposed))->master()?->UID;
		try {
			$write();
		} catch (CalendarException $e) {
			// The official dispatcher translates definite DAV refusals into domain failures.
			if ($e->getCode() >= 500) {
				return self::uncertain($uid);
			}
			throw $e;
		} catch (Throwable) {
			return self::uncertain($uid);
		}
		try {
			$row = $read();
			$written = $row === null ? null : $events->parse($row['data']);
			if ($written === null || (new StoredEvent(0, '', '', $written))->master() === null) {
				return self::uncertain($uid);
			}
			$preserved = EventAlarms::fingerprints($before, false) === EventAlarms::fingerprints($written, false);
			$overrides = EventAlarms::overrides($before) === EventAlarms::overrides($written);
			$applied = EventAlarms::fingerprints($proposed, true) === EventAlarms::fingerprints($written, true) && $preserved && $overrides;
			$status = $applied ? 'applied' : 'partial';
			return SchemaBuilder::result($map($written, $row) + [
				'uid' => $uid, 'etag' => $row['etag'],
				'alarmReceipt' => [
					'scope' => 'master', 'status' => $status,
					'requested' => EventAlarms::inspect($proposed)['managed'],
					'observed' => EventAlarms::inspect($written)['managed'],
					'preservedVerified' => $preserved, 'overridesUnchanged' => $overrides,
					'overridesMessage' => AlarmMessages::overrides(count(EventAlarms::overrides($before))),
					'notificationDeliveryVerified' => false,
					'message' => AlarmMessages::receipt($status, $uid),
				],
			]);
		} catch (Throwable) {
			return self::uncertain($uid);
		}
	}

	/**
	 * @param string $uid generated or updated event identity
	 * @return array<string, mixed> no observed snapshot and no instruction to retry
	 */
	private static function uncertain(string $uid): array {
		return SchemaBuilder::result([
			'uid' => $uid,
			'alarmReceipt' => [
				'scope' => 'master', 'status' => 'uncertain',
				'preservedVerified' => null, 'overridesUnchanged' => null,
				'notificationDeliveryVerified' => false,
				'message' => AlarmMessages::receipt('uncertain', $uid),
			],
		]) + ['isError' => true];
	}
}
