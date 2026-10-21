<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reading;

/** Keeps the established text block followed by its JSON metadata block. */
final readonly class FileRead {
    /** @param array<string, mixed> $metadata */
    public function __construct(public string $text, public array $metadata) {}

    public function toToolResult(): array {
        return ['content' => [
            ['type' => 'text', 'text' => $this->text],
            ['type' => 'text', 'text' => json_encode($this->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)],
        ]];
    }
}
