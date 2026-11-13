<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Routines;

use OCA\Mcp\Modules\Routines\Tools\CreateMailCard;
use OCA\Mcp\Modules\Routines\Tools\CreateMeeting;

/** Published Routines tools, in discovery order. A new tool goes at the end. */
final class RoutinesToolCatalog {
	/** @var list<class-string<\OCA\Mcp\Mcp\Tools\Tool>> tool classes, in tools/list order */
	public const TOOLS = [CreateMeeting::class, CreateMailCard::class];

	/** Static catalog only. */
	private function __construct() {
	}
}
