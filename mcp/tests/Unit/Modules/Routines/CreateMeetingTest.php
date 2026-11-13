<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Routines;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Modules\Routines\Meetings\CreateMeetingHandler;
use OCA\Mcp\Modules\Routines\Meetings\CreateMeetingPreview;
use OCA\Mcp\Modules\Routines\Meetings\MeetingPlans;
use OCA\Mcp\Modules\Routines\Tools\CreateMeeting;
use OCA\Mcp\Platform\Meetings\MeetingCalendar;
use OCA\Mcp\Platform\Meetings\MeetingStep;
use OCA\Mcp\Platform\Meetings\MeetingTalk;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * routines_create_meeting over port doubles that log every call in order: a plan logs `plan:<step>`, a write
 * `write:<step>`. The plan of the tool and every refusal of the preflight must log no write; a confirmed meeting writes
 * room, agenda and event once each, in that order, and stops at the first step that is not applied.
 */
final class CreateMeetingTest extends TestCase {
	private MeetingPorts $ports;
	private CreateMeeting $tool;

	protected function setUp(): void {
		$this->ports = new MeetingPorts();
		$plans = new MeetingPlans($this->ports, $this->ports);
		$this->tool = new CreateMeeting(new CreateMeetingHandler($plans, $this->ports, $this->ports), new CreateMeetingPreview($plans));
	}

	/** @return array<string, mixed> arguments of a meeting, as the schema validated them */
	private static function arguments(array $extra = []): array {
		return $extra + ['title' => 'Kickoff', 'participants' => ['bob', 'carla'], 'agenda' => "1. Scope\n2. Dates",
			'calendar' => '/remote.php/dav/calendars/alice/personal/', 'start' => '2026-03-12T14:00:00Z', 'end' => '2026-03-12T15:00:00Z',
			'send_invitations' => true, 'confirm' => true];
	}

	public function testThePlanShowsTheThreeStepsWithTheirDataAndWritesNothing(): void {
		$plan = $this->tool->preview(self::arguments(), new ToolContext('alice'));

		$this->assertSame(['plan:room', 'plan:agenda', 'plan:event'], $this->ports->log);
		$this->assertSame(['room', 'agenda', 'event'], array_column($plan['steps'], 'step'));
		$this->assertSame(['talk_create_group', 'talk_reply', 'calendar_create_event'], array_column($plan['steps'], 'tool'));
		$this->assertSame(['name' => 'Kickoff', 'participants' => ['bob', 'carla']], $plan['steps'][0]['plan']['draft']);
		$this->assertSame("1. Scope\n2. Dates", $plan['steps'][1]['plan']['draft']['message']);
		$this->assertSame(['field' => 'location', 'value' => null], $plan['steps'][2]['room_link']);
		foreach ($plan['steps'] as $step) {
			$this->assertArrayNotHasKey('requiresConfirmation', $step['plan'], 'a step plan must not ask to confirm another tool');
		}
		$event = $this->ports->events[0];
		$this->assertArrayNotHasKey('confirm', $event, 'the single confirmation never reaches the event step');
		$this->assertArrayNotHasKey('location', $event, 'the plan invents no room link');
		$this->assertSame(['bob', 'carla'], $event['attendees']);
		$this->assertSame('Kickoff', $event['summary']);
		$this->assertTrue($event['send_invitations']);
	}

	public function testAnotherOwnersCalendarIsNamedInThePlan(): void {
		$this->ports->shared = true;

		$plan = $this->tool->preview(self::arguments(), new ToolContext('alice'));

		$this->assertStringContainsString('confirm_shared', implode(' ', $plan['notices']));
	}

	public function testTheConfirmedMeetingRunsThePreflightThenWritesEachStepOnceInOrder(): void {
		$result = $this->tool->execute(self::arguments(), new ToolContext('alice'));

		$this->assertSame(['plan:room', 'plan:agenda', 'plan:event', 'write:room', 'write:agenda', 'write:event'], $this->ports->log);
		$this->assertArrayNotHasKey('isError', $result);
		$receipt = json_decode($result['content'][0]['text'], true);
		$this->assertSame('completed', $receipt['status']);
		$this->assertSame([], $receipt['not_done']);
		$this->assertSame('room1234', $this->ports->agendaToken);
		$event = end($this->ports->events);
		$this->assertSame('https://cloud.example/call/room1234', $event['location']);
		$this->assertArrayNotHasKey('confirm', $event);
		$this->assertSame(['conversation_token' => 'room1234'], $receipt['steps'][0]['ids']);
		$this->assertSame('https://cloud.example/call/room1234', $receipt['steps'][0]['url']);
		$this->assertSame(['uid' => 'event-uid'], $receipt['steps'][2]['ids']);
	}

