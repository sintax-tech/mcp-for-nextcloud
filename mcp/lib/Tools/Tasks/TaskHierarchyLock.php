<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Tasks;

use Closure;
use OCA\Mcp\Tools\ToolFailure;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Serializes the confirmed MCP writes that set a parent in one calendar, with the server's own locking provider
 * (the application mutex the contact backups already use). Inside it, the hierarchy is checked again and written,
 * so two MCP requests can no longer both pass the check and close a loop (A under B while B goes under A).
 *
 * It coordinates MCP requests only: CalDAV clients, the Tasks app and other apps do not take this lock, so their
 * concurrent changes are not covered. A held lock is refused at once, never waited for, and nothing is written.
 *
 * Returning from acquireLock() is not taken as proof of exclusion: with `filelocking.enabled` set to false the server
 * provides a locking provider whose acquireLock() does nothing and whose isLocked() always answers false (Nextcloud
 * 32 to 35). After acquiring, the lock must read as held through the public isLocked(); otherwise the write is
 * refused before the work runs, since the serialization it promises would not exist.
 */
final class TaskHierarchyLock {
	/** Prefix of the lock key; the calendar ID follows, so calendars never wait on each other. */
	public const KEY_PREFIX = 'mcp/tasks/hierarchy/';

	/**
	 * Receives the server's locking provider.
	 *
	 * @param ILockingProvider $locks application mutex provider
	 * @return void
	 */
	public function __construct(
		private ILockingProvider $locks,
	) {
	}

	/**
	 * Runs the work while holding the hierarchy lock of the calendar.
	 *
	 * @template T
	 * @param int $calendarId authorized calendar ID
	 * @param Closure(): T $work the check and the write
	 * @return T what the work returned
	 * @throws ToolFailure when another MCP request holds the lock, or the server's locking does not exclude anything;
	 *     nothing was run
	 */
	public function run(int $calendarId, Closure $work): mixed {
		$key = self::KEY_PREFIX . $calendarId;
		try {
			$this->locks->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			throw new ToolFailure(TaskHierarchyMessages::busy());
		}
		try {
			if (!$this->locks->isLocked($key, ILockingProvider::LOCK_EXCLUSIVE)) {
				throw new ToolFailure(TaskHierarchyMessages::lockingUnavailable());
			}
			return $work();
		} finally {
			$this->locks->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
}
