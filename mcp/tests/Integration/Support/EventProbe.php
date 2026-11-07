<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Support;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;

/**
 * Listens on the real event dispatcher and records each event core dispatches, with whether the caller's
 * connection was inside a transaction at that moment. Listeners are removed by detach().
 */
final class EventProbe {
	/** @var list<array{event: Event, inTransaction: bool}> */
	private array $seen = [];
	/** @var list<array{string, callable}> */
	private array $registered = [];

	public function __construct(private IEventDispatcher $dispatcher, private IDBConnection $caller) {}

	/**
	 * Records every event of this class.
	 *
	 * @param class-string<Event> $eventClass typed event class
	 * @param int $priority dispatcher priority; higher runs first
	 */
	public function record(string $eventClass, int $priority = 0): void {
		$this->on($eventClass, function (Event $event): void {
			$this->seen[] = ['event' => $event, 'inTransaction' => $this->caller->inTransaction()];
		}, $priority);
	}

	/**
	 * Registers an arbitrary listener that detach() also removes.
	 *
	 * @param class-string<Event> $eventClass typed event class
	 * @param callable(Event): void $listener listener
	 * @param int $priority dispatcher priority; higher runs first
	 */
	public function on(string $eventClass, callable $listener, int $priority = 0): void {
		$this->dispatcher->addListener($eventClass, $listener, $priority);
		$this->registered[] = [$eventClass, $listener];
	}

	/** @return list<array{event: Event, inTransaction: bool}> recorded events in dispatch order */
	public function seen(): array {
		return $this->seen;
	}

	/** @return list<class-string<Event>> classes of the recorded events in dispatch order */
	public function classes(): array {
		return array_map(static fn (array $entry): string => $entry['event']::class, $this->seen);
	}

	public function detach(): void {
		foreach ($this->registered as [$eventClass, $listener]) {
			$this->dispatcher->removeListener($eventClass, $listener);
		}
		$this->registered = [];
	}
}
