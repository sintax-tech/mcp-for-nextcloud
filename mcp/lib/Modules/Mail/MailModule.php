<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail;

use OCA\Mcp\Mcp\Tools\CatalogModule;
use OCA\Mcp\Modules\Mail\Presentation\{MailGuideNotes, MailPlanRenderer};

/** Mail discovery and lazy tool dispatch; plans and effects belong to their dedicated work classes. */
final class MailModule extends CatalogModule {
	/** Grant module of the tools. */
	public const MODULE = 'mail';

	/** {@inheritDoc} */
	public static function catalog(): array { return MailToolCatalog::TOOLS; }

	/** {@inheritDoc} */
	public static function registration(): ?string { return MailRegistration::class; }

	/** {@inheritDoc} */
	public function guideNotes(): array { return MailGuideNotes::notes(); }

	/** {@inheritDoc} */
	public function renderPlan(string $tool, array $plan): ?string { return (new MailPlanRenderer())->render($tool, $plan); }
}
