<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Availability;

use OCA\Mcp\Mcp\Tools\CatalogModule;
use OCA\Mcp\Modules\Availability\Presentation\AvailabilityGuideNotes;

/** Own out-of-office absence through the DAV absence service, read only in this slice. */
final class AvailabilityModule extends CatalogModule {
	/** @return list<class-string<\OCA\Mcp\Mcp\Tools\Tool>> published tools */
	public static function catalog(): array { return AvailabilityToolCatalog::TOOLS; }
	/** @return class-string<AvailabilityRegistration> capability registration only */
	public static function registration(): ?string { return AvailabilityRegistration::class; }
	/** @return list<string> fixed absence guidance */
	public function guideNotes(): array { return AvailabilityGuideNotes::notes(); }
}
