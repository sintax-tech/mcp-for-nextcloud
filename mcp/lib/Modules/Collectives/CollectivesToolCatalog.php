<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives;

use OCA\Mcp\Modules\Collectives\Tools\CreatePage;
use OCA\Mcp\Modules\Collectives\Tools\EditPage;
use OCA\Mcp\Modules\Collectives\Tools\ListCollectives;
use OCA\Mcp\Modules\Collectives\Tools\ListPages;
use OCA\Mcp\Modules\Collectives\Tools\MovePage;
use OCA\Mcp\Modules\Collectives\Tools\ReadPage;
use OCA\Mcp\Modules\Collectives\Tools\SearchPages;

/** The Collectives tools in tools/list order. A new tool goes at the end. */
final class CollectivesToolCatalog {
	/** @var list<class-string<\OCA\Mcp\Mcp\Tools\Tool>> tool classes, in tools/list order */
	public const TOOLS = [
		ListCollectives::class,
		ListPages::class,
		ReadPage::class,
		SearchPages::class,
		CreatePage::class,
		EditPage::class,
		MovePage::class,
	];

	/** Constant holder only; never instantiated. */
	private function __construct() {
	}
}
