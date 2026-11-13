<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Routines;

use OCA\Mcp\Mcp\Tools\CatalogModule;
use OCA\Mcp\Modules\Routines\Presentation\RoutinesGuideNotes;
use OCA\Mcp\Modules\Routines\Presentation\RoutinesPlanRenderer;

/** Combinations of several apps under one confirmation, through the ports their own modules implement. */
final class RoutinesModule extends CatalogModule {
	/** @return list<class-string<\OCA\Mcp\Mcp\Tools\Tool>> published tools */
	public static function catalog(): array {
		return RoutinesToolCatalog::TOOLS;
	}

	/** @return null nothing to bind and no capability probe: the ports are bound by the modules that own them */
	public static function registration(): ?string {
		return null;
	}

	/** @return list<string> fixed routine guidance */
	public function guideNotes(): array {
		return RoutinesGuideNotes::notes();
	}

	/** {@inheritDoc} */
	public function renderPlan(string $tool, array $plan): ?string {
		return (new RoutinesPlanRenderer())->render($tool, $plan);
	}
}
