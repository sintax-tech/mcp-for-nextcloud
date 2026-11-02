<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use DateTimeImmutable;
use OCA\Mcp\Platform\Time\UserTimezone;
use OCA\Mcp\Tests\Unit\Tools\Calendar\FakeCalendarStore;
use OCA\Mcp\Tests\Unit\Tools\Calendar\ScopeStub;
use OCA\Mcp\Modules\Calendar\Composing\AttendeeResolver;
use OCA\Mcp\Modules\Calendar\Writing\CalendarDav;
use OCA\Mcp\Modules\Calendar\Approval\CalendarDraftApproval;
use OCA\Mcp\Modules\Calendar\CalendarModule;
use OCA\Mcp\Modules\Calendar\Presentation\CalendarPlanRenderer;
use OCA\Mcp\Modules\Calendar\Locating\Classification;
use OCA\Mcp\Modules\Calendar\Composing\DateInput;
use OCA\Mcp\Modules\Calendar\Composing\EventBuilder;
use OCA\Mcp\Modules\Calendar\Locating\EventExpander;
use OCA\Mcp\Modules\Calendar\Presentation\EventMapper;
use OCA\Mcp\Modules\Calendar\Moving\EventRelocator;
use OCA\Mcp\Modules\Calendar\Locating\EventRepository;
use OCA\Mcp\Modules\Calendar\Creation\CreateEventDraft;
use OCA\Mcp\Modules\Calendar\Deletion\DeleteEventDraft;
use OCA\Mcp\Modules\Calendar\Reading\ListCalendarsHandler;
use OCA\Mcp\Modules\Calendar\Reading\ListEventsHandler;
use OCA\Mcp\Modules\Calendar\Moving\MoveEventDraft;
use OCA\Mcp\Modules\Calendar\Moving\TransferEventDraft;
use OCA\Mcp\Modules\Calendar\Editing\UpdateEventDraft;
use OCA\Mcp\Modules\Calendar\Scheduling\Scheduling;
use OCA\Mcp\Modules\Calendar\Scheduling\AvailabilityCheck;
use OCA\Mcp\Modules\Calendar\Scheduling\CollisionCheck;
use OCA\Mcp\Modules\Calendar\Scheduling\PlanWarnings;
use OCA\Mcp\Modules\Calendar\Scheduling\SharedCalendarFinder;
use OCA\Mcp\Modules\Calendar\Approval\SharedGuard;
use OCA\Mcp\Modules\Calendar\Writing\TrashPolicy;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Calendar\IManager;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The Calendar module of one request, wired as the container autowires it (every handler, the draft approval with its
 * plan warnings and the account timezone, the plan renderer) over a persistent {@see FakeCalendarStore} and a CalDAV
 * double. The clock is frozen, so an object the module builds carries the same DTSTAMP on every run.
 */
final class CalendarEffectsWiring {
	/** The moment every request of the effect contracts happens at. */
	public const NOW = '2026-03-10T12:00:00Z';
	/** {@see self::NOW} as an iCalendar DTSTAMP. */
	public const STAMP = '20260310T120000Z';

	/** Static builder only. */
	private function __construct() {
	}

	/**
	 * @param TestCase $test the running test, owner of the mocks
	 * @param FakeCalendarStore $store the calendars and objects, persistent across requests
	 * @param CalendarDav $dav the write pipeline double
	 * @param IConfig $config the configuration of the gate (account timezone, calendar retention)
	 * @param IUserManager $users the accounts the module resolves owners and guests with
	 * @return CalendarModule the module of one request
	 */
	public static function module(TestCase $test, FakeCalendarStore $store, CalendarDav $dav, IConfig $config, IUserManager $users): CalendarModule {
		$time = self::mock($test, ITimeFactory::class);
		$time->method('now')->willReturnCallback(static fn (): DateTimeImmutable => new DateTimeImmutable(self::NOW));
		$appConfig = self::mock($test, IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);
		$logger = new NullLogger();
		$access = ScopeStub::access($store);
		$classification = new Classification();
		$repository = new EventRepository($store, $classification);
		$dates = new DateInput();
		$mapper = new EventMapper();
		$builder = new EventBuilder();
		$guard = new SharedGuard($users);
		$attendees = new AttendeeResolver($users);
		$scheduling = new Scheduling($appConfig);
		$relocator = new EventRelocator($access, $store, $dav, $repository);
		$zones = new UserTimezone($config);
		$availability = self::mock($test, IManager::class);
		$availability->method('checkAvailability')->willReturn([]);
		$groups = self::mock($test, IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn([]);
		$warnings = new PlanWarnings(
			new CollisionCheck($store, $repository, $classification, new EventExpander(), $logger),
			new AvailabilityCheck($availability, $users, $logger),
			new SharedCalendarFinder($access, $store, $users, $groups),
			$attendees,
			$builder,
			$users,
			$logger,
			$zones,
		);
		return new CalendarModule(
			new ListCalendarsHandler($access),
			new ListEventsHandler($access, $store, $repository, $classification, new EventExpander(), $mapper, $dates, $time, $logger),
			new CreateEventDraft($access, $guard, $dav, $store, $repository, $builder, $mapper, $dates, $attendees, $scheduling, $time),
			new UpdateEventDraft($access, $guard, $dav, $store, $repository, $builder, $mapper, $dates, $attendees, $scheduling, $time),
			new MoveEventDraft($access, $relocator, $guard),
			new DeleteEventDraft($access, $guard, $dav, $store, $repository, new TrashPolicy($config), $scheduling),
			new TransferEventDraft($relocator),
			new CalendarDraftApproval($scheduling, $builder, $guard, $warnings, $zones),
			new CalendarPlanRenderer(),
		);
	}

	/**
	 * @param FakeCalendarStore $store the store of the contract
	 * @param RecordingCalendarDav $dav the request log of the contract
	 * @return array{objects: array<int, list<string>>, dav: list<string>} live iCalendar text per calendar id, in
	 *     storage order and without the random object names, and the dispatched requests
	 */
	public static function state(FakeCalendarStore $store, RecordingCalendarDav $dav): array {
		$objects = [];
		foreach ($store->objects as $calendarId => $rows) {
			$objects[$calendarId] = array_values(array_map(static fn (array $row): string => $row['data'],
				array_filter($rows, static fn (array $row): bool => !$row['deleted'])));
		}
		return ['objects' => $objects, 'dav' => $dav->log];
	}

	/**
	 * @param TestCase $test the running test
	 * @param class-string $class type to mock
	 * @return MockObject mock owned by the running test
	 */
	private static function mock(TestCase $test, string $class): MockObject {
		return (new \ReflectionMethod($test, 'createMock'))->invoke($test, $class);
	}
}
