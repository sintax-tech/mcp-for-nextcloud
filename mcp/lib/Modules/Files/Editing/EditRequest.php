<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Editing;

/** Validated tool arguments; authenticated identity comes separately from ToolContext. */
final readonly class EditRequest {
    public function __construct(
        public string $path,
        public string $content,
        public ?string $etag = null,
        public bool $confirmShared = false,
    ) {}

    public static function fromArray(array $arguments): self {
        return new self($arguments['path'], $arguments['content'], $arguments['etag'] ?? null, (bool)($arguments['confirm_shared'] ?? false));
    }
}
