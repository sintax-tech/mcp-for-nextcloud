<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files;
use OCA\Mcp\Modules\Files\Extraction\TextExtractor;

use OCA\Mcp\Mcp\Tools\CatalogModule;

/**
 * Publishes Files tools and delegates workflow guidance and confirmation text.
 *
 * Every writing tool also answers {@see self::preview()}, which the registry asks for whenever the call
 * arrives without `confirm: true`. A plan reads what it needs — the file, its ETag, the diff, the backup
 * that would be created, the node the batch would touch — and writes nothing: no folder is created, no
 * checkout token is minted and no file is backed up before the user has said yes.
 *
 * A file locked by files_lock (open in Text or Office, locked by another person or a WebDAV client) is a warning in
 * the plan and a refusal of the confirmed call, decided before the shared-write confirmation and the backup
 * ({@see \OCA\Mcp\Platform\FileAccess\LockAwareWrite}). The user's own manual lock lets the write through, as in the Files app.
 */
final class FilesModule extends CatalogModule {
	/** Characters returned by files_read before the text is truncated. */
	public const MAX_CHARS = TextExtractor::MAX_CHARS;
	/** Maximum size of the new content accepted by files_edit and files_replace, in bytes. */
	public const MAX_EDIT_BYTES = 10 * 1024 * 1024;
	/** Folder in the user's root that receives a copy of every file before an edit writes it. */
	public const BACKUP_FOLDER = \OCA\Mcp\Modules\Files\Backups\FileBackup::FOLDER;
	/** Extra rows fetched by files_search to make up for unreadable matches filtered out afterwards. */
	public const SEARCH_OVERFETCH = 10;

	/** @return list<class-string<\OCA\Mcp\Mcp\Tools\Tool>> ordered Files tools */
	public static function catalog(): array { return FilesToolCatalog::TOOLS; }
	/** @return class-string<FilesRegistration> Files resource port registration */
	public static function registration(): ?string { return FilesRegistration::class; }
	/**
	 * What the schemas cannot say: how a path is spelled, which tool finds what, and where the writes stop.
	 *
	 * @return list<string>
	 */
	public function guideNotes(): array {
		return \OCA\Mcp\Modules\Files\Presentation\FilesGuideNotes::notes();
	}
	/**
	 * {@inheritDoc}
	 *
	 * The words live in {@see FilesPlanRenderer}; this module only hands the plan over.
	 */
	public function renderPlan(string $tool, array $plan): ?string {
		return \OCA\Mcp\Modules\Files\Presentation\FilesPlanRenderer::render($tool, $plan);
	}
}
