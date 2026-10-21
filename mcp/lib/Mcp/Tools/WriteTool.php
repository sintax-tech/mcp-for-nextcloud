<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Mcp\Tools;

interface WriteTool extends Tool {
    /**
     * Describe the change without side effects; WriteGate adds the public envelope.
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function preview(array $arguments, ToolContext $context): array;
}
