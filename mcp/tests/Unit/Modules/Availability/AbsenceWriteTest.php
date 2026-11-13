<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Availability;

use OCA\Mcp\Mcp\Confirmation\PlanChanged;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCP\User\Events\OutOfOfficeChangedEvent;
use OCP\User\Events\OutOfOfficeEndedEvent;
use OCP\User\Events\OutOfOfficeScheduledEvent;
use OCP\User\Events\OutOfOfficeStartedEvent;
use PHPUnit\Framework\TestCase;

/** availability_set_absence over the verbatim DAV service: only A's absence and jobs change, B's jobs still fire. */
final class AbsenceWriteTest extends TestCase {
	/** Values of every call unless a test overrides them. */
	private const VALUES = ['first_day' => '2027-02-01', 'last_day' => '2027-02-03', 'status' => 'Vacation', 'message' => 'Ask Carol'];

	/** @return void A creates its absence: one insert, its own two jobs and Scheduled; B's row and jobs intact and firing */
	public function testCreatingTheAbsenceOfAPreservesTheJobsOfB(): void {
		$rig = new AbsenceNativeRig($this);
		$bId = $rig->seed('bob', '2027-03-01', '2027-03-05');
		$this->assertCreatesOrUpdatesOnlyA($rig, $bId, 'create', ['mapper.insert', 'events.dispatchTyped', 'jobs.scheduleAfter', 'jobs.scheduleAfter', 'coordinator.clearCache:alice'], OutOfOfficeScheduledEvent::class);
	}

	/** @return void A updates its absence: one update of its own row, its own jobs rescheduled and Changed; B's intact and firing */
	public function testUpdatingTheAbsenceOfAPreservesTheJobsOfB(): void {
		$rig = new AbsenceNativeRig($this);
		$bId = $rig->seed('bob', '2027-03-01', '2027-03-05');
		$aId = $rig->seed('alice', '2027-01-10', '2027-01-12');
		$this->assertCreatesOrUpdatesOnlyA($rig, $bId, 'update', ['mapper.update', 'events.dispatchTyped', 'jobs.scheduleAfter', 'jobs.scheduleAfter', 'coordinator.clearCache:alice'], OutOfOfficeChangedEvent::class);
		self::assertCount(2, $rig->rows);
		self::assertSame([['id' => $aId, 'event' => 'start'], ['id' => $aId, 'event' => 'end']], array_column($rig->jobsOf($aId), 'argument'), 'rescheduled in place, by argument');
		self::assertSame(strtotime('2027-02-01T00:00:00-03:00'), $rig->jobsOf($aId)[0]['time']);
	}

	/**
	 * @param AbsenceNativeRig $rig seeded rig
	 * @param int $bId B's absence
	 * @param string $mode expected plan mode
	 * @param list<string> $effects exact native effects of the confirmed call
	 * @param class-string $event expected native event of A
	 * @return void
	 */
	private function assertCreatesOrUpdatesOnlyA(AbsenceNativeRig $rig, int $bId, string $mode, array $effects, string $event): void {
		$bRow = $rig->table()[$bId];
		$bJobs = $rig->jobsOf($bId);
		self::assertCount(2, $bJobs);
		$rig->settle();
		$plan = $rig->set->preview(self::VALUES, new ToolContext('alice'));
		self::assertSame($mode, $plan['mode']);
		self::assertSame([], $rig->effects, 'the plan writes nothing');
		$receipt = json_decode($rig->set->execute(self::VALUES + ['confirm' => true, 'plan_state' => $plan['plan_state']], new ToolContext('alice'))['content'][0]['text'], true);
		self::assertTrue($receipt['saved']);
		self::assertSame($mode, $receipt['mode']);
		self::assertSame(['2027-02-01', '2027-02-03', 'Vacation', 'Ask Carol'], [$receipt['absence']['first_day'], $receipt['absence']['last_day'], $receipt['absence']['status'], $receipt['absence']['message']]);
		self::assertSame($effects, $rig->effects, 'one native write, its own jobs and the cache reset; no remove, delete or clear');
		self::assertCount(1, $rig->events);
		self::assertInstanceOf($event, $rig->events[0]);
		self::assertSame('alice', $rig->events[0]->getData()->getUser()->getUID());
		self::assertSame($bRow, $rig->table()[$bId], 'B\'s absence untouched');
		self::assertSame($bJobs, $rig->jobsOf($bId), 'IDs, arguments and schedule of B unchanged');
		$rig->settle();
		foreach ($bJobs as $job) {
			$rig->now = $job['time'] + 1;
			$rig->job->run($job['argument']);
		}
		self::assertSame([OutOfOfficeStartedEvent::class, OutOfOfficeEndedEvent::class], array_map(static fn (object $e): string => $e::class, $rig->events));
		foreach ($rig->events as $fired) self::assertSame(['bob', (string)$bId], [$fired->getData()->getUser()->getUID(), $fired->getData()->getId()]);
	}

