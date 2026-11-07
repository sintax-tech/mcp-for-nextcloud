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
use OCA\Mcp\Modules\Tables\Tools\CreateTable;
use OCA\Mcp\Modules\Tables\Tools\UpdateTable;
use OCA\Mcp\Modules\Tables\Tools\CreateColumn;
use OCA\Mcp\Modules\Tables\Tools\UpdateColumn;
use OCA\Mcp\Modules\Tables\Tools\CreateView;
use OCA\Mcp\Modules\Tables\Tools\UpdateView;
use OCA\Mcp\Modules\Tables\Tools\ShareNode;
use OCA\Mcp\Modules\Tables\Tools\UnshareNode;

/** The Tables tools in their published order. */
final class TablesToolCatalog {
	/** @var list<class-string<\OCA\Mcp\Mcp\Tools\Tool>> ordered published tools */
	public const TOOLS = [
		ListTables::class,
		ListViews::class,
		ListColumns::class,
		ListRows::class,
		CreateRow::class,
		UpdateRow::class,
		CreateTable::class,
		UpdateTable::class,
		CreateColumn::class,
		UpdateColumn::class,
		CreateView::class,
		UpdateView::class,
		ShareNode::class,
		UnshareNode::class,
	];

	/** Prevents construction of this ordered catalog. */
	private function __construct() {
	}
}
