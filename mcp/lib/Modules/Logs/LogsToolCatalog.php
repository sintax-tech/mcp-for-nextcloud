<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Logs;

use OCA\Mcp\Modules\Logs\Tools\AnalyzeLogs;
use OCA\Mcp\Modules\Logs\Tools\ListLogs;

/** The Logs tools in tools/list order. A new tool goes at the end. */
final class LogsToolCatalog {
	/** @var list<class-string<\OCA\Mcp\Mcp\Tools\Tool>> tool classes, in tools/list order */
	public const TOOLS = [
		ListLogs::class,
		AnalyzeLogs::class,
	];

	/** Constant holder only; never instantiated. */
	private function __construct() {
	}
}
