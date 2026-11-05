<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Approval;

use Closure;
use Sabre\VObject\Component\VCalendar;
use OCA\Mcp\Platform\Dav\Calendar;
use OCA\Mcp\Modules\Calendar\Locating\StoredEvent;

/** Request-local proposed write; no ICS or closure is persisted in the approval store. */
final class PreparedCalendarWrite {
	/**
	 * @param Calendar $source calendar the write starts from
	 * @param Calendar|null $target calendar the event goes to, for a move or a transfer; null for any other write
	 * @param StoredEvent|null $before the event as stored now; null when the write creates it
	 * @param VCalendar|null $after the event as the write would leave it; null when the write deletes it
	 * @param Closure(): array<string, mixed> $write the dispatch itself, run only by dispatch()
	 */
	public function __construct(
		public readonly Calendar $source,
		public readonly ?Calendar $target,
		public readonly ?StoredEvent $before,
		public readonly ?VCalendar $after,
		private Closure $write,
	) {}

	/**
	 * Runs the prepared write callback after the caller has completed its confirmation checks.
	 *
	 * @return array<string, mixed> result produced by the handler callback
	 * @throws \Throwable when the underlying write callback fails
	 */
	public function dispatch(): array {
		return ($this->write)();
	}
}
