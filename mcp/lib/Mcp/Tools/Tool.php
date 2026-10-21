<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Mcp\Tools;

/** A single tool behind the existing registry's validation and access policy. */
interface Tool {
    public static function definition(): ToolDefinition;

    /** @param array<string, mixed> $arguments validated by the public registry */
    public function execute(array $arguments, ToolContext $context): array;
}
