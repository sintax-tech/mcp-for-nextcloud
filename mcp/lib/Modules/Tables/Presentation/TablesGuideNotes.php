<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables\Presentation;


/** The behavior notes published alongside the Tables tools. */
final class TablesGuideNotes {
	/** Prevents construction of the static guide. */
	private function __construct() {
	}

	/** @return list<string> the unchanged module notes, in published order */
	public static function notes(): array {
		return [...TablesMessages::GUIDE_NOTES, RowsMessages::GUIDE_NOTE, RowWriteMessages::GUIDE_NOTE];
	}
}