	/** @return void a grant revoked after the plan, or the feature turned off, refuses right before the sink */
	public function testRevocationAfterThePlanWinsBeforeTheWrite(): void {
		foreach (['grant', 'feature'] as $revoked) {
			$rig = new AbsenceNativeRig($this);
			$plan = $rig->set->preview(self::VALUES, new ToolContext('alice'));
			$revoked === 'grant' ? $rig->policy->setGrant('alice', 'availability', 'absence', false) : $rig->enabled = false;
			$rig->settle();
			try {
				$rig->set->execute(self::VALUES + ['confirm' => true, 'plan_state' => $plan['plan_state']], new ToolContext('alice'));
				self::fail('refused');
			} catch (ToolFailure $e) {
				self::assertSame($revoked === 'grant' ? 'Changing your absence through MCP is not allowed for your account.' : 'Absence settings are turned off on this Nextcloud server.', $e->getMessage());
			}
			self::assertSame([], $rig->effects);
			self::assertSame([], $rig->rows);
		}
	}

	/** @return void a stored absence that changed since the plan answers the new plan and writes nothing */
	public function testAChangedAbsenceAnswersTheNewPlan(): void {
		$rig = new AbsenceNativeRig($this);
		$rig->seed('alice', '2027-01-10', '2027-01-12');
		$plan = $rig->set->preview(self::VALUES, new ToolContext('alice'));
		$rig->service->createOrUpdateAbsence($rig->user('alice'), '2027-01-10', '2027-01-12', 'Changed elsewhere', 'Back soon', null, null);
		$rig->settle();
		try {
			$rig->set->execute(self::VALUES + ['confirm' => true, 'plan_state' => $plan['plan_state']], new ToolContext('alice'));
			self::fail('plan changed');
		} catch (PlanChanged $e) {
			self::assertNotSame($plan['plan_state'], $e->plan['plan_state']);
			self::assertSame('Changed elsewhere', $e->plan['before']['status']);
		}
		self::assertSame([], $rig->effects);
	}

	/** @return void the replacement is kept, removed, set, or refused when it cannot be stored as the native POST stores it */
	public function testReplacementIsKeptRemovedOrRefusedNeverDroppedSilently(): void {
		$rig = new AbsenceNativeRig($this);
		$rig->seed('alice', '2027-01-10', '2027-01-12', 'carol');
		$kept = $rig->set->preview(self::VALUES, new ToolContext('alice'));
		self::assertSame(['user_id' => 'carol', 'display_name' => 'Carol'], $kept['after']['replacement']);
		$removed = $rig->set->preview(self::VALUES + ['replacement_user_id' => null], new ToolContext('alice'));
		self::assertNull($removed['after']['replacement']);
		self::assertContains('The current replacement user is removed from your absence.', $removed['notices']);
		self::assertSame(['user_id' => 'bob', 'display_name' => 'Bob'], $rig->set->preview(self::VALUES + ['replacement_user_id' => 'bob'], new ToolContext('alice'))['after']['replacement']);
		$refusals = ['mallory' => 'The replacement user was not found.'];
		foreach ($refusals as $id => $message) $this->assertRefused($rig, self::VALUES + ['replacement_user_id' => $id], $message);
		$rig->names['carol'] = str_repeat('C', 65);
		$this->assertRefused($rig, self::VALUES, 'The display name of this replacement user is longer than Nextcloud can store for an absence.');
		unset($rig->names['carol']);
		$this->assertRefused($rig, self::VALUES, 'The replacement user of your current absence no longer exists. Send replacement_user_id with another user, or null to remove the replacement.');
		$receipt = json_decode($rig->set->execute(self::VALUES + ['replacement_user_id' => null, 'confirm' => true,
			'plan_state' => $rig->set->preview(self::VALUES + ['replacement_user_id' => null], new ToolContext('alice'))['plan_state']], new ToolContext('alice'))['content'][0]['text'], true);
		self::assertNull($receipt['absence']['replacement']);
	}

	/** @return void strict days, order and the native and explicit text limits, refused before any read */
	public function testInvalidValuesAreRefusedBeforeAnything(): void {
		$rig = new AbsenceNativeRig($this);
		foreach ([
			[['first_day' => '2027-02-30'], 'Use real calendar days in the form YYYY-MM-DD.'],
			[['last_day' => '2027-2-3'], 'Use real calendar days in the form YYYY-MM-DD.'],
			[['first_day' => '2027-02-04'], 'The first day of the absence must not be after its last day.'],
			[['status' => str_repeat('é', 101)], 'The absence status can have at most 100 characters.'],
			[['message' => str_repeat('x', 4001)], 'The absence message can have at most 4000 characters.'],
		] as [$override, $message]) {
			$this->assertRefused($rig, array_replace(self::VALUES, $override), $message);
		}
		self::assertSame([], $rig->selects);
		self::assertSame([], $rig->effects);
	}

	/**
	 * @param AbsenceNativeRig $rig rig
	 * @param array<string,mixed> $arguments call
	 * @param string $message expected refusal
	 * @return void
	 */
	private function assertRefused(AbsenceNativeRig $rig, array $arguments, string $message): void {
		try {
			$rig->set->preview($arguments, new ToolContext('alice'));
			self::fail('refused: ' . $message);
		} catch (ToolFailure $e) {
			self::assertSame($message, $e->getMessage());
		}
	}
}
