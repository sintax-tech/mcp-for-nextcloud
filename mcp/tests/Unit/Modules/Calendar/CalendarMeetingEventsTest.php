<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Calendar;

use InvalidArgumentException;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Guests\GuestRestrictions;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Mcp\Tools\Discovery\CapabilityRegistry;
use OCA\Mcp\Mcp\Tools\Discovery\ToolAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Calendar\CalendarMeetingEvents;
use OCA\Mcp\Modules\Calendar\Integration\MeetingOccurrenceFacts;
use OCA\Mcp\Modules\Calendar\Reading\CalendarEventListing;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** calendar_list_events is the only read the meeting dossier may ask of Calendar. */
final class CalendarMeetingEventsTest extends TestCase {
	private bool $granted = true;
	private bool $appEnabled = true;
	private bool $guestAllowed = true;
	private bool $unavailable = false;
	/** @var list<array{string, string, list<string>}> */
	private array $asked = [];
	/** @var list<array{string, string}> */
	private array $guestApps = [];

	/**
	 * A missing calendar.read grant refuses the port before the reader runs.
	 *
	 * @return void
	 */
	public function testADeniedGrantNeverReachesTheReader(): void {
		$this->granted = false;
		$reader = new CountingEventListing();
		$events = new CalendarMeetingEvents($this->access(), $reader, new UnchangedOccurrenceFacts());

		try {
			$events->events('alice', []);
			self::fail('A denied grant reached the reader');
		} catch (ToolFailure $error) {
			self::assertStringContainsString('calendar_list_events', $error->getMessage());
		}
		self::assertSame(0, $reader->calls);
		self::assertSame([['alice', 'calendar', ['read']]], $this->asked);
	}

	/**
	 * A Calendar app that is off for the actor refuses the port before the reader runs.
	 *
	 * @return void
	 */
	public function testADisabledAppNeverReachesTheReader(): void {
		$this->appEnabled = false;
		$reader = new CountingEventListing();
		$events = new CalendarMeetingEvents($this->access(), $reader, new UnchangedOccurrenceFacts());

		try {
			$events->events('alice', []);
			self::fail('A disabled app reached the reader');
		} catch (ToolFailure $error) {
			self::assertStringContainsString('calendar_list_events', $error->getMessage());
		}
		self::assertSame(0, $reader->calls);
	}

	/**
	 * A guest whom Guests keeps out of Calendar never reaches the reader.
	 *
	 * @return void
	 */
	public function testAGuestTheAppForbidsNeverReachesTheReader(): void {
		$this->guestAllowed = false;
		$reader = new CountingEventListing();
		$events = new CalendarMeetingEvents($this->access(), $reader, new UnchangedOccurrenceFacts());

		try {
			$events->events('alice', []);
			self::fail('A forbidden guest reached the reader');
		} catch (ToolFailure) {
			self::assertSame(0, $reader->calls);
		}
		self::assertContains(['alice', 'calendar'], $this->guestApps);
	}

	/**
	 * An allowed call validates the published schema and asks the reader once.
	 *
	 * @return void
	 */
	public function testAnAllowedCallValidatesThenAsksTheReaderOnce(): void {
		$reader = new CountingEventListing();
		$events = new CalendarMeetingEvents($this->access(), $reader, new UnchangedOccurrenceFacts());

		$result = $events->events('alice', ['from' => '2026-11-13']);

		self::assertSame(['events' => true], $result);
		self::assertSame(1, $reader->calls);
		self::assertSame('alice', $reader->userId);
		self::assertSame('2026-11-13', $reader->arguments['from']);
		self::assertSame([['alice', 'calendar', ['read']]], $this->asked);
		self::assertContains(['alice', 'calendar'], $this->guestApps);
	}

	/**
	 * An argument calendar_list_events does not publish is refused before the reader.
	 *
	 * @return void
	 */
	public function testAnUnknownKeyIsRefusedBeforeTheReader(): void {
		$reader = new CountingEventListing();
		$events = new CalendarMeetingEvents($this->access(), $reader, new UnchangedOccurrenceFacts());

		try {
			$events->events('alice', ['uid' => 'one-event']);
			self::fail('An unknown key reached the reader');
		} catch (InvalidArgumentException) {
			self::assertSame(0, $reader->calls);
		}
	}

	/**
	 * A grant that cannot be read propagates and the reader is not called.
	 *
	 * @return void
	 */
	public function testAnUnreadableGrantPropagatesAndSkipsTheReader(): void {
		$this->unavailable = true;
		$reader = new CountingEventListing();
		$events = new CalendarMeetingEvents($this->access(), $reader, new UnchangedOccurrenceFacts());

		$this->expectException(PolicyUnavailable::class);
		try {
			$events->events('alice', []);
		} finally {
			self::assertSame(0, $reader->calls);
		}
	}

	/**
	 * The registry gate of calendar_list_events, with the grant, app and guest answers of this test.
	 *
	 * @return ToolAccess the real registry decision
	 */
	private function access(): ToolAccess {
		$policy = $this->createMock(GrantPolicy::class);
		$policy->method('grantedAny')->willReturnCallback(function (string $uid, string $module, array $operations): bool {
			$this->asked[] = [$uid, $module, $operations];
			if ($this->unavailable) {
				throw new PolicyUnavailable('x');
			}
			return $this->granted;
		});
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturnCallback(fn (): bool => $this->appEnabled);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (): IUser => $this->createMock(IUser::class));
		$guests = $this->createMock(GuestRestrictions::class);
		$guests->method('allowsApp')->willReturnCallback(function (string $uid, string $app): bool {
			$this->guestApps[] = [$uid, $app];
			return $this->guestAllowed;
		});
		return new ToolAccess($policy, $apps, $users, $guests, new CapabilityRegistry([], new NullLogger()));
	}
}

/** Counts calendar_list_events reads. The port has no other method to call. */
final class CountingEventListing implements CalendarEventListing {
	public int $calls = 0;
	public string $userId = '';
	/** @var array<string, mixed> */
	public array $arguments = [];

	/** {@inheritDoc} */
	public function handle(string $userId, array $arguments): array {
		$this->calls++;
		$this->userId = $userId;
		$this->arguments = $arguments;
		return ['events' => true];
	}
}

/** Leaves the calendar_list_events answer unchanged. The grant tests never need the second walk. */
final class UnchangedOccurrenceFacts implements MeetingOccurrenceFacts {
	/**
	 * @param string $userId the actor
	 * @param array<string, mixed> $arguments the listing arguments
	 * @param array<string, mixed> $result the listing answer
	 * @return array<string, mixed> the same answer
	 */
	public function apply(string $userId, array $arguments, array $result): array {
		return $result;
	}
}
