<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Availability;

use OCA\Mcp\Modules\Availability\Tools\ReadAbsence;

/** Published Availability tools, in discovery order. */
final class AvailabilityToolCatalog {
	/** Ordered tools; new tools append here. */
	public const TOOLS = [ReadAbsence::class];
	/** Static catalog only. */
	private function __construct() {}
}
