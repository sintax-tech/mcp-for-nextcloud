<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tools\Calendar;

use Closure;
use Sabre\VObject\Component\VCalendar;

/** Request-local proposed write; no ICS or closure is persisted in the approval store. */
final class PreparedCalendarWrite {
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
