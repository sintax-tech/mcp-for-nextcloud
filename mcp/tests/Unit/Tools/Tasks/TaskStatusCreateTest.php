<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tasks;

use InvalidArgumentException;
use OCA\Mcp\Modules\Tasks\Composing\TaskStatus;
use OCA\Mcp\Platform\Dav\DavResult;
use OCA\Mcp\Tests\Unit\Tools\Tasks\Handler\TaskListingCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class TaskStatusCreateTest extends TaskListingCase {
	public static function inputs(): iterable {
		foreach (TaskStatus::VALUES as $status) {
			foreach ([null, 0, 40, 100] as $percent) {
				yield "$status/" . ($percent ?? 'omitted') => [$status, $percent];
			}
		}
	}

	#[DataProvider('inputs')]
	public function testCreatePreviewAndConfirmedWrite(string $status, ?int $percent): void {
		$args = ['calendar' => self::PATH, 'summary' => 'New', 'status' => $status]
			+ ($percent === null ? [] : ['percentComplete' => $percent]);
		$invalid = $percent !== null && ($status === 'COMPLETED' ? $percent !== 100 : ($status !== 'CANCELLED' && $percent === 100));
		if ($invalid) {
			$this->dav->expects(self::never())->method('put');
			$this->expectException(InvalidArgumentException::class);
			$this->module->call('tasks_create_task', $args + ['confirm' => true], 'alice');
			return;
		}
		$plan = $this->module->preview('tasks_create_task', $args, 'alice');
		self::assertSame($status, $plan['after']['status']);
		self::assertSame($percent ?? ($status === 'COMPLETED' ? 100 : ($status === 'IN-PROCESS' ? 1 : 0)), $plan['after']['percentComplete']);
		self::assertSame($status === 'COMPLETED', $plan['after']['completed'] !== null);
		$this->dav->expects(self::once())->method('put')->willReturnCallback(function ($user, $calendar, $uri, $ics) use ($status): DavResult {
			self::assertSame('alice', $user);
			self::assertStringContainsString("STATUS:$status\r\n", $ics);
			$this->tasks[basename($uri, '.ics')] = $ics;
			return new DavResult(201);
		});
		$result = $this->json($this->module->call('tasks_create_task', $args + ['confirm' => true], 'alice'));
		self::assertSame($status, $result['status']);
	}
}
