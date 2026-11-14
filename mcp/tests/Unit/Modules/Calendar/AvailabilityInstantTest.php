<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Calendar;

use DateTimeImmutable;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Modules\Calendar\Integration\CalendarAvailability;
use OCA\Mcp\Modules\Calendar\Reading\AvailabilityCandidates;
use OCA\Mcp\Modules\Calendar\Reading\AvailabilityParticipants;
use OCA\Mcp\Modules\Calendar\Reading\AvailabilityQuery;
use OCA\Mcp\Modules\Calendar\Reading\AvailabilityWindow;
use OCA\Mcp\Modules\Calendar\Reading\CheckAvailabilityHandler;
use OCA\Mcp\Modules\Calendar\Reading\SuggestTimesHandler;
use OCA\Mcp\Modules\Calendar\Reading\SuggestionInput;
use OCP\Calendar\IAvailabilityResult;
use OCP\Calendar\IManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

/** The same instant gives the same verdict whatever offset the caller wrote it in. */
final class AvailabilityInstantTest extends TestCase {
	/** Bob's only event: 13:30–14:30 in São Paulo. */
	private const BUSY = ['2026-11-19T16:30:00Z', '2026-11-19T17:30:00Z'];

	/** @var list<array{DateTimeImmutable, DateTimeImmutable}> windows exactly as handed to the native manager */
	private array $asked = [];

	/**
	 * Native double that, like Nextcloud 34/35, serializes the request into a VFREEBUSY with Sabre and reads it back:
	 * a zone without a matching VTIMEZONE is read as UTC wall time, which is what hid the conflict.
	 *
	 * @return ContainerInterface lazy container resolving the double
	 */
	private function native(): ContainerInterface {
		$manager = $this->createMock(IManager::class);
		$manager->method('checkAvailability')->willReturnCallback(function (DateTimeImmutable $start, DateTimeImmutable $end, IUser $organizer, array $emails): array {
			$this->asked[] = [$start, $end];
			$calendar = new VCalendar();
			$request = $calendar->add('VFREEBUSY');
			$request->add('DTSTART', $start);
			$request->add('DTEND', $end);
			$read = Reader::read($calendar->serialize())->VFREEBUSY;
			$from = $read->DTSTART->getDateTime()->getTimestamp();
			$to = $read->DTEND->getDateTime()->getTimestamp();
			$free = strtotime(self::BUSY[1]) <= $from || strtotime(self::BUSY[0]) >= $to;
			$result = $this->createMock(IAvailabilityResult::class);
			$result->method('getAttendeeEmail')->willReturn('mailto:' . $emails[0]);
			$result->method('isAvailable')->willReturn($free);
			return [$result];
		});
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(IManager::class)->willReturn($manager);
		return $container;
	}

	/** @return AvailabilityQuery shared read over two local accounts and the native double */
	private function query(): AvailabilityQuery {
		$accounts = [];
		foreach (['alice', 'bob'] as $id) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($id);
			$user->method('isEnabled')->willReturn(true);
			$user->method('getEMailAddress')->willReturn($id . '@example.invalid');
			$accounts[$id] = $user;
		}
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(static fn ($id) => $accounts[$id] ?? null);
		$users->method('getByEmail')->willReturnCallback(static fn ($email) => array_values(array_filter($accounts, static fn ($user) => $user->getEMailAddress() === $email)));
		$policy = $this->createMock(GrantPolicy::class);
		$policy->method('scopeItems')->willReturn(null);
		return new AvailabilityQuery($policy, new AvailabilityParticipants($users), new CalendarAvailability($this->native()));
	}

	/**
	 * @param string $start caller start
	 * @param string $end caller end
	 * @return array<string, mixed> decoded calendar_check_availability result for bob
	 */
	private function check(string $start, string $end): array {
		$result = (new CheckAvailabilityHandler($this->query(), new AvailabilityWindow()))->handle('alice', [
			'participants' => ['bob'], 'start' => $start, 'end' => $end, 'timezone' => 'America/Sao_Paulo',
		]);
		return json_decode($result['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
	}

	/** @return iterable<string, array{string, string, string}> one instant per offset, and both touching boundaries */
	public static function windows(): iterable {
		yield 'conflict in -03:00' => ['2026-11-19T13:30:00-03:00', '2026-11-19T14:30:00-03:00', 'busy'];
		yield 'conflict in +05:30' => ['2026-11-19T22:00:00+05:30', '2026-11-19T23:00:00+05:30', 'busy'];
		yield 'conflict in Z' => ['2026-11-19T16:30:00Z', '2026-11-19T17:30:00Z', 'busy'];
		yield 'partial overlap in -03:00' => ['2026-11-19T14:00:00-03:00', '2026-11-19T15:00:00-03:00', 'busy'];
		yield 'starts when the event ends' => ['2026-11-19T14:30:00-03:00', '2026-11-19T15:30:00-03:00', 'free'];
		yield 'ends when the event starts' => ['2026-11-19T21:00:00+05:30', '2026-11-19T22:00:00+05:30', 'free'];
		yield 'wall time of the event read as UTC' => ['2026-11-19T13:30:00Z', '2026-11-19T14:30:00Z', 'free'];
	}

	/**
	 * @param string $start caller start
	 * @param string $end caller end
	 * @param string $status expected verdict for bob
	 * @return void verdict depends on the instant only, and the native manager always receives UTC
	 */
	#[DataProvider('windows')]
	public function testVerdictFollowsTheInstantAndNativeReceivesUtc(string $start, string $end, string $status): void {
		$data = $this->check($start, $end);
		self::assertSame([['participant' => 'bob', 'status' => $status]], $data['participants']);
		self::assertCount(1, $this->asked);
		[$from, $to] = $this->asked[0];
		self::assertSame('UTC', $from->getTimezone()->getName());
		self::assertSame('UTC', $to->getTimezone()->getName());
		self::assertSame(strtotime($start), $from->getTimestamp());
		self::assertSame(strtotime($end), $to->getTimestamp());
	}

	/** @return void the reply still shows the window in the zone the caller named, as before */
	public function testReplyKeepsTheCallerZone(): void {
		$data = $this->check('2026-11-19T22:00:00+05:30', '2026-11-19T23:00:00+05:30');
		self::assertSame('2026-11-19T13:30:00-03:00', $data['start']);
		self::assertSame('2026-11-19T14:30:00-03:00', $data['end']);
		self::assertSame('America/Sao_Paulo', $data['timezone']);
	}

	/** @return void calendar_suggest_times shares the adapter, so its named-zone windows also reach the manager in UTC */
	public function testSuggestionsShareTheUtcBoundary(): void {
		$handler = new SuggestTimesHandler($this->query(), new SuggestionInput(new AvailabilityWindow()), new AvailabilityCandidates());
		$handler->handle('alice', array_replace(AvailabilityCandidatesTest::args(), ['participants' => ['bob']]));
		self::assertNotSame([], $this->asked);
		foreach ($this->asked as [$from, $to]) {
			self::assertSame('UTC', $from->getTimezone()->getName());
			self::assertSame('UTC', $to->getTimezone()->getName());
		}
	}
}
