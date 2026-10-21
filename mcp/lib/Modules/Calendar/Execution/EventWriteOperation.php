<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare (strict_types=1);
namespace OCA\Mcp\Modules\Calendar\Execution;

interface EventWriteOperation
{
    public function prepare(array $arguments, string $userId): PreparedEventWrite|array;
    public function execute(array $arguments, string $userId): array;
}
