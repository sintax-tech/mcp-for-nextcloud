<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Polls;

use OCA\Mcp\Mcp\Tools\CatalogModule;
use OCA\Mcp\Modules\Polls\Presentation\PollsGuideNotes;
use OCA\Mcp\Modules\Polls\Presentation\PollsPlanRenderer;
use OCA\Mcp\Modules\Polls\Presentation\PollsWritePlanRenderer;

/**
 * The Polls module: the tools of {@see PollsToolCatalog}, their behaviour notes and the Markdown of the creation plan.
 * Protected votes/results and existing links have independent grants; the private creation is planned first and only
 * runs when the same call comes back with `confirm: true`.
 */
final class PollsModule extends CatalogModule {
	/**
	 * @return list<class-string<\OCA\Mcp\Mcp\Tools\Tool>> the Polls tools, in tools/list order
	 */
	public static function catalog(): array {
		return PollsToolCatalog::TOOLS;
	}

	/** @return class-string<\OCA\Mcp\Mcp\Tools\ModuleRegistration> Mutation port and lazy capability probe. */
	public static function registration(): ?string {
		return PollsRegistration::class;
	}

	/**
	 * @return list<string> what the schemas cannot say: untrusted content, native costs, link credentials and creation effects
	 */
	public function guideNotes(): array {
		return PollsGuideNotes::withMutations();
	}

	/**
	 * {@inheritDoc}
	 */
	public function renderPlan(string $tool, array $plan): ?string {
		return (new PollsWritePlanRenderer())->render($tool, $plan) ?? (new PollsPlanRenderer())->render($tool, $plan);
	}
}
