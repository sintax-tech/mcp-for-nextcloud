<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables;

use OCA\Mcp\Modules\Tables\Tools\ListTables;
use OCA\Mcp\Modules\Tables\Tools\ListViews;
use OCA\Mcp\Modules\Tables\Tools\ListColumns;
use OCA\Mcp\Modules\Tables\Tools\ListRows;
use OCA\Mcp\Modules\Tables\Tools\CreateRow;
use OCA\Mcp\Modules\Tables\Tools\UpdateRow;

/** The Tables tools in their published order. */
final class TablesToolCatalog {
	/** @var list<class-string<\OCA\Mcp\Mcp\Tools\Tool>> ordered published tools */
	public const TOOLS = [ListTables::class, ListViews::class, ListColumns::class, ListRows::class, CreateRow::class, UpdateRow::class, \OCA\Mcp\Modules\Tables\Tools\CreateTable::class, \OCA\Mcp\Modules\Tables\Tools\UpdateTable::class, \OCA\Mcp\Modules\Tables\Tools\CreateColumn::class, \OCA\Mcp\Modules\Tables\Tools\UpdateColumn::class, \OCA\Mcp\Modules\Tables\Tools\CreateView::class, \OCA\Mcp\Modules\Tables\Tools\UpdateView::class, \OCA\Mcp\Modules\Tables\Tools\ShareNode::class, \OCA\Mcp\Modules\Tables\Tools\UnshareNode::class];

	/** Prevents construction of this ordered catalog. */
	private function __construct() {
	}
}