	/** @return array<string, array{string}> */
	public static function refusedSteps(): array {
		return ['room' => ['room'], 'agenda' => ['agenda'], 'event' => ['event']];
	}

	/** A step the actor can no longer run, found by the preflight, refuses the meeting with nothing written. */
	#[DataProvider('refusedSteps')]
	public function testAPreflightRefusalCreatesNothingAndNamesTheStep(string $step): void {
		$this->ports->refusePlan = $step;

		$result = $this->tool->execute(self::arguments(), new ToolContext('alice'));

		$this->assertSame([], array_values(array_filter($this->ports->log, static fn (string $call): bool => str_starts_with($call, 'write:'))));
		$this->assertTrue($result['isError']);
		$receipt = json_decode($result['content'][0]['text'], true);
		$this->assertSame('refused', $receipt['status']);
		$this->assertSame(['room', 'agenda', 'event'], $receipt['not_done']);
		$refused = array_values(array_filter($receipt['steps'], static fn (array $item): bool => $item['step'] === $step))[0];
		$this->assertSame('refused by ' . $step, $refused['message']);
	}

	public function testACalendarOfSomebodyElseWithoutConfirmSharedIsRefusedBeforeTheRoom(): void {
		$this->ports->shared = true;

		$result = $this->tool->execute(self::arguments(), new ToolContext('alice'));

		$this->assertSame(['plan:room', 'plan:agenda', 'plan:event'], $this->ports->log);
		$this->assertTrue($result['isError']);
		$this->assertSame('refused', json_decode($result['content'][0]['text'], true)['status']);

		$this->ports->log = [];
		$created = $this->tool->execute(self::arguments(['confirm_shared' => true]), new ToolContext('alice'));
		$this->assertSame('completed', json_decode($created['content'][0]['text'], true)['status']);
		$this->assertTrue(end($this->ports->events)['confirm_shared']);
	}

	/** @return array<string, array{string, string, list<string>, bool}> step, status, expected writes, error result */
	public static function stops(): array {
		return [
			'room failed: nothing exists' => ['room', MeetingStep::FAILED, ['write:room'], true],
			'room uncertain: it may exist' => ['room', MeetingStep::UNCERTAIN, ['write:room'], false],
			'room partial: invitations missing' => ['room', MeetingStep::PARTIAL, ['write:room'], false],
			'agenda failed after the room' => ['agenda', MeetingStep::FAILED, ['write:room', 'write:agenda'], false],
			'agenda uncertain' => ['agenda', MeetingStep::UNCERTAIN, ['write:room', 'write:agenda'], false],
			'event refused by its sink after the preflight' => ['event', MeetingStep::FAILED, ['write:room', 'write:agenda', 'write:event'], false],
			'event uncertain' => ['event', MeetingStep::UNCERTAIN, ['write:room', 'write:agenda', 'write:event'], false],
		];
	}

	/**
	 * The world may change between the preflight and a write: the meeting stops at the first step its port does not
	 * apply, keeps what exists and starts nothing after it.
	 *
	 * @param list<string> $writes
	 */
	#[DataProvider('stops')]
	public function testTheMeetingStopsAtTheFirstStepThatIsNotApplied(string $step, string $status, array $writes, bool $error): void {
		$this->ports->outcome[$step] = $status;

		$result = $this->tool->execute(self::arguments(), new ToolContext('alice'));

		$this->assertSame($writes, array_values(array_filter($this->ports->log, static fn (string $call): bool => str_starts_with($call, 'write:'))));
		$this->assertSame($error, $result['isError'] ?? false);
		$receipt = json_decode($result['content'][0]['text'], true);
		$this->assertSame('stopped', $receipt['status']);
		$this->assertSame($step, $receipt['stopped_at']);
		$statuses = array_column($receipt['steps'], 'status', 'step');
		$this->assertSame($status, $statuses[$step]);
		$later = array_slice(['room', 'agenda', 'event'], array_search($step, ['room', 'agenda', 'event'], true) + 1);
		foreach ($later as $name) {
			$this->assertSame('not_started', $statuses[$name]);
		}
	}

