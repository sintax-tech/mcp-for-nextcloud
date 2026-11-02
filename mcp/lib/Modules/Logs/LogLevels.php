<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Logs;

/**
 * Level names of the Nextcloud log, by the number the core writes.
 *
 * Owned here so a redactor can name a level without depending on the analyzer that counts them.
 */
final class LogLevels {
	/** Level names, highest first, by the number the core writes. */
	public const NAMES = [4 => 'fatal', 3 => 'error', 2 => 'warning', 1 => 'info', 0 => 'debug'];

	/** Constant holder only; never instantiated. */
	private function __construct() {
	}
}
