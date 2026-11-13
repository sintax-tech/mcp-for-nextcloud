<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Status;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pinned user_status and calendar-status sources for NC34 and NC35, and the calls this module does not make. */
final class StatusNativeTest extends TestCase {
	/** Maintenance the read must not perform, checked against the pinned sources below. */
	private const FORBIDDEN = [
		'->processStatus', 'processCalendarStatus(', '->heartbeat', '->setStatus(', '->setCustomMessage(',
		'->createBackupStatus', '->restoreBackupStatuses', '->revertUserStatus', 'clearOlderThanClearAt(',
		'clearStatusesOlderThan(', 'deleteCurrentStatus', '->cleanStatus', 'PredefinedStatusService',
		'->insert(', '->update(',
	];

	/**
	 * Lines that show why the controller and StatusService reads are not safe, and why the mapper select is.
	 *
	 * @var array<string, array<string, array<int, string>>>
	 */
	private const LINES = [
		'34' => [
			'UserStatusMapper.php' => [
				90 => 'function findByUserId(string $userId, bool $isBackup = false)',
				93 => "->select('*')",
				95 => "'_' . \$userId",
			],
			'StatusService.php' => [
				123 => 'return $this->processStatus($this->mapper->findByUserId($userId));',
				151 => '$userStatus = new UserStatus();',
				330 => 'function setCustomMessage',
				447 => 'function processStatus',
				454 => 'if ($clearAt !== null && $clearAt < $this->timeFactory->getTime())',
				477 => '$this->mapper->update($status);',
				490 => '$this->mapper->update($status);',
			],
			'UserStatusController.php' => [
				59 => '$this->calendarStatusService->processCalendarStatus($this->userId);',
				60 => '$userStatus = $this->service->findByUserId($this->userId);',
				81 => '$this->service->setStatus($this->userId, $statusType, null, true)',
				83 => '$this->service->removeBackupUserStatus($this->userId)',
			],
			'HeartbeatController.php' => [55 => 'function heartbeat(string $status)'],
			'DavStatusService.php' => [42 => 'function processCalendarStatus(string $userId)'],
		],
		'35' => [
			'UserStatusMapper.php' => [
				90 => 'function findByUserId(string $userId, bool $isBackup = false)',
				93 => "->select('*')",
				95 => "'_' . \$userId",
			],
			'StatusService.php' => [
				131 => 'return $this->processStatus($this->mapper->findByUserId($userId));',
				159 => '$userStatus = new UserStatus();',
				338 => 'function setCustomMessage',
				455 => 'function processStatus',
				462 => 'if ($clearAt !== null && $clearAt < $this->timeFactory->getTime())',
				485 => '$this->mapper->update($status);',
				498 => '$this->mapper->update($status);',
			],
			'UserStatusController.php' => [
				60 => '$this->calendarStatusService->processCalendarStatus($this->userId);',
				61 => '$userStatus = $this->service->findByUserId($this->userId);',
				82 => '$this->service->setStatus($this->userId, $statusType, null, true)',
				84 => '$this->service->removeBackupUserStatus($this->userId)',
			],
			'HeartbeatController.php' => [56 => 'function heartbeat(string $status)'],
			'DavStatusService.php' => [43 => 'function processCalendarStatus(string $userId)'],
		],
	];

	/** @return iterable<string, array{string}> pinned majors */
	public static function pins(): iterable {
		yield 'NC34' => ['34'];
		yield 'NC35' => ['35'];
	}

	/**
	 * @param string $major pinned major
	 * @return void hashes and the cited lines
	 */
	#[DataProvider('pins')]
	public function testPinnedSourcesCiteTheSelectAndTheWritesItAvoids(string $major): void {
		$root = dirname(__DIR__, 3) . '/fixtures/user-status-native';
		$manifest = json_decode((string)file_get_contents($root . '/sources.json'), true);
		self::assertIsArray($manifest['versions'][$major]);
		foreach ($manifest['versions'][$major] as $file => $source) {
			self::assertSame($source['sha256'], hash_file('sha256', $root . '/' . $major . '/' . $file), $file);
		}
		foreach (self::LINES[$major] as $file => $lines) {
			$body = file($root . '/' . $major . '/' . $file, FILE_IGNORE_NEW_LINES);
			self::assertIsArray($body);
			foreach ($lines as $number => $needle) {
				self::assertStringContainsString($needle, $body[$number - 1], $major . ' ' . $file . ':' . $number);
			}
		}
	}

	/** @return void only the type editor calls setStatus, and only as the status menu does */
	public function testModuleSourceDoesNotCallMaintenance(): void {
		$root = dirname(__DIR__, 4) . '/lib/Modules/Status';
		$files = glob($root . '/*.php') ?: [];
		$files = array_merge($files, glob($root . '/*/*.php') ?: []);
		$editor = $root . '/Integration/StatusEditor.php';
		self::assertContains($editor, $files);
		$joined = '';
		$editorBody = '';
		foreach ($files as $file) {
			$body = (string)file_get_contents($file);
			if ($file === $editor) {
				$editorBody = $body;
				continue;
			}
			$joined .= $body;
		}
		foreach (self::FORBIDDEN as $token) {
			self::assertStringNotContainsString($token, $joined, $token);
		}
		foreach (array_diff(self::FORBIDDEN, ['->setStatus(']) as $token) {
			self::assertStringNotContainsString($token, $editorBody, $token);
		}
		self::assertStringContainsString('->setStatus($actor, $type, null, true)', $editorBody);
		self::assertStringContainsString('->removeBackupUserStatus($actor)', $editorBody);
		self::assertStringContainsString('findByUserId($actor, false)', $joined);
	}
}