	public function testARoomWithoutLinkKeepsItsAgendaAndNeverCreatesTheEvent(): void {
		$this->ports->link = null;

		$result = $this->tool->execute(self::arguments(), new ToolContext('alice'));

		$this->assertSame(['write:room', 'write:agenda'], array_values(array_filter($this->ports->log, static fn (string $call): bool => str_starts_with($call, 'write:'))));
		$receipt = json_decode($result['content'][0]['text'], true);
		$this->assertSame('stopped', $receipt['status']);
		$this->assertSame('event', $receipt['stopped_at']);
		$this->assertSame(['conversation_token' => 'room1234'], $receipt['steps'][0]['ids']);
		$this->assertNull($receipt['steps'][0]['url']);
		$this->assertSame('not_started', $receipt['steps'][2]['status']);
		$this->assertSame(['event'], $receipt['not_done']);
	}
}

/** Both meeting ports, logging every call; each plan and each write can be made to answer otherwise. */
final class MeetingPorts implements MeetingTalk, MeetingCalendar {
	/** @var list<string> */
	public array $log = [];
	/** @var list<array<string, mixed>> event arguments received, plans and writes */
	public array $events = [];
	public ?string $refusePlan = null;
	public bool $shared = false;
	/** @var array<string, string> step => status its write answers */
	public array $outcome = [];
	public ?string $link = 'https://cloud.example/call/room1234';
	public ?string $agendaToken = null;

	public function planRoom(string $userId, string $name, array $participants): array {
		$this->plan('room');
		return ['requiresConfirmation' => true, 'action' => 'talk_create_group', 'draft' => ['name' => $name, 'participants' => $participants], 'message' => 'confirm group'];
	}

	public function createRoom(string $userId, string $name, array $participants): MeetingStep {
		$this->log[] = 'write:room';
		$result = ['conversation_token' => 'room1234', 'url' => $this->link, 'invitations_failed' => []];
		return match ($this->outcome['room'] ?? MeetingStep::APPLIED) {
			MeetingStep::FAILED => MeetingStep::failed('no room'),
			MeetingStep::UNCERTAIN => MeetingStep::uncertain('maybe a room'),
			MeetingStep::PARTIAL => MeetingStep::partial(['conversation_token' => 'room1234'], $result, 'invitation failed'),
			default => MeetingStep::applied(['conversation_token' => 'room1234'], $result, $this->link === null ? 'no link' : null),
		};
	}

	public function planAgenda(string $userId, string $message): array {
		$this->plan('agenda');
		return ['action' => 'talk_reply', 'conversation' => null, 'draft' => ['message' => $message]];
	}

	public function postAgenda(string $userId, string $token, string $message): MeetingStep {
		$this->log[] = 'write:agenda';
		$this->agendaToken = $token;
		return $this->outcome('agenda', ['conversation_token' => $token, 'messageId' => 7]);
	}

	public function planEvent(string $userId, array $event): array {
		$this->plan('event');
		$this->events[] = $event;
		return ['requiresConfirmation' => true, 'message' => 'confirm event', 'calendar' => ['name' => 'Personal'],
			'after' => ['start' => $event['start'], 'end' => $event['end']], 'shared' => $this->shared ? [['owner' => 'bob']] : []];
	}

	public function createEvent(string $userId, array $event): MeetingStep {
		$this->log[] = 'write:event';
		$this->events[] = $event;
		return $this->outcome('event', ['uid' => 'event-uid']);
	}

	/** @param string $step planned step */
	private function plan(string $step): void {
		$this->log[] = 'plan:' . $step;
		if ($this->refusePlan === $step) {
			throw new ToolFailure('refused by ' . $step);
		}
	}

	/** @param array<string, string|int> $ids */
	private function outcome(string $step, array $ids): MeetingStep {
		return match ($this->outcome[$step] ?? MeetingStep::APPLIED) {
			MeetingStep::FAILED => MeetingStep::failed($step . ' refused'),
			MeetingStep::UNCERTAIN => MeetingStep::uncertain($step . ' unknown', $ids),
			default => MeetingStep::applied($ids, $ids),
		};
	}
}
