<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Collectives;

use OCA\Mcp\Modules\Collectives\Removal\RemovalReadback;

/**
 * {@see RemovalReadback} on a clock of its own: every pause moves the clock by {@see RemovalReadback::PAUSE} and
 * nothing really waits, so the whole wait is counted instead of spent.
 */
final class PacedRemovalReadback extends RemovalReadback {
	/** Pauses taken so far. */
	public int $pauses = 0;

	/** @return float the clock: the pauses taken, in seconds */
	protected function now(): float {
		return $this->pauses * self::PAUSE / 1e6;
	}

	/** @return void one pause, on the clock only */
	protected function pause(): void {
		$this->pauses++;
	}
}
