<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notifications;

use OCA\Mcp\Mcp\Tools\CatalogModule;
use OCA\Mcp\Modules\Notifications\Presentation\NotificationsGuideNotes;

/** Own notifications as opaque metadata, without native rendering or maintenance. */
final class NotificationsModule extends CatalogModule {
	/** @return list<class-string<\OCA\Mcp\Mcp\Tools\Tool>> ordered public reads */
	public static function catalog(): array { return NotificationsToolCatalog::TOOLS; }
	/** @return class-string<NotificationsRegistration> native capability registration */
	public static function registration(): ?string { return NotificationsRegistration::class; }
	/** @return list<string> bounded coverage guidance */
	public function guideNotes(): array { return NotificationsGuideNotes::notes(); }
}
