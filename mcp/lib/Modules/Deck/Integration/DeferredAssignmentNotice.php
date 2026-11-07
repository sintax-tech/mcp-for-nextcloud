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
 * Runs the steps of an assignment retry with push delivery deferred, keeping track of the deferral (issue #26).
 *
 * Deck's `AssignmentService::assignUser()` notifies another assignee through `IManager::notify()` before it inserts
 * the assignment, and the Notifications app prepares and sends the push inline for an assignee with a push device.
 * An error there ends the call before the insert. Inside `defer()`, `notify()` stores the notification and only
 * queues the push; `flush()` sends it after the insert, so the error reaches the caller after the write, which the
 * handler reports as "saved, then Deck failed".
 *
 * Only retries come here, after every other assignment of the call took the direct path
 * ({@see \OCA\Mcp\Modules\Deck\AssigneePasses}): when a flush fails, NC 32/35 `Manager::flush()` stops before it
 * clears its deferral and Notifications' `Push::flushPayloads()` keeps the failing push queued. That deferral is
 * still the one this instance opened: `defer()` answers false for it, and this instance keeps flushing it, so each
 * later step gets its own delivery attempt and its own error. The withdrawal of a failed attempt's notification runs
 * here too, because the Notifications app opens a deferral of its own while it deletes and leaves it open when a
 * notifier fails; opened by this instance first, that deferral is closed here. A deferral someone else opened is
 * left to them, as `IManager::defer()` documents.
 */
final class DeferredAssignmentNotice {
	/** Whether this instance opened the deferral that is open now and left it open after a failed flush. */
	private bool $abandoned = false;

	/**
	 * Keep the notification manager the steps run under.
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
	 * @param \Closure(): Assignment $assign Deck call that stores the assignment.
	 * @return Assignment The assignment Deck stored.
	 * @throws \Throwable The assignment's error, or the delivery error raised after the assignment was stored.
	 */
	public function assign(\Closure $assign): Assignment {
		return $this->deferred($assign);
	}

	/**
	 * Runs the withdrawal of a notification inside the same deferral, so a deferral it leaves open stays closed.
	 *
	 * @param \Closure(): void $withdraw Deck call that marks the notification processed.
	 * @return void
	 * @throws \Throwable The withdrawal's error, or a delivery error raised after it.
	 */
	public function withdraw(\Closure $withdraw): void {
		$this->deferred($withdraw);
	}

	/**
	 * Runs one step with delivery deferred and closes the deferral this instance owns.
	 *
	 * A failed step still flushes the deferral this instance owns, and the step's own error is the one that reaches
	 * the caller.
	 *
	 * @param \Closure(): mixed $step Deck call to run.
	 * @return mixed What the step returned.
	 * @throws \Throwable The step's error, or the delivery error raised after it.
	 */
	private function deferred(\Closure $step): mixed {
		$owner = $this->notifications->defer() || $this->abandoned;
		if (!$owner) {
			return $step();
		}
		try {
			$result = $step();
		} catch (\Throwable $e) {
			$this->flushAfterFailure();
			throw $e;
		}
		$this->flush();

		return $result;
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
	 * Delivers the queued pushes after a step that failed, without hiding that failure.
	 *
	 * @return void
	 */
	private function flushAfterFailure(): void {
		try {
			$this->flush();
		} catch (\Throwable) {
			// The step's error is the answer; a delivery error after it adds nothing the caller can act on.
		}
	}
}
