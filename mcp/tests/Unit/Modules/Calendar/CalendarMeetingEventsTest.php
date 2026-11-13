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

	public function testADeniedGrantNeverReachesTheReader(): void {
		$this->granted = false;
		$reader = new CountingEventListing();
		$events = new CalendarMeetingEvents($this->access(), $reader);

		try {
			$events->events('alice', []);
			self::fail('A denied grant reached the reader');
		} catch (ToolFailure $error) {
			self::assertStringContainsString('calendar_list_events', $error->getMessage());
		}
		self::assertSame(0, $reader->calls);
		self::assertSame([['alice', 'calendar', ['read']]], $this->asked);
	}

	public function testADisabledAppNeverReachesTheReader(): void {
		$this->appEnabled = false;
		$reader = new CountingEventListing();
		$events = new CalendarMeetingEvents($this->access(), $reader);

		try {
			$events->events('alice', []);
			self::fail('A disabled app reached the reader');
		} catch (ToolFailure $error) {
			self::assertStringContainsString('calendar_list_events', $error->getMessage());
		}
		self::assertSame(0, $reader->calls);
	}

	public function testAGuestTheAppForbidsNeverReachesTheReader(): void {
		$this->guestAllowed = false;
		$reader = new CountingEventListing();
		$events = new CalendarMeetingEvents($this->access(), $reader);

		try {
			$events->events('alice', []);
			self::fail('A forbidden guest reached the reader');
		} catch (ToolFailure) {
			self::assertSame(0, $reader->calls);
		}
		self::assertContains(['alice', 'calendar'], $this->guestApps);
	}

	public function testAnAllowedCallValidatesThenAsksTheReaderOnce(): void {
		$reader = new CountingEventListing();
		$events = new CalendarMeetingEvents($this->access(), $reader);

		$result = $events->events('alice', ['from' => '2026-11-13']);

		self::assertSame(['events' => true], $result);
		self::assertSame(1, $reader->calls);
		self::assertSame('alice', $reader->userId);
		self::assertSame('2026-11-13', $reader->arguments['from']);
		self::assertSame([['alice', 'calendar', ['read']]], $this->asked);
		self::assertContains(['alice', 'calendar'], $this->guestApps);
	}

	public function testAnUnknownKeyIsRefusedBeforeTheReader(): void {
		$reader = new CountingEventListing();
		$events = new CalendarMeetingEvents($this->access(), $reader);

		try {
			$events->events('alice', ['uid' => 'one-event']);
			self::fail('An unknown key reached the reader');
		} catch (InvalidArgumentException) {
			self::assertSame(0, $reader->calls);
		}
	}

	public function testAnUnreadableGrantPropagatesAndSkipsTheReader(): void {
		$this->unavailable = true;
		$reader = new CountingEventListing();
		$events = new CalendarMeetingEvents($this->access(), $reader);

		$this->expectException(PolicyUnavailable::class);
		try {
			$events->events('alice', []);
		} finally {
			self::assertSame(0, $reader->calls);
		}
	}

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

	public function handle(string $userId, array $arguments): array {
		$this->calls++;
		$this->userId = $userId;
		$this->arguments = $arguments;
		return ['events' => true];
	}
}
