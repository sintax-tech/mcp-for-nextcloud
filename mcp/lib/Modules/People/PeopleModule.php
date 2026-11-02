<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\People;

use OCA\Mcp\Mcp\Tools\CatalogModule;
use OCA\Mcp\Modules\People\Presentation\PeopleGuideNotes;

/**
 * The People module: the account and group search of {@see PeopleToolCatalog} and the behaviour notes that keep the
 * model from guessing an ID. The sharing search applies the administrator's enumeration and group restrictions.
 */
final class PeopleModule extends CatalogModule {
	/**
	 * @return list<class-string<\OCA\Mcp\Mcp\Tools\Tool>> the People tools, in tools/list order
	 */
	public static function catalog(): array {
		return PeopleToolCatalog::TOOLS;
	}

	/**
	 * @return list<string> what the schema cannot say about finding accounts and sharing with groups
	 */
	public function guideNotes(): array {
		return PeopleGuideNotes::notes();
	}
}
