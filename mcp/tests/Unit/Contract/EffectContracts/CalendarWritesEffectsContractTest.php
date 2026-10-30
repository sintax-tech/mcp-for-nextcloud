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
use PHPUnit\Framework\TestCase;

/**
 * Effect contract of the Calendar writes that move, delete, transfer and invite (RE-01), frozen through the endpoint over
 * {@see CalendarWritesScene}: the live and trashed objects of every calendar, each CalDAV request with its If-Match and
 * scheduling flag, and what the scheduler was handed.
 *
 * No flow has a PlanChanged step: the Calendar module keeps no plan fingerprint. What it has instead is the ETag of the
 * plan, which every confirmation here carries; each test proves directly that a stale one writes nothing.
 */
final class CalendarWritesEffectsContractTest extends TestCase {
	/** Why the Calendar flows have no PlanChanged step. */
	private const NO_PLAN_CHANGED = 'The Calendar module keeps no plan fingerprint (no PlanState/PlanChanged). The plan '
		. 'returns the ETag of the event and the confirmed call carries it: when the event changed in between, the ETag '
		. 'no longer matches and the call is refused as a conflict (If-Match), without a new plan.';

	/** The calendars, the CalDAV double and the scheduler stand-in. */
	private CalendarWritesScene $scene;
	/** The endpoint the flows go through. */
	private EffectsGate $gate;

	/** @return void builds the scene and the endpoint */
	protected function setUp(): void {
		$this->scene = new CalendarWritesScene();
		$this->gate = new EffectsGate($this);
		$this->gate->data()->user[EffectsGate::USER]['core']['timezone'] = 'America/Sao_Paulo';
		$users = CalendarWritesScene::users($this);
		$this->gate->serve(fn (): array => [CalendarEffectsWiring::module($this, $this->scene->store, $this->scene->dav, $this->gate->config(), $users)]);
	}

	/** @return void calendar_move_event between two calendars of alice, back and forth, with the ETag of each plan */
	public function testMovingAnEvent(): void {
		$this->scene->event(1, 'quinzenal', 'Reunião quinzenal');
		$flow = $this->flow('move-event', 'calendar_move_event', 'move', function (): array {
			$inPersonal = $this->scene->calendarOf('quinzenal') === 1;
			return ['calendar' => $inPersonal ? CalendarWritesScene::PERSONAL : CalendarWritesScene::WORK, 'uid' => 'quinzenal',
				'targetCalendar' => $inPersonal ? CalendarWritesScene::WORK : CalendarWritesScene::PERSONAL];
		});
		(new FlowRunner($this, $this->gate, 'calendar'))->run($flow);
		$this->assertSame(2, $this->scene->calendarOf('quinzenal'), 'the confirmed move left the event in the work calendar');
		$this->assertSame(['operation' => 'move', 'calendar' => 'personal', 'object' => 'quinzenal.ics', 'target' => 'work',
			'etag' => $this->etagOfFixture('quinzenal')], $this->scene->state()['dav'][0], 'one MOVE with the If-Match of the plan');
		$this->assertSame([], $this->scene->scheduled, 'a move never schedules anything');
		$this->assertStaleEtagIsRefused($flow, 'quinzenal');
	}

