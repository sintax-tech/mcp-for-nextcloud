<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Activity\Presentation;

/** Fixed privacy and continuation guidance, shared by the guide and tool description. */
final class ActivityGuideNotes {
	/** Static guide only. */
	private function __construct() {}
	/** @return list<string> conservative first-slice guidance */
	public static function notes(): array { return [ActivitySchema::NOTE]; }
}
