<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCA\Mcp\Tests\Unit\Contract\Oracle\CriticalFlow;
use OCA\Mcp\Tests\Unit\Contract\Oracle\EffectsGate;
use OCA\Mcp\Tests\Unit\Contract\Oracle\FlowRunner;
use OCA\Mcp\Tests\Unit\Tools\Calendar\FakeCalendarDav;
use OCA\Mcp\Tests\Unit\Tools\Calendar\FakeCalendarStore;
use OCA\Mcp\Tests\Unit\Tools\FakeUsers;
use PHPUnit\Framework\TestCase;

/**
 * Effect contract of the Calendar module (RE-01): the creation of an event and the update of an existing one, frozen
 * through the endpoint with a fake calendar store as the observable effect and the CalDAV requests it received.
 */
final class CalendarEffectsContractTest extends TestCase {
	/** Principal of the account every call is made for. */
	private const ALICE = 'principals/users/alice';
	/** Path of the calendar of alice every flow writes to. */
	private const PERSONAL = '/remote.php/dav/calendars/alice/personal/';
	/** Object URI of the event the update flow changes. */
	private const EVENT = 'event.ics';
	/** Why no calendar flow has a visibility step. */
	private const NO_VISIBILITY = 'Calendar objects are not Files nodes: no system tag or excluded folder can hide a '
		. 'calendar or an event from MCP (VisibilityGuard and FilesAccessGuard do not apply). What narrows the reachable '
		. 'calendars is the administrator resource scope and the native ACL, which are scope and permission refusals, '
		. 'not visibility.';

	private FakeCalendarStore $store;
	private RecordingCalendarDav $dav;
	private DavRandomness $random;
	private EffectsGate $gate;

	/** @return void builds the calendars, the event of the update flow and the endpoint */
	protected function setUp(): void {
		$this->store = new FakeCalendarStore();
		$this->store->addCalendar(self::ALICE, 1, 'personal', self::ALICE, ['name' => 'Pessoal']);
		$this->store->addObject(1, self::EVENT, str_replace("\n", "\r\n", "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//test//EN\n"
			. "BEGIN:VEVENT\nUID:event\nDTSTAMP:20260301T100000Z\nSUMMARY:Planejamento\nDTSTART:20261001T120000Z\n"
			. "DTEND:20261001T130000Z\nSEQUENCE:0\nEND:VEVENT\nEND:VCALENDAR\n"));
		$this->dav = new RecordingCalendarDav(new FakeCalendarDav($this->store));
		$this->random = new DavRandomness($this->store, ['event'], ['20260301T100000Z', CalendarEffectsWiring::STAMP],
			DavRandomness::UUID_V4);
		$this->gate = new EffectsGate($this);
		$this->gate->data()->user[EffectsGate::USER]['core']['timezone'] = 'America/Sao_Paulo';
		$users = FakeUsers::manager($this, ['alice' => 'Alice']);
		$this->gate->serve(fn (): array => [CalendarEffectsWiring::module($this, $this->store, $this->dav, $this->gate->config(), $users)]);
	}

	/** @return void calendar_create_event in a calendar of alice: plan, strict confirm, effect and every refusal */
	public function testCreationOfAnEvent(): void {
		$flow = (new CriticalFlow('create-event', 'calendar_create_event', 'calendar', 'create',
			['calendar' => self::PERSONAL, 'summary' => 'Reunião de equipe', 'start' => '2026-03-12T09:00:00-03:00',
				'end' => '2026-03-12T10:00:00-03:00', 'timeZone' => 'America/Sao_Paulo', 'location' => 'Sala 2'],
			fn (): array => CalendarEffectsWiring::state($this->store, $this->dav)))
			->withoutPlanChanged('A creation has no existing object to change between the plan and the confirmation, '
				. 'and the Calendar module keeps no plan fingerprint (no PlanState/PlanChanged): the confirmed call is '
				. 'prepared again from the arguments and writes a new object with a new UID.')
			->withoutVisibility(self::NO_VISIBILITY)
			->normalizing(fn (): array => $this->random->literals());
		(new FlowRunner($this, $this->gate, 'calendar'))->run($flow);
		$body = $this->gate->call($flow->tool, $flow->confirmed($this->gate->call($flow->tool, $flow->arguments())['body']))['body'];
		$this->assertFalse($body->result->isError ?? false, 'a second creation must succeed: ' . json_encode($body));
		$this->assertCount(3, $this->store->objects[1], 'the fixture event and two created events');
		// On the bytes the store holds, before the transcript replaced them: two UUIDs, stamps of now or of the clock.
		$this->random->assertShapes($this);
	}

	/**
	 * calendar_update_event of an existing event, confirmed with the ETag of its plan; a stale ETag is refused with no
	 * write, which is what the module has instead of a PlanChanged answer.
	 *
	 * @return void
	 */
	public function testUpdateOfAnEvent(): void {
		$flow = (new CriticalFlow('update-event', 'calendar_update_event', 'calendar', 'edit',
			['calendar' => self::PERSONAL, 'uid' => 'event', 'summary' => 'Planejamento trimestral', 'location' => 'Sala 3'],
			fn (): array => CalendarEffectsWiring::state($this->store, $this->dav)))
			->confirmingWith(fn (mixed $plan, array $arguments): array => $arguments
				+ ['confirm' => true, 'etag' => $plan->result->structuredContent->etag])
			->withoutPlanChanged('The Calendar module keeps no plan fingerprint (no PlanState/PlanChanged). The plan '
				. 'returns the ETag of the event and the confirmed call carries it: when the event changed in between, '
				. 'the ETag no longer matches and the call is refused as a conflict (If-Match), without a new plan.')
			->withoutVisibility(self::NO_VISIBILITY)
			->normalizing(fn (): array => $this->random->literals());
		(new FlowRunner($this, $this->gate, 'calendar'))->run($flow);
		$this->random->assertShapes($this);
		$this->assertStaleEtagIsRefused($flow);
	}

	/**
	 * The mechanism the update flow has instead of PlanChanged: another client changes the event after the plan, and
	 * the confirmation carrying the ETag of that plan writes nothing.
	 *
	 * @param CriticalFlow $flow the update flow, already run
	 * @return void
	 */
	private function assertStaleEtagIsRefused(CriticalFlow $flow): void {
		$stale = $flow->confirmed($this->gate->call($flow->tool, $flow->arguments())['body']);
		$row = $this->store->objects[1][self::EVENT];
		$this->store->objects[1][self::EVENT] = ['data' => str_replace('SUMMARY:', "COMMENT:outro cliente\r\nSUMMARY:", $row['data']),
			'etag' => '"outro-cliente"'] + $row;
		$before = CalendarEffectsWiring::state($this->store, $this->dav);
		$body = $this->gate->call($flow->tool, $stale)['body'];
		self::assertTrue($body->result->isError ?? false, 'a stale ETag must be refused: ' . json_encode($body));
		self::assertEquals($before, CalendarEffectsWiring::state($this->store, $this->dav), 'a stale ETag must write nothing');
	}
}
