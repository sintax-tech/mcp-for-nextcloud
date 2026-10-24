<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Tasks;

use OCA\Mcp\Tests\Unit\InMemoryLocks;
use OCA\Mcp\Tools\Tasks\TaskHierarchyLock;
use OCA\Mcp\Tools\Tasks\TaskHierarchyMessages;
use OCA\Mcp\Tools\ToolFailure;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The hierarchy lock runs the work only while it really excludes: an effective provider holds the key during the
 * work and releases it, even on failure; a held key is "busy"; a provider that excludes nothing is "unavailable",
 * and in both refusals the work never runs.
 */
final class TaskHierarchyLockTest extends TestCase {
	/** Key of calendar 7. */
	private const KEY = TaskHierarchyLock::KEY_PREFIX . '7';

	/**
	 * An effective provider holds the key while the work runs and releases it afterwards.
	 *
	 * @return void
	 */
	public function testAnEffectiveProviderHoldsTheKeyDuringTheWork(): void {
		$locks = new InMemoryLocks();
		$held = null;
		$result = (new TaskHierarchyLock($locks))->run(7, function () use ($locks, &$held): string {
			$held = array_keys($locks->held);
			return 'written';
		});
		$this->assertSame('written', $result);
		$this->assertSame([self::KEY], $held);
		$this->assertSame([], $locks->held);
	}

	/**
	 * A failing work still releases the key.
	 *
	 * @return void
	 */
	public function testTheKeyIsReleasedWhenTheWorkFails(): void {
		$locks = new InMemoryLocks();
		try {
			(new TaskHierarchyLock($locks))->run(7, static fn () => throw new RuntimeException('DAV failed'));
			$this->fail('the failure was swallowed');
		} catch (RuntimeException $e) {
			$this->assertSame('DAV failed', $e->getMessage());
		}
		$this->assertSame([], $locks->held);
	}

	/**
	 * A key held by another request refuses as busy, without running the work.
	 *
	 * @return void
	 */
	public function testAHeldKeyIsBusy(): void {
		$locks = new InMemoryLocks();
		$locks->held[self::KEY] = true;
		$ran = false;
		try {
			(new TaskHierarchyLock($locks))->run(7, function () use (&$ran): void {
				$ran = true;
			});
			$this->fail('the work ran under a held lock');
		} catch (ToolFailure $e) {
			$this->assertSame(TaskHierarchyMessages::busy(), $e->getMessage());
		}
		$this->assertFalse($ran);
		$this->assertSame([self::KEY => true], $locks->held, 'the lock of the other request is left alone');
	}

	/**
	 * A provider that acquires without excluding (file locking disabled) refuses as unavailable, never runs the work
	 * and still releases what it asked for.
	 *
	 * @return void
	 */
	public function testAProviderThatExcludesNothingIsRefused(): void {
		$locks = new IneffectiveLocks();
		$ran = false;
		try {
			(new TaskHierarchyLock($locks))->run(7, function () use (&$ran): void {
				$ran = true;
			});
			$this->fail('the work ran without exclusion');
		} catch (ToolFailure $e) {
			$this->assertSame(TaskHierarchyMessages::lockingUnavailable(), $e->getMessage());
			$this->assertNotSame(TaskHierarchyMessages::busy(), $e->getMessage());
		}
		$this->assertFalse($ran);
		$this->assertSame(['acquire:' . self::KEY, 'release:' . self::KEY], $locks->calls);
	}
}
