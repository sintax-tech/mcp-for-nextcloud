<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tasks;

use OCA\Mcp\Mcp\Tools\CatalogModule;
use OCA\Mcp\Modules\Tasks\Presentation\TasksGuideNotes;
use OCA\Mcp\Modules\Tasks\Presentation\TasksPlanRenderer;

/** Publishes VTODO tools through the ordered catalog without requiring the optional Tasks UI app. */
final class TasksModule extends CatalogModule {
	/** @return list<class-string<\OCA\Mcp\Mcp\Tools\Tool>> the existing tools in published order */
	public static function catalog(): array {
		return TasksToolCatalog::TOOLS;
	}

	/** @return class-string<TasksRegistration> Tasks query-port registration */
	public static function registration(): ?string {
		return TasksRegistration::class;
	}

	/** @return list<string> the unchanged Tasks workflow notes */
	public function guideNotes(): array {
		return TasksGuideNotes::notes();
	}

	/**
	 * @param string $name registered write tool
	 * @param array<string, mixed> $plan before/after plan with its own timezone
	 * @return string|null the unchanged confirmation text, or generic fallback
	 */
	public function renderPlan(string $name, array $plan): ?string {
		return (new TasksPlanRenderer())->render($name, $plan);
	}
}
