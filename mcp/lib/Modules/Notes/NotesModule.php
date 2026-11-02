<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Notes;

use OCA\Mcp\Mcp\Tools\CatalogModule;
use OCA\Mcp\Modules\Notes\Locating\NotesRepository;
use OCA\Mcp\Modules\Notes\Presentation\NotesGuideNotes;
use OCA\Mcp\Modules\Notes\Presentation\NotesPlanRenderer;

/**
 * The Notes module: the tools of {@see NotesToolCatalog}, their behaviour notes and the Markdown of their plans.
 * Every write is planned first and only runs when the same call comes back with `confirm: true`; a note open in Text
 * is refused before anything changes, and a deletion only happens where the trash bin takes the note back.
 */
final class NotesModule extends CatalogModule {
	/** Maximum note size read or written, in bytes. */
	public const MAX_BYTES = NotesRepository::MAX_BYTES;

	/**
	 * @return list<class-string<\OCA\Mcp\Mcp\Tools\Tool>> the Notes tools, in tools/list order
	 */
	public static function catalog(): array {
		return NotesToolCatalog::TOOLS;
	}

	/**
	 * @return class-string<NotesRegistration> the DI of this module
	 */
	public static function registration(): ?string {
		return NotesRegistration::class;
	}

	/**
	 * @return list<string> what the schemas cannot say: how a note is named and what the module refuses to do
	 */
	public function guideNotes(): array {
		return NotesGuideNotes::notes();
	}

	/**
	 * {@inheritDoc}
	 */
	public function renderPlan(string $tool, array $plan): ?string {
		return (new NotesPlanRenderer())->render($tool, $plan);
	}
}