	/** @return void calendar_delete_event of an event with a guest, cancellation requested, into the CalDAV trash */
	public function testDeletingAnEvent(): void {
		foreach (['cancelada-1', 'cancelada-2', 'cancelada-3'] as $uid) {
			$this->scene->event(1, $uid, 'Visita ' . $uid, ['bob']);
		}
		$etag = $this->etagOfFixture('cancelada-1');
		// Each step asks for the first event still alive, so every refusal is asked with a confirmation valid right now.
		$flow = $this->flow('delete-event', 'calendar_delete_event', 'delete', fn (): array => ['calendar' => CalendarWritesScene::PERSONAL,
			'uid' => current(array_filter(['cancelada-1', 'cancelada-2', 'cancelada-3'], fn (string $uid): bool => $this->scene->calendarOf($uid) !== null)),
			'send_invitations' => true]);
		(new FlowRunner($this, $this->gate, 'calendar'))->run($flow);
		$state = $this->scene->state();
		$this->assertArrayHasKey('cancelada-1-deleted.ics', $state['trash'][1], 'the deleted event went to the trash, not purged');
		$this->assertNull($this->scene->calendarOf('cancelada-1'));
		$this->assertSame(['operation' => 'delete', 'calendar' => 'personal', 'object' => 'cancelada-1.ics', 'etag' => $etag, 'scheduling' => true],
			$state['dav'][0], 'one DELETE with the If-Match of the plan and the scheduling flag asked for');
		$this->assertSame(['DELETE cancelada-1: mailto:bob@example.invalid'], $this->scene->scheduled, 'the cancellation reached the scheduler');
		$result = $this->confirmNow($flow);
		$this->assertSame(['uid' => 'cancelada-2', 'deleted' => true, 'recoverable' => true, 'requested' => true],
			['uid' => $result['uid'], 'deleted' => $result['deleted'], 'recoverable' => $result['recoverable'], 'requested' => $result['scheduling']['requested']]);
		$this->assertStaleEtagIsRefused($flow, 'cancelada-3');
	}

	/**
	 * calendar_transfer_event to the calendar bob shares with alice and back, acknowledged with confirm_shared; without
	 * it the confirmed call writes nothing.
	 *
	 * @return void
	 */
	public function testTransferringAnEvent(): void {
		$this->scene->event(1, 'entrega', 'Entrega do relatório');
		$flow = $this->flow('transfer-event', 'calendar_transfer_event', 'transfer', function (): array {
			$inPersonal = $this->scene->calendarOf('entrega') === 1;
			return ['calendar' => $inPersonal ? CalendarWritesScene::PERSONAL : CalendarWritesScene::TEAM, 'uid' => 'entrega',
				'targetCalendar' => $inPersonal ? CalendarWritesScene::TEAM : CalendarWritesScene::PERSONAL, 'confirm_shared' => true];
		});
		(new FlowRunner($this, $this->gate, 'calendar'))->run($flow);
		$this->assertSame(3, $this->scene->calendarOf('entrega'), 'the confirmed transfer left the event in the calendar of bob');
		$this->assertSame(['operation' => 'move', 'calendar' => 'personal', 'object' => 'entrega.ics', 'target' => 'team_shared_by_bob',
			'etag' => $this->etagOfFixture('entrega')], $this->scene->state()['dav'][0], 'one MOVE with the If-Match of the plan');
		$unacknowledged = $flow->confirmed($this->gate->call($flow->tool, $flow->arguments())['body']);
		unset($unacknowledged['confirm_shared']);
		$before = $this->scene->state();
		$body = $this->gate->call($flow->tool, $unacknowledged)['body'];
		$this->assertTrue($body->result->isError ?? false, 'a transfer touching a shared calendar needs confirm_shared: ' . json_encode($body));
		$this->assertEquals($before, $this->scene->state(), 'an unacknowledged transfer writes nothing');
		$this->assertStaleEtagIsRefused($flow, 'entrega');
	}

	/**
	 * calendar_update_event changing the guests with send_invitations: the update reaches CalDAV with the scheduling
	 * flag on, the scheduler marks each guest, and the result reports what the server left on them.
	 *
	 * @return void
	 */
	public function testInvitingGuestsToAnEvent(): void {
		$this->scene->event(1, 'revisao', 'Revisão do contrato', ['bob']);
		$flow = $this->flow('invite-guests', 'calendar_update_event', 'edit', ['calendar' => CalendarWritesScene::PERSONAL, 'uid' => 'revisao',
			'attendees' => ['bob', 'carla'], 'send_invitations' => true]);
		(new FlowRunner($this, $this->gate, 'calendar'))->run($flow);
		$update = $this->scene->state()['dav'][0];
		$this->assertSame(['update', 'personal', 'revisao.ics', $this->etagOfFixture('revisao'), true],
			[$update['operation'], $update['calendar'], $update['object'], $update['etag'], $update['scheduling']], 'one update with the flag on');
		$this->assertStringContainsString("ATTENDEE;CN=Carla Dias", $update['ics'], 'the new guest was sent to CalDAV');
		$this->assertSame(['PUT revisao: mailto:bob@example.invalid, mailto:carla@example.invalid'], $this->scene->scheduled);
		$this->assertSame(2, substr_count($this->scene->store->objects[1]['revisao.ics']['data'], 'SCHEDULE-STATUS=1.2'));
		$result = $this->confirmNow($flow);
		$this->assertSame(['requested' => true, 'imipEnabled' => true, 'statuses' => ['1.2', '1.2']], ['requested' => $result['scheduling']['requested'],
			'imipEnabled' => $result['scheduling']['imipEnabled'], 'statuses' => array_column($result['scheduling']['participants'], 'scheduleStatus')]);
	}

