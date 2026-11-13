<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Availability\Presentation;

/** Fixed absence guidance, shared by the guide and the tool descriptions. */
final class AvailabilityGuideNotes {
	/** Static guide only. */
	private function __construct() {}
	/** @return list<string> absence guidance of both tools */
	public static function notes(): array { return [AvailabilitySchema::NOTE, AvailabilitySchema::SET_NOTE]; }
}
