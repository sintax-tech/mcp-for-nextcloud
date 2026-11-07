<?php
/* SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Logs\Reading;

use Closure;

/** Monotonic cooperative deadline, shared by offset seeking, block reads and filtering. */
final class LogDeadline {
	private Closure $clock;
	private float $end;

	/** @param int $milliseconds Time allowance. @param Closure|null $clock Monotonic seconds; null uses hrtime. */
	public function __construct(int $milliseconds, ?Closure $clock = null) {
		$this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
		$this->end = ($this->clock)() + $milliseconds / 1000;
	}

	/** @return bool Whether no further read/decode/filter work may start. */
	public function expired(): bool {
		return ($this->clock)() >= $this->end;
	}
}
