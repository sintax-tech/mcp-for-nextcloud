<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Contacts;

use OCA\Mcp\Mcp\Tools\CatalogModule;
use OCA\Mcp\Modules\Contacts\Presentation\ContactsGuideNotes;
use OCA\Mcp\Modules\Contacts\Presentation\ContactsPlanRenderer;

/**
 * The Contacts module: the tools of {@see ContactsToolCatalog} over personal and shared CardDAV address books and the
 * read-only accounts catalog. Writes use the same native ACL and validation pipeline as the web UI, answer with a plan
 * first, and a deletion only happens after a verified vCard backup.
 */
final class ContactsModule extends CatalogModule {
	/**
	 * @return list<class-string<\OCA\Mcp\Mcp\Tools\Tool>> the Contacts tools, in tools/list order
	 */
	public static function catalog(): array {
		return ContactsToolCatalog::TOOLS;
	}

	/**
	 * @return class-string<ContactsRegistration> the DI of this module
	 */
	public static function registration(): ?string {
		return ContactsRegistration::class;
	}

	/**
	 * @return list<string> what the schemas cannot say: the address books and accounts, the plan first, the backup
	 */
	public function guideNotes(): array {
		return ContactsGuideNotes::notes();
	}

	/**
	 * Describes the plan as the text the person confirms, in their own language.
	 *
	 * @param string $tool registered write tool name
	 * @param array<string, mixed> $plan the plan preview() returned for that tool
	 * @return string|null Markdown body, or null when this renderer has nothing to say about it
	 */
	public function renderPlan(string $tool, array $plan): ?string {
		// Built on demand and not injected: a contact plan carries no date, so the renderer needs
		// nothing this module does not already hold, and a constructor parameter would reach every caller.
		return (new ContactsPlanRenderer())->render($tool, $plan);
	}
}
