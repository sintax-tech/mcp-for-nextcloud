<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes;

use OCA\Mcp\Modules\Notes\Tools\CreateNote;
use OCA\Mcp\Modules\Notes\Tools\DeleteNote;
use OCA\Mcp\Modules\Notes\Tools\EditNote;
use OCA\Mcp\Modules\Notes\Tools\ListNotes;
use OCA\Mcp\Modules\Notes\Tools\MoveNote;
use OCA\Mcp\Modules\Notes\Tools\ReadNote;
use OCA\Mcp\Modules\Notes\Tools\SearchNotes;

/** The Notes tools in tools/list order. A new tool goes at the end. */
final class NotesToolCatalog {
	/** @var list<class-string<\OCA\Mcp\Mcp\Tools\Tool>> tool classes, in tools/list order */
	public const TOOLS = [
		ListNotes::class,
		SearchNotes::class,
		ReadNote::class,
		CreateNote::class,
		EditNote::class,
		MoveNote::class,
		DeleteNote::class,
	];

	/** Constant holder only; never instantiated. */
	private function __construct() {
	}
}
