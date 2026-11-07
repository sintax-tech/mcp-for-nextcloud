<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Logs\Reading;

/** Bounded current-file scan: existing entry/depth ceilings plus a monotonic cooperative time allowance. */
class LogScanner {
	/** Maximum eligible slots processed after the requested offset. */
	public const SCAN_LIMIT = 5000;
	/** Maximum eligible depth from the current file end. */
	public const READ_BUDGET = 20000;
	/** Largest accepted numeric offset. */
	public const MAX_OFFSET = self::READ_BUDGET;
	/** Cooperative monotonic time allowance for one scan, in milliseconds. */
	public const TIME_BUDGET_MS = 1000;

	/** @param LogReader $reader Current log source. @param \Closure|null $clock Optional monotonic clock for deadline tests. */
	public function __construct(private LogReader $reader, private ?\Closure $clock = null) {}

	/** @param LogFilter $filter Filters. @param int $offset Eligible-entry offset. @param int|null $limit Page size.
	 * @return array<string,mixed> Bounded scan and continuation diagnostics.
	 */
	public function scan(LogFilter $filter, int $offset, ?int $limit): array {
		return (new TimedLogScan($this->reader, $this->clock))->scan($filter, $offset, $limit);
	}
}
