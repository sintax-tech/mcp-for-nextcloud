<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Routines\Presentation;

/** Fixed routine guidance, shared by the guide and the tool descriptions. */
final class RoutinesGuideNotes {
	/** Static guide only. */
	private function __construct() {
	}

	/** @return list<string> guidance of the meeting routine */
	public static function notes(): array {
		return [RoutinesSchema::MEETING_NOTE];
	}
}
