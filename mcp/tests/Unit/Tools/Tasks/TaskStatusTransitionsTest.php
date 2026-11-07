<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tasks;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Mcp\Modules\Tasks\Composing\TaskData;
use OCA\Mcp\Modules\Tasks\Composing\TaskStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TaskStatusTransitionsTest extends TestCase {
	public static function states(): iterable {
		foreach (TaskStatus::VALUES as $stored) {
			foreach ([$stored, strtolower($stored)] as $current) {
				foreach ([null, 0, 40, 100] as $progress) {
					foreach ([false, true] as $dated) {
						foreach (TaskStatus::VALUES as $status) {
							foreach ([null, 0, 40, 100] as $explicit) {
								yield "$current/" . ($progress ?? 'absent') . "/$dated/$status/" . ($explicit ?? 'omitted') => [$current, $progress, $dated, $status, $explicit];
							}
						}
					}
				}
			}
		}
	}

	#[DataProvider('states')]
	public function testEveryStoredStateAndExplicitPair(string $current, ?int $progress, bool $dated, string $status, ?int $explicit): void {
		$data = new TaskData();
		$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\nBEGIN:VTODO\r\nUID:t1\r\nSUMMARY:Work\r\nSTATUS:$current\r\nX-CUSTOM:keep\r\n"
			. ($progress === null ? '' : "PERCENT-COMPLETE:$progress\r\n")
			. ($dated ? "COMPLETED:20261001T110000Z\r\n" : '') . "END:VTODO\r\nEND:VCALENDAR\r\n";
		$original = $data->parse($ics);
		$before = $original->serialize();
		$args = ['status' => $status] + ($explicit === null ? [] : ['percentComplete' => $explicit]);
		$refused = $explicit !== null && ($status === 'COMPLETED' ? $explicit !== 100 : ($status !== 'CANCELLED' && $explicit === 100));
		try {
			$after = $data->patch($original, $args, new DateTimeImmutable('2026-10-01T12:00:00Z'));
			self::assertFalse($refused, 'contradictory pair accepted');
		} catch (InvalidArgumentException) {
			self::assertTrue($refused, 'valid pair refused');
			self::assertSame($before, $original->serialize());
			return;
		}
		$task = $data->master($after);
		self::assertSame($status, (string) $task->STATUS);
		$expected = $explicit;
		if ($status === 'COMPLETED') { $expected = 100; }
		elseif ($status !== 'CANCELLED' && $expected === null) {
			$expected = $progress === 100 ? 99 : ($status === 'IN-PROCESS' && ($progress ?? 0) === 0 ? 1 : ($progress ?? 0));
		}
		$hasProgress = $expected === null ? $progress !== null : ($expected !== 0 || $progress !== null);
		self::assertSame($hasProgress, isset($task->{'PERCENT-COMPLETE'}));
		if ($hasProgress) { self::assertSame($expected ?? $progress, (int) (string) $task->{'PERCENT-COMPLETE'}); }
		$completion = $status === 'COMPLETED' ? (strtoupper($current) === 'COMPLETED' && $dated ? '20261001T110000Z' : '20261001T120000Z')
			: ($status === 'CANCELLED' && $dated ? '20261001T110000Z' : null);
		self::assertSame($completion, isset($task->COMPLETED) ? (string) $task->COMPLETED : null);
		self::assertSame('keep', (string) $task->{'X-CUSTOM'});
		self::assertSame($before, $original->serialize(), 'the original must remain untouched');
	}

	public function testProgressAloneKeepsItsZeroPropertyButExplicitStatusDoesNotAddOne(): void {
		$data = new TaskData();
		$original = $data->create(['summary' => 'Work'], new DateTimeImmutable('2026-10-01T11:00:00Z'));
		foreach ([['percentComplete' => 0], ['status' => 'NEEDS-ACTION', 'percentComplete' => 0]] as $index => $args) {
			$after = $data->patch($original, $args, new DateTimeImmutable('2026-10-01T12:00:00Z'));
			self::assertSame($index === 0, isset($data->master($after)->{'PERCENT-COMPLETE'}));
		}
	}
}
