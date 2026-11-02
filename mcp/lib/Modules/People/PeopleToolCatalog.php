<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\People;

use OCA\Mcp\Modules\People\Tools\SearchPeople;

/** The People tools in tools/list order. A new tool goes at the end. */
final class PeopleToolCatalog {
	/** @var list<class-string<\OCA\Mcp\Mcp\Tools\Tool>> tool classes, in tools/list order */
	public const TOOLS = [
		SearchPeople::class,
	];

	/** Constant holder only; never instantiated. */
	private function __construct() {
	}
}
