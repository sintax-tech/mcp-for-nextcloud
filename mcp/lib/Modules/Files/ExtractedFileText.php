<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Files;

use OCA\Mcp\Mcp\Resources\FileTextResource;
use OCP\Files\File;

/** Adapts the existing extractor to the resource port. */
final class ExtractedFileText implements FileTextResource {
	/** @param TextExtractor $extractor existing Files text extraction */
	public function __construct(private TextExtractor $extractor) {
	}
	/** {@inheritDoc} */
	public function canExtract(string $name, string $mime): bool { return TextExtractor::canExtract($name, $mime); }
	/** {@inheritDoc} */
	public function textMime(string $name, string $mime): string { return TextExtractor::textMime($name, $mime); }
	/** {@inheritDoc} */
	public function readText(File $file, int $maxChars = self::MAX_CHARS): string { return $this->extractor->readText($file, $maxChars); }
}
