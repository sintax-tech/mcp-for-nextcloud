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
 * Runs one Deck assignment with the assignee's push delivery deferred until the assignment is stored (issue #26).
 *
 * Deck's `AssignmentService::assignUser()` notifies another assignee through `IManager::notify()` before it inserts
 * the assignment, and the Notifications app delivers that push inline. When the assignee has a push device, any
 * delivery error escaped `assignUser()` and nothing was assigned. Inside `defer()`, `notify()` stores the
 * notification and only queues the push; `flush()` delivers it after the insert, so a delivery error reaches the
 * caller as an error after the write, which the handler reports as "saved, then Deck failed".
 *
 * The deferral is closed only by the call that opened it: when another part of the request already defers,
 * `defer()` answers false and that owner keeps the flush, as `IManager::defer()` documents.
 */
final class DeferredAssignmentNotice {
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
	 * A failed assignment still flushes the deferral it opened, so no later notification of the request stays
	 * queued, and the assignment's own error is the one that reaches the caller.
	 *
	 * @param \Closure(): Assignment $assign Deck call that stores the assignment.
	 * @return Assignment The assignment Deck stored.
	 * @throws \Throwable The assignment's error, or the delivery error raised after the assignment was stored.
	 */
	public function assign(\Closure $assign): Assignment {
		if (!$this->notifications->defer()) {
			return $assign();
		}
		try {
			$assignment = $assign();
		} catch (\Throwable $e) {
			$this->flushAfterFailure();
			throw $e;
		}
		$this->notifications->flush();

		return $assignment;
	}

	/**
	 * Delivers the queued pushes after an assignment that failed, without hiding that failure.
	 *
	 * @return void
	 */
	private function flushAfterFailure(): void {
		try {
			$this->notifications->flush();
		} catch (\Throwable) {
			// The assignment error is the answer; a delivery error after it adds nothing the caller can act on.
		}
	}
}
