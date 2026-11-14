<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Status\Presentation;

/** Fixed snapshot guidance, shared by the guide and the tool description. */
final class StatusGuideNotes {
	/** Static guide only. */
	private function __construct() {
	}

	/** @return list<string> configured-snapshot guidance and the confirmed type change */
	public static function notes(): array {
		return [StatusSchema::NOTE, StatusTypeSchema::NOTE, StatusMessageSchema::NOTE];
	}
}