	/**
	 * @param string $name flow name
	 * @param string $tool tool under test
	 * @param string $operation its grant operation
	 * @param array<string, mixed>|\Closure(): array<string, mixed> $arguments arguments of the plan
	 * @return CriticalFlow the flow, confirmed with the ETag of its plan
	 */
	private function flow(string $name, string $tool, string $operation, array|\Closure $arguments): CriticalFlow {
		return (new CriticalFlow($name, $tool, 'calendar', $operation, $arguments, fn (): array => $this->scene->state()))
			->confirmingWith(static fn (mixed $plan, array $arguments): array => $arguments + ['confirm' => true]
				+ (is_string($plan->result->structuredContent->etag ?? null) ? ['etag' => $plan->result->structuredContent->etag] : []))
			->withoutPlanChanged(self::NO_PLAN_CHANGED)
			->withoutVisibility(CalendarWritesScene::NO_VISIBILITY);
	}

	/**
	 * @param CriticalFlow $flow the flow, already run
	 * @return array<string, mixed> the decoded result of a plan asked now and confirmed at once
	 */
	private function confirmNow(CriticalFlow $flow): array {
		$body = $this->gate->call($flow->tool, $flow->confirmed($this->gate->call($flow->tool, $flow->arguments())['body']))['body'];
		$this->assertFalse($body->result->isError ?? isset($body->error), 'the confirmation must succeed: ' . json_encode($body));
		return json_decode($body->result->content[0]->text, true, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * Another client changes the event after the plan; the confirmation carrying the ETag of that plan writes nothing.
	 *
	 * @param CriticalFlow $flow the flow, already run
	 * @param string $uid the event the next confirmation targets
	 * @return void
	 */
	private function assertStaleEtagIsRefused(CriticalFlow $flow, string $uid): void {
		$stale = $flow->confirmed($this->gate->call($flow->tool, $flow->arguments())['body']);
		$this->assertArrayHasKey('etag', $stale, 'the plan must carry the ETag of the event');
		$calendarId = $this->scene->calendarOf($uid);
		$row = $this->scene->store->objects[$calendarId][$uid . '.ics'];
		$this->scene->store->objects[$calendarId][$uid . '.ics'] = ['data' => str_replace('SUMMARY:', "COMMENT:outro cliente\r\nSUMMARY:", $row['data']),
			'etag' => '"outro-cliente"'] + $row;
		$before = $this->scene->state();
		$body = $this->gate->call($flow->tool, $stale)['body'];
		$this->assertTrue($body->result->isError ?? false, 'a stale ETag must be refused: ' . json_encode($body));
		$this->assertEquals($before, $this->scene->state(), 'a stale ETag must write nothing');
	}

	/**
	 * @param string $uid fixture event
	 * @return string the ETag the fixture gave it (the fake store hashes the text it holds)
	 */
	private function etagOfFixture(string $uid): string {
		$scene = new CalendarWritesScene();
		$scene->event(1, $uid, match ($uid) {
			'quinzenal' => 'Reunião quinzenal', 'entrega' => 'Entrega do relatório', 'revisao' => 'Revisão do contrato', default => 'Visita ' . $uid,
		}, $uid === 'quinzenal' || $uid === 'entrega' ? [] : ['bob']);
		return $scene->store->objects[1][$uid . '.ics']['etag'];
	}
}
