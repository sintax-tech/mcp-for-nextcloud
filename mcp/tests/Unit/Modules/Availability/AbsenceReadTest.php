<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Availability;

use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Modules\Availability\Tools\ReadAbsence;
use OCP\User\Events\OutOfOfficeEndedEvent;
use OCP\User\Events\OutOfOfficeStartedEvent;
use PHPUnit\Framework\TestCase;

/** availability_read_absence over the verbatim DAV service: the actor only, and no write, job or event of anyone. */
final class AbsenceReadTest extends TestCase {
	/** @return void A's read leaves B's stored absence and both of its jobs intact, and those jobs still fire B's events */
	public function testReadOfAPreservesTheJobsOfBAndTheirStartAndEndEvents(): void {
		foreach ([false, true] as $aHasAbsence) {
			$rig = new AbsenceNativeRig($this);
			$bId = $rig->seed('bob', '2027-03-01', '2027-03-05');
			if ($aHasAbsence) $rig->seed('alice', '2027-02-01', '2027-02-02');
			self::assertSame(['mapper.insert', 'events.dispatchTyped', 'jobs.scheduleAfter', 'jobs.scheduleAfter'], array_slice($rig->effects, 0, 4), 'seeding goes through the native POST path');
			$bJobs = array_values(array_filter($rig->jobs, static fn (array $job): bool => $job['argument']['id'] === $bId));
			self::assertSame([['id' => $bId, 'event' => 'start'], ['id' => $bId, 'event' => 'end']], array_column($bJobs, 'argument'));
			$rows = array_map(static fn (object $row): array => $row->jsonSerialize() + ['id' => $row->getId()], $rig->rows);
			$jobs = $rig->jobs;
			$rig->settle();

			$result = (new ReadAbsence($rig->handler))->execute([], new ToolContext('alice'));

			self::assertArrayNotHasKey('isError', $result);
			self::assertSame($aHasAbsence, json_decode($result['content'][0]['text'], true)['configured']);
			self::assertSame([], $rig->effects, 'no mapper write, job list call, event or coordinator cache call');
			self::assertSame(['findByUserId:alice'], $rig->selects, 'one SELECT, of the actor only');
			self::assertSame($rows, array_map(static fn (object $row): array => $row->jsonSerialize() + ['id' => $row->getId()], $rig->rows));
			self::assertSame($jobs, $rig->jobs, 'IDs, arguments and schedule of B unchanged');

			// The cron would run each job once its time passed: B's start, then B's end, with the native job class.
			foreach ($bJobs as $job) {
				$rig->now = $job['time'] + 1;
				self::assertSame('OCA\\DAV\\BackgroundJob\\OutOfOfficeEventDispatcherJob', $job['class']);
				$rig->job->run($job['argument']);
			}
			self::assertCount(2, $rig->events);
			self::assertInstanceOf(OutOfOfficeStartedEvent::class, $rig->events[0]);
			self::assertInstanceOf(OutOfOfficeEndedEvent::class, $rig->events[1]);
			foreach ($rig->events as $event) {
				self::assertSame('bob', $event->getData()->getUser()->getUID());
				self::assertSame((string)$bId, $event->getData()->getId());
			}
		}
	}

	/** @return void states, inclusive days, native time zone conversion and replacement */
	public function testAnswersTheStoredAbsenceWithItsStateAtReadTime(): void {
		$rig = new AbsenceNativeRig($this);
		$rig->seed('alice', '2027-03-01', '2027-03-05', 'carol');
		$rig->settle();
		$cases = ['upcoming' => strtotime('2027-02-28T12:00:00Z'), 'in_effect' => strtotime('2027-03-03T12:00:00Z'), 'ended' => strtotime('2027-03-06T12:00:00Z')];
		foreach ($cases as $state => $now) {
			$rig->now = $now;
			$answer = json_decode($rig->handler->handle('alice')['content'][0]['text'], true);
			self::assertSame(['configured' => true, 'absence' => ['state' => $state, 'first_day' => '2027-03-01', 'last_day' => '2027-03-05',
				'timezone' => 'America/Sao_Paulo', 'starts_at' => '2027-03-01T00:00:00-03:00', 'ends_at' => '2027-03-05T23:59:00-03:00',
				'status' => 'Away', 'message' => 'Back soon', 'replacement' => ['user_id' => 'carol', 'display_name' => 'Carol']]], $answer);
		}
		self::assertSame([], $rig->effects);
		$rig->zones = [];
		$answer = json_decode($rig->handler->handle('alice')['content'][0]['text'], true);
		self::assertSame('UTC', $answer['absence']['timezone'], 'the native server-default fallback');
		self::assertSame('2027-03-01T00:00:00+00:00', $answer['absence']['starts_at']);
	}

	/** @return void no stored absence is an answer, not an error, and nothing is created */
	public function testNoAbsenceIsConfiguredFalse(): void {
		$rig = new AbsenceNativeRig($this);
		self::assertSame(['configured' => false, 'absence' => null], json_decode($rig->handler->handle('alice')['content'][0]['text'], true));
		self::assertSame([], $rig->effects);
		self::assertSame([], $rig->rows);
	}

	/** @return void the feature switch refuses before any absence SELECT */
	public function testFeatureOffRefusesBeforeReading(): void {
		$rig = new AbsenceNativeRig($this);
		$rig->seed('alice', '2027-03-01', '2027-03-05');
		$rig->settle();
		$rig->enabled = false;
		$result = $rig->handler->handle('alice');
		self::assertTrue($result['isError']);
		self::assertSame('Absence settings are turned off on this Nextcloud server.', $result['content'][0]['text']);
		self::assertSame([], $rig->selects);
		self::assertSame([], $rig->effects);
	}

	/** @return void the account is always the actor: arguments cannot name another one, and unknown actors are refused */
	public function testTheActorIsTheOnlyAccountRead(): void {
		$rig = new AbsenceNativeRig($this);
		$rig->seed('bob', '2027-03-01', '2027-03-05');
		$rig->settle();
		$answer = (new ReadAbsence($rig->handler))->execute(['user_id' => 'bob', 'userId' => 'bob'], new ToolContext('alice'));
		self::assertFalse(json_decode($answer['content'][0]['text'], true)['configured']);
		self::assertSame(['findByUserId:alice'], $rig->selects);
		$unknown = $rig->handler->handle('mallory');
		self::assertTrue($unknown['isError']);
		self::assertSame('Your absence settings are unavailable.', $unknown['content'][0]['text']);
		self::assertSame(['findByUserId:alice'], $rig->selects, 'an unknown actor is refused before any SELECT');
		self::assertSame([], $rig->effects);
		$schema = ReadAbsence::definition()->inputSchema;
		self::assertEquals(new \stdClass(), $schema['properties']);
		self::assertFalse($schema['additionalProperties']);
	}
}
