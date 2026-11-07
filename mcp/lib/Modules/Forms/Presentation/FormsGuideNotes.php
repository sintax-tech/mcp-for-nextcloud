<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms\Presentation;

/** Fixed model-facing limits of the existing Forms tools. */
final class FormsGuideNotes {
	/** Prevents construction of the fixed notes. */
	private function __construct() {
	}

	/** @return list<string> original guide notes, in publication order */
	public static function notes(): array {
		return [FormsMessages::GUIDE, \OCA\Mcp\Modules\Forms\Presentation\ResultsTools::GUIDE, \OCA\Mcp\Modules\Forms\Presentation\CreateFormMessages::GUIDE, \OCA\Mcp\Modules\Forms\Presentation\EditMessages::GUIDE, StructureMessages::GUIDE];
	}
}
