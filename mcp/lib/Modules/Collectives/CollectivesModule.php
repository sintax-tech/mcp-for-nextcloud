<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Collectives;

use OCA\Mcp\Mcp\Tools\CatalogModule;
use OCA\Mcp\Modules\Collectives\Presentation\CollectivesGuideNotes;

/**
 * The Collectives module: the tools of {@see CollectivesToolCatalog} over the pages of the collectives a user is a
 * member of. Native services are reached only through Integration, after the grant, the app, Guests and the proven
 * native shape are checked; every tool needs the `collectives.native` capability.
 */
final class CollectivesModule extends CatalogModule {
	/**
	 * @return list<class-string<\OCA\Mcp\Mcp\Tools\Tool>> the Collectives tools, in tools/list order
	 */
	public static function catalog(): array {
		return CollectivesToolCatalog::TOOLS;
	}

	/**
	 * @return class-string<CollectivesRegistration> the DI of this module
	 */
	public static function registration(): ?string {
		return CollectivesRegistration::class;
	}

	/**
	 * @return list<string> what the schemas cannot say: how pages are addressed and what the module refuses
	 */
	public function guideNotes(): array {
		return CollectivesGuideNotes::notes();
	}
}
