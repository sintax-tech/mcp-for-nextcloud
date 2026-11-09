<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Calendar;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Tests\Unit\L10n\JsonL10n;
use PHPUnit\Framework\Attributes\DataProvider;

/** Exercises plan preparation under account, locale and calendar conditions from support diagnostics. */
final class CalendarPlanEnvironmentTest extends CalendarTestCase {
	/** @return iterable<string, array{string,string,string,array<string,mixed>}> Neutral account and event variations. */
	public static function environments(): iterable {
		foreach (['alice', 'MemberOne'] as $user) {
			foreach (['en', 'de', 'de_DE', 'pt_BR', 'es'] as $locale) {
				foreach (['', 'invalid/zone', 'Europe/Berlin'] as $zone) {
					foreach ([
						'offset' => ['start' => '2026-12-01T10:00:00+01:00', 'end' => '2026-12-01T11:00:00+01:00'],
						'zone' => ['start' => '2026-12-01T09:00:00Z', 'end' => '2026-12-01T10:00:00Z', 'timeZone' => 'Europe/Berlin'],
						'day' => ['start' => '2026-12-01', 'end' => '2026-12-02', 'allDay' => true],
					] as $shape => $dates) {
						yield "$user-$locale-$zone-$shape" => [$user, $locale, $zone, $dates];
					}
				}
			}
		}
	}

	/**
	 * @param string $user Account ID, including a mixed-case variant.
	 * @param string $locale Request language.
	 * @param string $zone Account timezone preference, including empty and invalid values.
	 * @param array<string,mixed> $dates Valid event timing variations.
	 * @return void Both previews return review data and leave the DAV write journal empty.
	 */
	#[DataProvider('environments')]
	public function testCreateAndDescriptionOnlyUpdateReturnPlans(string $user, string $locale, string $zone, array $dates): void {
		$this->accountTimezones[$user] = $zone;
		Translator::use(new JsonL10n($locale));
		$principal = 'principals/users/' . $user;
		$this->store->calendars[$principal] = [];
		$this->store->addCalendar($principal, 21, 'personal', $principal);
		$this->store->addCalendar($principal, 22, 'contact_birthdays', $principal, ['readOnly' => true]);
		$this->store->addCalendar($principal, 23, 'subscription', $principal, ['readOnly' => true]);
		$this->store->addCalendar($principal, 24, 'shared_by_other', 'principals/users/other', ['readOnly' => true]);
		$this->store->addObject(21, 'event.ics', self::ics("UID:event\nSUMMARY:Before\nDTSTART;TZID=Europe/Berlin:20261201T100000\nDTEND;TZID=Europe/Berlin:20261201T110000"));
		$path = '/remote.php/dav/calendars/' . $user . '/personal/';
		// Domain previews isolate account/calendar lookup from the registry's separately-tested app gate.
		$create = $this->module->preview('calendar_create_event', ['calendar' => $path, 'summary' => 'Neutral event'] + $dates, $user);
		$update = $this->module->preview('calendar_update_event', ['calendar' => $path, 'uid' => 'event', 'description' => 'Neutral description'], $user);
		foreach ([$create, $update] as $plan) {
			self::assertTrue($plan['requiresConfirmation']);
			self::assertSame($user, $plan['calendar']['ownerId']);
			self::assertNotNull($plan['after']);
		}
		self::assertSame('Neutral description', $update['after']['description']);
		self::assertSame($zone === 'Europe/Berlin' ? $zone : date_default_timezone_get(), $create['timezone']);
		$this->assertNoWrites();
	}
}
