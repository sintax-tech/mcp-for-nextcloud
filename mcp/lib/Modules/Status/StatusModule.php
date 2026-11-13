<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Status;

use OCA\Mcp\Mcp\Tools\CatalogModule;
use OCA\Mcp\Modules\Status\Presentation\StatusGuideNotes;
use OCA\Mcp\Modules\Status\Presentation\StatusPlanRenderer;

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

	/** @return list<string> fixed snapshot and type-change guidance */
	public function guideNotes(): array {
		return StatusGuideNotes::notes();
	}

	/**
	 * @param string $tool tool name
	 * @param array<string, mixed> $plan plan from the handler
	 * @return string|null readable plan, or null when this module has no text for it
	 */
	public function renderPlan(string $tool, array $plan): ?string {
		return (new StatusPlanRenderer())->render($tool, $plan);
	}
}
