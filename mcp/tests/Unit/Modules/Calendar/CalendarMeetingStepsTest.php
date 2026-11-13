<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Calendar;

use InvalidArgumentException;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Mcp\Tools\Discovery\CapabilityRegistry;
use OCA\Mcp\Mcp\Tools\Discovery\ToolAccess;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Modules\Calendar\CalendarMeetingSteps;
use OCA\Mcp\Modules\Calendar\Creation\CreateEventHandler;
use OCA\Mcp\Modules\Calendar\Creation\CreateEventPreview;
use OCA\Mcp\Platform\Workflows\WorkflowStep;
use OCA\Mcp\Tests\Unit\Tools\Calendar\CalendarTestCase;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The event step of a meeting goes through the very plan and effect of calendar_create_event.
 *
 * Every DAV write of the module is counted by FakeCalendarDav before it answers: a plan and every refusal leave the
 * count at zero, and a confirmed step makes exactly the one PUT calendar_create_event makes. The grant, Guests and
 * app decision is the registry's own ToolAccess, asked for calendar_create_event.
 */
final class CalendarMeetingStepsTest extends CalendarTestCase {
	/** Whether the actor holds calendar.create. */
	private bool $granted = true;
	/** Whether the Calendar app is enabled for the actor. */
	private bool $appEnabled = true;
	/** @var list<array{string, string, list<string>}> grant questions asked, in order */
	private array $asked = [];
	private CalendarMeetingSteps $steps;

	protected function setUp(): void {
		parent::setUp();
		$policy = $this->createMock(GrantPolicy::class);
		$policy->method('grantedAny')->willReturnCallback(function (string $uid, string $module, array $operations): bool {
			$this->asked[] = [$uid, $module, $operations];
			return $this->granted;
		});
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturnCallback(fn (string $app): bool => $app === 'calendar' && $this->appEnabled);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid): IUser => $this->createMock(IUser::class));
		$access = new ToolAccess($policy, $apps, $users, null, new CapabilityRegistry([], new NullLogger()));
		$draft = $this->writeHandlers['calendar_create_event'];
		$this->steps = new CalendarMeetingSteps($access, new CreateEventPreview($this->approval, $draft), new CreateEventHandler($this->approval, $draft), new NullLogger());
	}

	/** @return array<string, mixed> arguments of calendar_create_event */
	private static function event(array $extra = []): array {
		return $extra + ['calendar' => self::PERSONAL, 'summary' => 'Reunião', 'start' => '2026-03-12T14:00:00-03:00', 'end' => '2026-03-12T15:00:00-03:00', 'location' => 'https://cloud.example/call/abc12345'];
	}

	public function testThePlanIsTheOneOfCalendarCreateEventAndWritesNothing(): void {
		$plan = $this->steps->planEvent('alice', self::event());

		$this->assertSame([], $this->dav->calls);
		$this->assertSame($this->module->preview('calendar_create_event', self::event(), 'alice'), $plan);
		$this->assertSame('https://cloud.example/call/abc12345', $plan['after']['location']);
		$this->assertSame([['alice', 'calendar', ['create']]], $this->asked);
	}

	public function testTheConfirmedStepMakesTheSinglePutOfCalendarCreateEvent(): void {
		$step = $this->steps->createEvent('alice', self::event());

		$this->assertSame(WorkflowStep::APPLIED, $step->status, (string)$step->message);
		$this->assertCount(1, $this->dav->calls);
		[$operation, [$calendar, $object]] = $this->dav->calls[0];
		$this->assertSame(['put', 'personal', $step->ids['uid'] . '.ics'], [$operation, $calendar, $object]);
		$this->assertSame($step->ids['uid'], $step->result['uid']);
		$this->assertNotEmpty($step->result['etag']);
	}

	/** @return array<string, array{\Closure(self): void}> */
	public static function refusedBeforeTheWrite(): array {
		return [
			'calendar.create not granted' => [static function (self $test): void { $test->granted = false; }],
			'Calendar app off for the actor' => [static function (self $test): void { $test->appEnabled = false; }],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('refusedBeforeTheWrite')]
	public function testTheRegistryGateRefusesBothThePlanAndTheStep(\Closure $deny): void {
		$deny($this);

		$step = $this->steps->createEvent('alice', self::event());
		$this->assertSame(WorkflowStep::FAILED, $step->status);
		$this->assertStringContainsString('calendar_create_event', (string)$step->message);
		$this->expectException(ToolFailure::class);
		try {
			$this->steps->planEvent('alice', self::event());
		} finally {
			$this->assertSame([], $this->dav->calls);
		}
	}

	public function testAReadOnlyCalendarIsRefusedByItsOwnAclWithNothingWritten(): void {
		$step = $this->steps->createEvent('alice', self::event(['calendar' => self::READONLY]));

		$this->assertSame(WorkflowStep::FAILED, $step->status);
		$this->assertSame([], $this->dav->calls);
	}

	public function testACalendarOfSomebodyElseNeedsConfirmShared(): void {
		$refused = $this->steps->createEvent('alice', self::event(['calendar' => self::TEAM]));
		$this->assertSame(WorkflowStep::FAILED, $refused->status);
		$this->assertSame([], $this->dav->calls);

		$created = $this->steps->createEvent('alice', self::event(['calendar' => self::TEAM, 'confirm_shared' => true]));
		$this->assertSame(WorkflowStep::APPLIED, $created->status, (string)$created->message);
		$this->assertCount(1, $this->dav->calls);
	}

	public function testArgumentsAreCheckedAgainstThePublishedSchemaBeforeAnything(): void {
		$this->expectException(InvalidArgumentException::class);
		try {
			$this->steps->createEvent('alice', ['calendar' => self::PERSONAL, 'summary' => 'x', 'start' => '2026-03-12T14:00:00Z', 'end' => '2026-03-12T15:00:00Z', 'confirm' => true]);
		} finally {
			$this->assertSame([], $this->dav->calls);
		}
	}

	public function testAFailureOfTheWriteItselfIsUncertainAndNeverRetried(): void {
		$this->dav->failureFor['put'] = new RuntimeException('pipeline broke after storing');

		$step = $this->steps->createEvent('alice', self::event());

		$this->assertSame(WorkflowStep::UNCERTAIN, $step->status);
		$this->assertCount(1, $this->dav->calls);
	}
}
