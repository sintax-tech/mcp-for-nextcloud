<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Mcp\Modules\Calendar\Composing\EventBuilder;
use OCA\Mcp\Modules\Calendar\Composing\EventTiming;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Component\VCalendar;

/**
 * The iCalendar written for a new event and for an update carries one DTSTAMP, the module clock.
 */
final class EventStampTest extends TestCase {
	private const CREATED_AT = '2026-03-10T12:00:00Z';
	private const UPDATED_AT = '2026-03-10T15:30:00Z';

	/**
	 * A created object has the creation instant once, not Sabre's wall-clock default plus another.
	 */
	public function testACreatedEventSerializesExactlyOneDtstampFromTheModuleClock(): void {
		$calendar = $this->created();
		$ics = $calendar->serialize();

		self::assertSame(1, substr_count($ics, 'DTSTAMP:'));
		self::assertSame(1, count($calendar->VEVENT->select('DTSTAMP')));
		self::assertStringContainsString('DTSTAMP:20260310T120000Z', $ics);
	}

	/**
	 * Updating an event that already has one DTSTAMP replaces it and does not append a second.
	 */
	public function testAnUpdateReplacesTheDtstampAndLeavesExactlyOne(): void {
		$calendar = $this->created();
		(new EventBuilder())->touch($calendar->VEVENT, new DateTimeImmutable(self::UPDATED_AT));
		$ics = $calendar->serialize();

		self::assertSame(1, substr_count($ics, 'DTSTAMP:'));
		self::assertSame(1, count($calendar->VEVENT->select('DTSTAMP')));
		self::assertStringContainsString('DTSTAMP:20260310T153000Z', $ics);
		self::assertStringNotContainsString('DTSTAMP:20260310T120000Z', $ics);
	}

	/**
	 * An object that already carries the duplicate Sabre default collapses to the update instant.
	 */
	public function testAnUpdateCollapsesADuplicateDtstampToTheModuleClock(): void {
		$calendar = $this->created();
		$calendar->VEVENT->add('DTSTAMP', new DateTimeImmutable('2020-01-01T00:00:00Z'));
		self::assertSame(2, substr_count($calendar->serialize(), 'DTSTAMP:'));

		(new EventBuilder())->touch($calendar->VEVENT, new DateTimeImmutable(self::UPDATED_AT));
		$ics = $calendar->serialize();

		self::assertSame(1, substr_count($ics, 'DTSTAMP:'));
		self::assertStringContainsString('DTSTAMP:20260310T153000Z', $ics);
	}

	/** @return VCalendar calendar whose only event was built the way a creation writes it */
	private function created(): VCalendar {
		$zone = new DateTimeZone('UTC');
		$timing = new EventTiming(
			new DateTimeImmutable('2026-03-12T09:00:00Z'),
			new DateTimeImmutable('2026-03-12T10:00:00Z'),
			false,
			$zone,
		);

		return (new EventBuilder())->create('event-1', ['summary' => 'Meet'], $timing, new DateTimeImmutable(self::CREATED_AT));
	}
}
