<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Access\FileTypes;

use OCA\Mcp\Access\FileTypes\FileWritePolicy;
use OCA\Mcp\Access\FileTypes\FileWritePolicyUnavailable;
use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Typed config repair owns one transaction, preserves D9 on rollback and never exposes an absent committed key. */
final class FileWritePolicyRecoveryTest extends TestCase {
	/** Recovery succeeds through the admin service entry, without deleting anything outside the transaction. */
	public function testTypedConfigurationCanBeRecoveredWithoutTerminal(): void {
		[$policy, $state] = $this->fixture();
		try {
			$policy->current();
			self::fail('Wrongly typed policy accepted');
		} catch (FileWritePolicyUnavailable) {
			self::assertSame(7, $state->stored);
		}
		$saved = $policy->save('allowlist', ['txt'], false);
		self::assertSame($saved, $policy->current());
		self::assertSame(['begin', 'delete', 'set', 'commit'], $state->events);
		self::assertFalse($state->active);
	}

	/** @return iterable<string, array{string}> Every destructive repair failure must retain the prior committed value. */
	public static function failures(): iterable {
		foreach (['begin', 'delete', 'set', 'read', 'commit'] as $stage) {
			yield $stage => [$stage];
		}
	}

	/** @param string $stage Failure before a successful commit. */
	#[DataProvider('failures')]
	public function testFailedRepairKeepsThePreviousD9State(string $stage): void {
		[$policy, $state] = $this->fixture($stage);
		try {
			$policy->save('allowlist', ['txt'], false);
			self::fail('Failed repair reported success');
		} catch (FileWritePolicyUnavailable $e) {
			self::assertNull($e->getPrevious());
		}
		self::assertSame(7, $state->stored);
		self::assertFalse($state->active);
		self::assertContains('rollback', $state->events);
		$this->expectException(FileWritePolicyUnavailable::class);
		$policy->current();
	}

	/** An outer transaction is never committed, rolled back or modified by this repair. */
	public function testRepairRefusesToOwnAnOuterTransaction(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('setValueString')->willThrowException(new AppConfigTypeConflictException());
		$config->expects(self::never())->method('deleteKey');
		$db = $this->createMock(IDBConnection::class);
		$db->method('inTransaction')->willReturn(true);
		$db->expects(self::never())->method('beginTransaction');
		$db->expects(self::never())->method('rollBack');
		$db->expects(self::never())->method('commit');
		$this->expectException(FileWritePolicyUnavailable::class);
		(new FileWritePolicy($config, $db))->save('off', [], false);
	}

	/**
	 * Model committed and uncommitted values separately; the integration test additionally proves real core behavior.
	 *
	 * @param string $failure Stage to fail, or empty for success.
	 * @return array{FileWritePolicy, \stdClass} Policy and observable transaction state.
	 */
	private function fixture(string $failure = ''): array {
		$state = (object)['stored' => 7, 'working' => 7, 'active' => false, 'events' => []];
		$config = $this->createMock(IAppConfig::class);
		$db = $this->createMock(IDBConnection::class);
		$db->method('inTransaction')->willReturnCallback(static fn (): bool => $state->active);
		$db->method('beginTransaction')->willReturnCallback(static function () use ($state, $failure): void {
			$state->active = true;
			$state->working = $state->stored;
			$state->events[] = 'begin';
			if ($failure === 'begin') {
				throw new \RuntimeException('private begin failure');
			}
		});
		$db->method('commit')->willReturnCallback(static function () use ($state, $failure): void {
			if ($failure === 'commit') {
				throw new \RuntimeException('private commit failure');
			}
			$state->stored = $state->working;
			$state->active = false;
			$state->events[] = 'commit';
		});
		$db->method('rollBack')->willReturnCallback(static function () use ($state): void {
			$state->working = $state->stored;
			$state->active = false;
			$state->events[] = 'rollback';
		});
		$config->method('hasKey')->willReturnCallback(static fn (): bool => ($state->active ? $state->working : $state->stored) !== null);
		$config->method('getValueString')->willReturnCallback(static function () use ($state, $failure): string {
			$value = $state->active ? $state->working : $state->stored;
			if (!is_string($value)) {
				throw new AppConfigTypeConflictException();
			}
			return $state->active && $failure === 'read' ? 'bad-readback' : $value;
		});
		$config->method('deleteKey')->willReturnCallback(static function ($app, $key) use ($state, $failure): void {
			self::assertSame(['mcp', FileWritePolicy::KEY], [$app, $key]);
			self::assertTrue($state->active);
			self::assertSame(7, $state->stored, 'Other readers still see the previous committed D9 value.');
			$state->working = null;
			$state->events[] = 'delete';
			if ($failure === 'delete') {
				throw new \RuntimeException('private delete failure');
			}
		});
		$config->method('setValueString')->willReturnCallback(static function ($app, $key, $value, $lazy) use ($state, $failure): bool {
			self::assertFalse($lazy);
			if (!$state->active) {
				throw new AppConfigTypeConflictException();
			}
			self::assertSame(7, $state->stored);
			if ($failure === 'set') {
				throw new \RuntimeException('private set failure');
			}
			$state->working = $value;
			$state->events[] = 'set';
			return true;
		});
		return [new FileWritePolicy($config, $db), $state];
	}
}
