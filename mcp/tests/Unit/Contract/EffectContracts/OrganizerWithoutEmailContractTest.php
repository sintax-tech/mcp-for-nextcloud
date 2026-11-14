<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Modules\Calendar\Presentation\CalendarMessages;
use OCA\Mcp\Modules\Routines\Meetings\CreateMeetingHandler;
use OCA\Mcp\Modules\Routines\Meetings\CreateMeetingPreview;
use OCA\Mcp\Modules\Routines\Meetings\MeetingPlans;
use OCA\Mcp\Modules\Routines\RoutinesModule;
use OCA\Mcp\Modules\Routines\Tools\CreateMeeting;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Tools\Calendar\FakeCalendarDav;
use OCA\Mcp\Tests\Unit\Tools\Calendar\FakeCalendarStore;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * An organizer without an e-mail cannot invite guests. Through the endpoint, calendar_create_event and
 * routines_create_meeting refuse the plan and the confirmed call with that reason, not a bare "Invalid arguments",
 * naming nothing of the guest, and write nothing to Talk or to the calendar.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class OrganizerWithoutEmailContractTest extends TestCase {
	/** Principal of the account every call is made for. */
	private const ALICE = 'principals/users/alice';
	/** Calendar of alice the event would go to. */
	private const PERSONAL = '/remote.php/dav/calendars/alice/personal/';

	private EffectsGate $gate;
	private TalkWritesWiring $talk;
	private FakeCalendarStore $store;
	private RecordingCalendarDav $dav;

	/** @return void alice without an e-mail, pedro with one, Talk doubles and alice's personal calendar */
	protected function setUp(): void {
		$this->gate = new EffectsGate($this);
		$this->gate->data()->user[EffectsGate::USER]['core']['timezone'] = 'America/Sao_Paulo';
		$this->talk = new TalkWritesWiring($this, $this->gate);
		$this->store = new FakeCalendarStore();
		$this->store->addCalendar(self::ALICE, 1, 'personal', self::ALICE, ['name' => 'Pessoal']);
		$this->dav = new RecordingCalendarDav(new FakeCalendarDav($this->store));
		$users = $this->users();
		$this->gate->serve(fn (): array => [
			CalendarEffectsWiring::module($this, $this->store, $this->dav, $this->gate->config(), $users),
			$this->meetings($users),
		]);
	}

	/** @return void both tools, plan and confirmed call, give the reason and leave Talk and the calendar untouched */
	public function testTheRefusalNamesItsReasonAndWritesNothing(): void {
		$when = ['start' => '2026-03-11T09:00:00-03:00', 'end' => '2026-03-11T10:00:00-03:00'];
		$calls = [
			'calendar_create_event' => ['calendar' => self::PERSONAL, 'summary' => 'Kickoff', 'attendees' => ['pedro']] + $when,
			'routines_create_meeting' => ['title' => 'Kickoff', 'participants' => ['pedro'], 'agenda' => 'Scope', 'calendar' => self::PERSONAL] + $when,
		];
		$talk = $this->talk->state();
		$calendar = CalendarEffectsWiring::state($this->store, $this->dav);
		foreach ($calls as $tool => $arguments) {
			foreach ([$arguments, $arguments + ['confirm' => true]] as $call) {
				$answer = $this->gate->call($tool, $call)['body'];
				$trace = $tool . ' ' . json_encode($answer);
				self::assertSame(-32602, $answer->error->code ?? null, $trace);
				self::assertSame('arguments: ' . CalendarMessages::organizerWithoutEmail(), $answer->error->message, $trace);
				self::assertSame(['field' => 'arguments', 'rule' => CalendarMessages::organizerWithoutEmail()], (array)$answer->error->data, $trace);
				self::assertStringNotContainsString('pedro', json_encode($answer), $trace);
			}
		}
		self::assertSame($talk, $this->talk->state());
		self::assertSame($calendar, CalendarEffectsWiring::state($this->store, $this->dav));
		self::assertSame([], $this->dav->log);
		self::assertSame([], $this->talk->creation->created);
	}

	/**
	 * @param IUserManager $users the accounts of this test
	 * @return RoutinesModule the meeting routine over the real Talk and Calendar ports
	 */
	private function meetings(IUserManager $users): RoutinesModule {
		$access = $this->gate->access();
		$talk = $this->talk->meetingSteps($access);
		$calendar = CalendarEffectsWiring::meetingSteps($this, $this->store, $this->dav, $this->gate->config(), $users, $access);
		$plans = new MeetingPlans($talk, $calendar);
		$tool = new CreateMeeting(new CreateMeetingHandler($plans, $talk, $calendar, new NullLogger()), new CreateMeetingPreview($plans));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => $id === CreateMeeting::class ? $tool
			: throw new \LogicException('Unexpected service ' . $id));
		return new RoutinesModule($container);
	}

	/** @return IUserManager alice enabled without an e-mail and pedro enabled with one */
	private function users(): IUserManager {
		$known = [];
		foreach (['alice' => null, 'pedro' => 'pedro@example.invalid'] as $uid => $email) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('getDisplayName')->willReturn(ucfirst($uid));
			$user->method('isEnabled')->willReturn(true);
			$user->method('getEMailAddress')->willReturn($email);
			$known[$uid] = $user;
		}
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(static fn (string $uid): ?IUser => $known[$uid] ?? null);
		return $users;
	}
}
