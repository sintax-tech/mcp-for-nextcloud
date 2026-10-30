<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Forms\Results;

/** Fixes the semantic projection supported by MCP independently of future native Forms additions. */
final class ResultsTypes {
	/** @var list<string> Exact supported values examined in all four pinned Forms releases. */
	public const SUPPORTED = [
		'short', 'long', 'color', 'date', 'time', 'datetime', 'linearscale',
		'multiple', 'multiple_unique', 'dropdown', 'file', 'grid', 'ranking',
	];
}
