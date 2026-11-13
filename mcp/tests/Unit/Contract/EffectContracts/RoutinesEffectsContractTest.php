<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Modules\Routines\Meetings\CreateMeetingHandler;
use OCA\Mcp\Modules\Routines\Meetings\CreateMeetingPreview;
use OCA\Mcp\Modules\Routines\Meetings\MeetingPlans;
use OCA\Mcp\Modules\Routines\RoutinesModule;
use OCA\Mcp\Modules\Routines\Tools\CreateMeeting;
use OCA\Mcp\Tests\Unit\Contract\Oracle\CriticalFlow;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Contract\Oracle\FlowRunner;
use OCA\Mcp\Tests\Unit\Tools\Calendar\FakeCalendarDav;
use OCA\Mcp\Tests\Unit\Tools\Calendar\FakeCalendarStore;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * routines_create_meeting through the real endpoint, the real meeting ports of Talk and Calendar and their real work
 * classes, over the native Talk doubles and the calendar store. The observable effect is everything both apps hold:
 * rooms, invitations, messages and calendar objects with the CalDAV requests. The plan and every refusal must leave all
 * of it unchanged; the confirmed call creates the room, its agenda and then the event whose LOCATION is the room link.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RoutinesEffectsContractTest extends TestCase {
	/** Principal of the account every call is made for. */
	private const ALICE = 'principals/users/alice';

	private EffectsGate $gate;
	private TalkWritesWiring $talk;
	private FakeCalendarStore $store;
	private RecordingCalendarDav $dav;
	private DavRandomness $random;

	/** @return void Talk with its native doubles, alice's personal calendar and the endpoint serving the routines */
	protected function setUp(): void {
		$this->gate = new EffectsGate($this);
		$this->gate->data()->user[EffectsGate::USER]['core']['timezone'] = 'America/Sao_Paulo';
		$this->talk = new TalkWritesWiring($this, $this->gate);
		$this->store = new FakeCalendarStore();
		$this->store->addCalendar(self::ALICE, 1, 'personal', self::ALICE, ['name' => 'Pessoal']);
		$this->dav = new RecordingCalendarDav(new FakeCalendarDav($this->store));
		$this->random = new DavRandomness($this->store, [], [CalendarEffectsWiring::STAMP], DavRandomness::UUID_V4);
		$users = $this->users();
		$this->gate->serve(fn (): array => [$this->module($users)]);
	}

	/** @return void the meeting: plan without effect, strict confirm, three effects in order and every refusal */
	public function testCreatingAMeeting(): void {
		$flow = (new CriticalFlow('create-meeting', 'routines_create_meeting', 'routines', 'meeting',
			// One day later per event already created, so no plan meets an overlap (its warning needs ext-intl).
			fn (): array => ['title' => 'Kickoff', 'participants' => ['pedro'], 'agenda' => "1. Scope\n2. Dates",
				'calendar' => '/remote.php/dav/calendars/alice/personal/',
				'start' => sprintf('2026-03-%02dT09:00:00-03:00', 11 + count($this->store->objects[1] ?? [])),
				'end' => sprintf('2026-03-%02dT10:00:00-03:00', 11 + count($this->store->objects[1] ?? []))],
			fn (): array => ['talk' => $this->talk->state(), 'calendar' => CalendarEffectsWiring::state($this->store, $this->dav)]))
			->withoutPlanChanged('The meeting keeps no plan fingerprint: the confirmed call plans every step again before the room '
				. 'exists and each port checks again in its own sink; a change refuses the meeting or stops it at that step.')
			->withoutVisibility('A meeting creates a new room and a new event; it reads no Files node that a tag or excluded folder could hide.')
			->normalizing(fn (): array => $this->random->literals());
		(new FlowRunner($this, $this->gate, 'routines'))->run($flow);

		$this->assertCount(1, $this->talk->creation->created);
		$this->assertSame("1. Scope\n2. Dates", $this->talk->chat->messages[0]['message']);
		$event = $this->store->objects[1][array_key_first($this->store->objects[1])]['data'];
		$this->assertStringContainsString('LOCATION:https://cloud.example/index.php/call/group000', $event);
	}

	/** @return void with calendar.create revoked after the plan, the preflight refuses the meeting before any room */
	public function testARevokedEventStepRefusesTheMeetingBeforeTheRoom(): void {
		$arguments = ['title' => 'Kickoff', 'participants' => ['pedro'], 'agenda' => 'Scope',
			'calendar' => '/remote.php/dav/calendars/alice/personal/', 'start' => '2026-03-11T09:00:00-03:00', 'end' => '2026-03-11T10:00:00-03:00'];
		$plan = $this->gate->call('routines_create_meeting', $arguments);
		$this->assertTrue($plan['body']->result->structuredContent->requiresConfirmation ?? false, json_encode($plan['body']));

		$this->gate->grant('calendar', 'create', false);
		$answer = $this->gate->call('routines_create_meeting', $arguments + ['confirm' => true]);

		$this->assertTrue($answer['body']->result->isError ?? false, json_encode($answer['body']));
		$receipt = json_decode($answer['body']->result->content[0]->text, true);
		$this->assertSame('refused', $receipt['status']);
		$this->assertSame([], $this->talk->creation->created);
		$this->assertSame([], $this->talk->chat->messages);
		$this->assertSame([], $this->dav->log);
	}

	/**
	 * @param IUserManager $users the accounts with e-mail addresses, as the event guests need them
	 * @return RoutinesModule the module of one request over the real ports
	 */
	private function module(IUserManager $users): RoutinesModule {
		$access = $this->gate->access();
		$plans = new MeetingPlans($this->talk->meetingSteps($access),
			CalendarEffectsWiring::meetingSteps($this, $this->store, $this->dav, $this->gate->config(), $users, $access));
		$tool = new CreateMeeting(new CreateMeetingHandler($plans, $this->talk->meetingSteps($access),
			CalendarEffectsWiring::meetingSteps($this, $this->store, $this->dav, $this->gate->config(), $users, $access)), new CreateMeetingPreview($plans));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => $id === CreateMeeting::class ? $tool
			: throw new \LogicException('Unexpected service ' . $id));
		return new RoutinesModule($container);
	}

	/** @return IUserManager alice and pedro, enabled and with an e-mail address */
	private function users(): IUserManager {
		$known = [];
		foreach (['alice' => 'Alice', 'pedro' => 'Paulo Exemplo'] as $uid => $name) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('getDisplayName')->willReturn($name);
			$user->method('isEnabled')->willReturn(true);
			$user->method('getEMailAddress')->willReturn($uid . '@example.invalid');
			$known[$uid] = $user;
		}
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(static fn (string $uid): ?IUser => $known[$uid] ?? null);
		return $users;
	}
}
