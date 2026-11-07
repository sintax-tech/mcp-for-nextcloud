<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar\Composing;

use DateTimeImmutable;
use DateTimeZone;
use Sabre\VObject\Component\VEvent;

/**
 * Leaves a VEVENT with exactly one DTSTAMP, taken from the module clock.
 *
 * Creating a VEVENT asks Sabre for its defaults, and {@see VEvent::getDefaults()} inserts a DTSTAMP
 * built with `gmdate()` whenever the caller did not pass one. A later `$event->add('DTSTAMP', …)`
 * does not replace that property: Sabre appends a second one. RFC 5545 requires the property once.
 * The value that must remain is the instant the module was given, in UTC, not the wall clock Sabre read.
 */
final class EventStamp {
	/**
	 * Removes every DTSTAMP and writes one, the given instant in UTC.
	 *
	 * @param VEvent $event component that must carry a single DTSTAMP afterwards
	 * @param DateTimeImmutable $now instant to record; its zone is converted to UTC
	 */
	public function apply(VEvent $event, DateTimeImmutable $now): void {
		unset($event->DTSTAMP);
		$event->add('DTSTAMP', $now->setTimezone(new DateTimeZone('UTC')));
	}
}
