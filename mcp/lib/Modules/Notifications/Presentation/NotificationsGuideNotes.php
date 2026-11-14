<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notifications\Presentation;

/** Common fixed guidance for discovery and the guide. */
final class NotificationsGuideNotes {
	/** Static guide only. */
	private function __construct() {}
	/** @return list<string> privacy and coverage limits */
	public static function notes(): array { return [NotificationsSchema::NOTE, NotificationsSchema::DISMISS]; }
}
