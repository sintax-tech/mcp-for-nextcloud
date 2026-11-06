<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Forms;

use OCA\Mcp\Mcp\Tools\CatalogModule;
use OCA\Mcp\Modules\Forms\Presentation\FormsGuideNotes;
use OCA\Mcp\Modules\Forms\Presentation\FormsPlanRenderer;

/** Publishes the Forms catalog, its original scope notes and complete plan presentation. */
final class FormsModule extends CatalogModule {
	/** {@inheritDoc} */
	public static function catalog(): array {
		return FormsToolCatalog::TOOLS;
	}

	/** @return class-string<FormsRegistration> the capability probe of the structure tools */
	public static function registration(): ?string {
		return FormsRegistration::class;
	}

	/** {@inheritDoc} */
	public function guideNotes(): array {
		return FormsGuideNotes::notes();
	}

	/** {@inheritDoc} */
	public function renderPlan(string $tool, array $plan): ?string {
		return (new FormsPlanRenderer())->render($tool, $plan);
	}
}
