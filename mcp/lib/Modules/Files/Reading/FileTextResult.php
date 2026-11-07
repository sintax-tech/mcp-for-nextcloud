<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reading;



/** Builds the text-content response with a separate JSON metadata block. */
final class FileTextResult {
	/**
	 * @param string $text the file text, unchanged
	 * @param array<string, mixed> $metadata path, etag and access of the file it came from
	 * @return array{content: list<array{type:string, text:string}>}
	 */
	public function render(string $text, array $metadata): array {
		return [
			'content' => [
				['type' => 'text', 'text' => $text],
				['type' => 'text', 'text' => json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)],
			],
		];
	}
}
