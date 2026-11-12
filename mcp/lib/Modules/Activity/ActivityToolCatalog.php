<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Activity;

use OCA\Mcp\Modules\Activity\Tools\ListActivity;

/** Published Activity tools, in discovery order. */
final class ActivityToolCatalog {
	/** Ordered tools; new tools append here. */
	public const TOOLS = [ListActivity::class];
	/** Static catalog only. */
	private function __construct() {}
}
