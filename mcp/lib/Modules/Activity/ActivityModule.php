<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Activity;

use OCA\Mcp\Mcp\Tools\CatalogModule;
use OCA\Mcp\Modules\Activity\Presentation\ActivityGuideNotes;

/** Own Activity feed with no formatter, notifier or read-marker maintenance. */
final class ActivityModule extends CatalogModule {
	/** @return list<class-string<\OCA\Mcp\Mcp\Tools\Tool>> published tools */
	public static function catalog(): array { return ActivityToolCatalog::TOOLS; }
	/** @return class-string<ActivityRegistration> capability registration only */
	public static function registration(): ?string { return ActivityRegistration::class; }
	/** @return list<string> fixed coverage and privacy guidance */
	public function guideNotes(): array { return ActivityGuideNotes::notes(); }
}
