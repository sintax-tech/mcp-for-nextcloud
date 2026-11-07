<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Calendar;

use OCA\Mcp\Mcp\Tools\CatalogModule;
use OCA\Mcp\Modules\Calendar\Presentation\CalendarGuideNotes;
use OCA\Mcp\Modules\Calendar\Presentation\CalendarPlanRenderer;

/**
 * The Calendar module: the tools of {@see CalendarToolCatalog}, their behaviour notes and the Markdown of their plans.
 * Every write is planned first and only runs when the same call comes back with `confirm: true`; a calendar of
 * somebody else also needs `confirm_shared: true`, and a deletion only happens where the calendar trash bin keeps it.
 */
final class CalendarModule extends CatalogModule {
	/**
	 * @return list<class-string<\OCA\Mcp\Mcp\Tools\Tool>> the Calendar tools, in tools/list order
	 */
	public static function catalog(): array {
		return CalendarToolCatalog::TOOLS;
	}

	/**
	 * @return class-string<CalendarRegistration> the DI of this module
	 */
	public static function registration(): ?string {
		return CalendarRegistration::class;
	}

	/**
	 * @return list<string> what the schemas cannot say: how an event is found, and why a write answers with a plan first
	 */
	public function guideNotes(): array {
		return CalendarGuideNotes::notes();
	}

	/**
	 * {@inheritDoc}
	 */
	public function renderPlan(string $tool, array $plan): ?string {
		return (new CalendarPlanRenderer())->renderPlan($tool, $plan);
	}
}
