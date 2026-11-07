<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck;

use OCP\Notification\IDeferrableApp;
use OCP\Notification\INotification;

/**
 * The Notifications app as the official manager sees it, reduced to what issue #26 depends on.
 *
 * Mirrors Notifications 32/35 `App` and `Push`: `notify()` stores the notification (`Handler::add`) and, outside a
 * deferral, prepares and sends the push inline (`Push::pushToDevice`); inside one it only queues it. `flush()`
 * (`Push::flushPayloads`) prepares the queue in order, clears it only after the whole loop and only then stops
 * deferring and sends, so a push that fails to prepare leaves itself and everything after it queued, and keeps the
 * app deferring.
 */
final class PushQueueApp implements IDeferrableApp {
	/** @var list<string> Recipients whose notification was stored. */
	public array $stored = [];
	/** @var list<string> Recipients whose push was sent. */
	public array $delivered = [];
	/** Number of `flush()` calls. */
	public int $flushes = 0;
	/** Whether pushes are queued instead of prepared (`Push::$deferPreparing`). */
	public bool $deferPreparing = false;
	/** Whether prepared pushes wait instead of being sent (`Push::$deferPayloads`). */
	public bool $deferPayloads = false;
	/** @var list<string> Recipients queued for preparation (`Push::$notificationsToPush`). */
	public array $queue = [];
	/** @var list<string> Prepared pushes waiting to be sent (`Push::$payloadsToSend`). */
	private array $payloads = [];

	/** @param list<string> $failing Recipients whose push fails while it is prepared. */
	public function __construct(private array $failing) {
	}

	/** @param INotification $notification Notification Deck sends. @return void */
	public function notify(INotification $notification): void {
		$this->stored[] = $notification->getUser();
		if ($this->deferPreparing) {
			$this->queue[] = $notification->getUser();
			return;
		}
		$this->prepare($notification->getUser());
		$this->send();
	}

	/** @param INotification $notification Ignored. @return void */
	public function markProcessed(INotification $notification): void {
	}

	/** @param INotification $notification Ignored. @return int Always 0. */
	public function getCount(INotification $notification): int {
		return 0;
	}

	/** @return void */
	public function defer(): void {
		$this->deferPreparing = true;
		$this->deferPayloads = true;
	}

	/** @return void @throws \RuntimeException When a queued push fails to prepare; the queue stays as it was. */
	public function flush(): void {
		$this->flushes++;
		$this->deferPreparing = false;
		foreach ($this->queue as $user) {
			$this->prepare($user);
		}
		$this->queue = [];
		$this->deferPayloads = false;
		$this->send();
	}

	/** @param string $user Recipient. @return void @throws \RuntimeException For a failing recipient. */
	private function prepare(string $user): void {
		if (in_array($user, $this->failing, true)) {
			throw new \RuntimeException('push preparation failed');
		}
		$this->payloads[] = $user;
	}

	/** @return void Sends the prepared pushes unless they are deferred. */
	private function send(): void {
		if ($this->deferPayloads) {
			return;
		}
		array_push($this->delivered, ...$this->payloads);
		$this->payloads = [];
	}
}
