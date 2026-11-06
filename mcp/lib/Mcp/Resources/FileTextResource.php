<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Mcp\Resources;

use OCP\Files\File;

/** File text extraction available to resource reads without a module dependency. */
interface FileTextResource {
	/** Characters returned before truncation. */
	public const MAX_CHARS = 100_000;
	/** @param string $name file name @param string $mime file MIME type @return bool whether extraction is supported */
	public function canExtract(string $name, string $mime): bool;
	/** @param string $name file name @param string $mime file MIME type @return string extracted text MIME type */
	public function textMime(string $name, string $mime): string;
	/** @param File $file readable native file @param int $maxChars character budget @return string extracted text */
	public function readText(File $file, int $maxChars = self::MAX_CHARS): string;
}
