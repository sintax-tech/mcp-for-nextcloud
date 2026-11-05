<?php
/*
* SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
* SPDX-License-Identifier: AGPL-3.0-or-later
*/
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tasks;

use InvalidArgumentException;
use OCA\Mcp\Tests\Unit\Tools\Tasks\Handler\TaskListingCase;

/** Verifies setting the status (STATUS) and progress (PERCENT-COMPLETE) of a task on create and edit. */
final class TaskStatusTest extends TaskListingCase {
	/**
	* @param array<string, mixed> $fields fields of the edit
	* @return array<string, mixed> the task as the plan says it becomes
	*/
	private function edit(array $fields): array {
		return $this->module->preview('tasks_edit_task', ['calendar' => self::PATH, 'uid' => 't1'] + $fields, 'alice')['after'];
	}

	/** @param string $extra lines replacing the NEEDS-ACTION status of the planted task */
	private function plant(string $extra): void {
		$this->tasks['t1'] = str_replace("STATUS:NEEDS-ACTION\r\n", $extra, $this->task('t1', 'Work'));
	}

	public function testCancellingKeepsTheProgressAndIsWritten(): void {
		$this->plant("STATUS:IN-PROCESS\r\nPERCENT-COMPLETE:40\r\n");
		$plan = $this->module->preview('tasks_edit_task', ['calendar' => self::PATH, 'uid' => 't1', 'status' => 'CANCELLED'], 'alice');
		self::assertSame('CANCELLED', $plan['after']['status']);
		self::assertSame(40, $plan['after']['percentComplete']);
		self::assertNull($plan['after']['completed']);
		self::assertStringContainsString('- Status: in progress → cancelled', (string) $this->module->renderPlan('tasks_edit_task', $plan));
		$this->expectUpdate(
			function (string $data) {
				self::assertStringContainsString("STATUS:CANCELLED\r\n", $data);
				self::assertStringContainsString("PERCENT-COMPLETE:40\r\n", $data);
				self::assertStringContainsString('X-CUSTOM:keep', $data);
			}
		);
		$this->module->call('tasks_edit_task', ['calendar' => self::PATH, 'uid' => 't1', 'status' => 'CANCELLED', 'confirm' => true], 'alice');
	}

	public function testCancellingACompletedTaskKeepsTheCompletionTime(): void {
		$this->plant("STATUS:COMPLETED\r\nPERCENT-COMPLETE:100\r\nCOMPLETED:20261001T110000Z\r\n");
		$after = $this->edit(['status' => 'CANCELLED', 'percentComplete' => 60]);
		self::assertSame(['CANCELLED', 60, '2026-10-01T11:00:00+00:00'], [$after['status'], $after['percentComplete'], $after['completed']]);
	}

	public function testStatusAndProgressCanBeSetTogether(): void {
		$after = $this->edit(['status' => 'IN-PROCESS', 'percentComplete' => 25]);
		self::assertSame(['IN-PROCESS', 25], [$after['status'], $after['percentComplete']]);
	}

	public function testCompletedStatusReachesOneHundredPercentWithACompletionTime(): void {
		$after = $this->edit(['status' => 'COMPLETED']);
		self::assertSame(['COMPLETED', 100], [$after['status'], $after['percentComplete']]);
		self::assertNotNull($after['completed']);
	}

	public function testCompletingAgainKeepsTheOriginalCompletionTime(): void {
		$this->plant("STATUS:COMPLETED\r\nPERCENT-COMPLETE:100\r\nCOMPLETED:20261001T110000Z\r\n");
		self::assertSame('2026-10-01T11:00:00+00:00', $this->edit(['status' => 'COMPLETED', 'summary' => 'Done'])['completed']);
	}

	public function testReopeningAFinishedTaskKeepsNinetyNinePercent(): void {
		$this->plant("STATUS:COMPLETED\r\nPERCENT-COMPLETE:100\r\nCOMPLETED:20261001T110000Z\r\n");
		$after = $this->edit(['status' => 'NEEDS-ACTION']);
		self::assertSame(['NEEDS-ACTION', 99, null], [$after['status'], $after['percentComplete'], $after['completed']]);
	}

	public function testReopeningACancelledTaskKeepsItsProgress(): void {
		$this->plant("STATUS:CANCELLED\r\nPERCENT-COMPLETE:30\r\n");
		$after = $this->edit(['status' => 'IN-PROCESS']);
		self::assertSame(['IN-PROCESS', 30], [$after['status'], $after['percentComplete']]);
	}

	public function testProgressAloneStillReopensACancelledTaskAsBefore(): void {
		$this->plant("STATUS:CANCELLED\r\nPERCENT-COMPLETE:30\r\n");
		$after = $this->edit(['percentComplete' => 50]);
		self::assertSame(['IN-PROCESS', 50], [$after['status'], $after['percentComplete']]);
	}

	public function testProgressWithTheCancelledStatusKeepsTheTaskCancelled(): void {
		$this->plant("STATUS:CANCELLED\r\nPERCENT-COMPLETE:30\r\n");
		$after = $this->edit(['status' => 'CANCELLED', 'percentComplete' => 50]);
		self::assertSame(['CANCELLED', 50], [$after['status'], $after['percentComplete']]);
	}

	public function testProgressAloneStillDerivesTheStatusOfAnOpenTask(): void {
		$after = $this->edit(['percentComplete' => 100]);
		self::assertSame(['COMPLETED', 100], [$after['status'], $after['percentComplete']]);
		self::assertNotNull($after['completed']);
	}

	public function testATaskCanBeCreatedCancelled(): void {
		$plan = $this->module->preview('tasks_create_task', ['calendar' => self::PATH, 'summary' => 'Idea', 'status' => 'CANCELLED'], 'alice');
		self::assertSame('CANCELLED', $plan['after']['status']);
		self::assertStringContainsString('- Status: cancelled', (string) $this->module->renderPlan('tasks_create_task', $plan));
	}

	public function testANewOpenTaskDoesNotListItsDefaultStatus(): void {
		$plan = $this->module->preview('tasks_create_task', ['calendar' => self::PATH, 'summary' => 'Idea'], 'alice');
		self::assertStringNotContainsString('Status', (string) $this->module->renderPlan('tasks_create_task', $plan));
	}

	public function testCancelledTasksAreStillListed(): void {
		$this->plant("STATUS:CANCELLED\r\n");
		self::assertSame(['t1'], array_column($this->list([])['tasks'], 'uid'));
	}

	/** @return array<string, array{array<string, mixed>}> */
	public static function contradictions(): array {
		return [
			'completed below 100' => [['status' => 'COMPLETED', 'percentComplete' => 50]],
			'open at 100' => [['status' => 'NEEDS-ACTION', 'percentComplete' => 100]],
			'in process at 100' => [['status' => 'IN-PROCESS', 'percentComplete' => 100]],
			'unknown status' => [['status' => 'DONE']],
			'lowercase status' => [['status' => 'cancelled']],
		];
	}

	/**
	* @dataProvider contradictions
	* @param array<string, mixed> $fields fields of the edit
	*/
	public function testContradictoryOrUnknownStatusesAreRefusedBeforeAnyWrite(array $fields): void {
		$this->dav->expects(self::never())->method('update');
		$this->expectException(InvalidArgumentException::class);
		$this->module->call('tasks_edit_task', ['calendar' => self::PATH, 'uid' => 't1', 'confirm' => true] + $fields, 'alice');
	}

	public function testStatusAloneIsAnEdit(): void {
		self::assertSame('IN-PROCESS', $this->edit(['status' => 'IN-PROCESS'])['status']);
	}
}
