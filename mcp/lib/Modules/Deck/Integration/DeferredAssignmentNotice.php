<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Integration;

use OCA\Deck\Db\Assignment;
use OCP\Notification\IManager;

/**
 * Retries one Deck assignment with the assignee's push delivered only after the assignment is stored (issue #26).
 *
 * Deck's `AssignmentService::assignUser()` notifies another assignee through `IManager::notify()` before it inserts
 * the assignment, and the Notifications app prepares and sends the push inline for an assignee with a push device.
 * An error there ends the call before the insert. Inside `defer()`, `notify()` stores the notification and only
 * queues the push; `flush()` sends it after the insert, so the error reaches the caller after the write, which the
 * handler reports as "saved, then Deck failed".
 *
 * Only retries come here, after every other assignment of the call took the direct path
 * ({@see \OCA\Mcp\Modules\Deck\AssigneePasses}): when a flush fails, NC 32/35 `Manager::flush()` stops before it
 * clears its deferral and Notifications' `Push::flushPayloads()` keeps the failing push queued, so nothing queued
 * after it in this request can be sent. That deferral is still the one this instance opened: `defer()` answers false
 * for it, and this instance keeps flushing it, so each later retry gets its own delivery attempt and its own error.
 * A deferral someone else opened is left to them, as `IManager::defer()` documents.
 */
final class DeferredAssignmentNotice {
	/** Whether this instance opened the deferral that is open now and left it open after a failed flush. */
	private bool $abandoned = false;

	/**
	 * Keep the notification manager the assignment runs under.
	 *
	 * @param IManager $notifications Nextcloud notification manager shared by every app in the request.
	 */
	public function __construct(
		private IManager $notifications
	) {
	}

	/**
	 * Runs the assignment with push delivery deferred, then delivers what it queued.
	 *
	 * A failed assignment still flushes the deferral this instance owns, and the assignment's own error is the one
	 * that reaches the caller.
	 *
	 * @param \Closure(): Assignment $assign Deck call that stores the assignment.
	 * @return Assignment The assignment Deck stored.
	 * @throws \Throwable The assignment's error, or the delivery error raised after the assignment was stored.
	 */
	public function assign(\Closure $assign): Assignment {
		$owner = $this->notifications->defer() || $this->abandoned;
		if (!$owner) {
			return $assign();
		}
		try {
			$assignment = $assign();
		} catch (\Throwable $e) {
			$this->flushAfterFailure();
			throw $e;
		}
		$this->flush();

		return $assignment;
	}

	/**
	 * Delivers the queued pushes and records whether the deferral was left open.
	 *
	 * @return void
	 * @throws \Throwable The delivery error; the deferral then stays this instance's.
	 */
	private function flush(): void {
		$this->abandoned = true;
		$this->notifications->flush();
		$this->abandoned = false;
	}

	/**
	 * Delivers the queued pushes after an assignment that failed, without hiding that failure.
	 *
	 * @return void
	 */
	private function flushAfterFailure(): void {
		try {
			$this->flush();
		} catch (\Throwable) {
			// The assignment error is the answer; a delivery error after it adds nothing the caller can act on.
		}
	}
}
