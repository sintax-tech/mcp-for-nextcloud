<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Files\Extraction;

use OCA\Mcp\Mcp\Resources\FileTextResource;
use OCP\Files\File;

/** Exposes document format detection and text extraction through the file resource port. */
final class ExtractedFileText implements FileTextResource {
	/** @param TextExtractor $extractor document text extraction service */
	public function __construct(private TextExtractor $extractor) {
	}
	/** {@inheritDoc}
	 * @param string $name filename used for format detection
	 * @param string $mime declared MIME type used for format detection
	 */
	public function canExtract(string $name, string $mime): bool { return TextExtractor::canExtract($name, $mime); }
	/** {@inheritDoc}
	 * @param string $name filename used for format detection
	 * @param string $mime declared MIME type used for format detection
	 */
	public function textMime(string $name, string $mime): string { return TextExtractor::textMime($name, $mime); }
	/** {@inheritDoc}
	 * @param File $file visible file whose text is extracted
	 * @param int $maxChars maximum number of extracted characters
	 */
	public function readText(File $file, int $maxChars = self::MAX_CHARS): string { return $this->extractor->readText($file, $maxChars); }
}
