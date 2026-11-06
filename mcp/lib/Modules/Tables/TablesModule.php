<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Tables;

use OCA\Mcp\Mcp\Tools\CatalogModule;
use OCA\Mcp\Modules\Tables\Presentation\TablesGuideNotes;
use OCA\Mcp\Modules\Tables\Presentation\RowWritePlanRenderer;
use OCA\Mcp\Modules\Tables\Creation\CreateRowHandler;
use OCA\Mcp\Modules\Tables\Editing\UpdateRowHandler;

/** Delegates the Tables catalog, guide notes and row plan rendering. */
final class TablesModule extends CatalogModule {
	/** Grant module of the tools. */
	public const MODULE = 'tables';

	/** {@inheritDoc} */
	public static function catalog(): array {
		return TablesToolCatalog::TOOLS;
	}

	/** @return class-string<\OCA\Mcp\Mcp\Tools\ModuleRegistration> Native capabilities. */
	public static function registration(): ?string { return TablesRegistration::class; }

	/** {@inheritDoc} */
	public function guideNotes(): array {
		return TablesGuideNotes::notes();
	}

	/** {@inheritDoc} */
	public function renderPlan(string $tool, array $plan): ?string {
		if (\OCA\Mcp\Modules\Tables\Presentation\SchemaPlanRenderer::supports($tool)) return \OCA\Mcp\Modules\Tables\Presentation\SchemaPlanRenderer::render($plan);
		return in_array($tool, [CreateRowHandler::TOOL, UpdateRowHandler::TOOL], true) ? (new RowWritePlanRenderer())->render($plan) : null;
	}
}
