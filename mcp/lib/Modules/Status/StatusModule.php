<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Status;

use OCA\Mcp\Mcp\Tools\CatalogModule;
use OCA\Mcp\Modules\Status\Presentation\StatusGuideNotes;

/** Own configured user-status snapshot, with no maintenance of the stored row. */
final class StatusModule extends CatalogModule {
	/** @return list<class-string<\OCA\Mcp\Mcp\Tools\Tool>> published tools */
	public static function catalog(): array {
		return StatusToolCatalog::TOOLS;
	}

	/** @return class-string<StatusRegistration> capability registration only */
	public static function registration(): ?string {
		return StatusRegistration::class;
	}

	/** @return list<string> fixed snapshot and privacy guidance */
	public function guideNotes(): array {
		return StatusGuideNotes::notes();
	}
}
