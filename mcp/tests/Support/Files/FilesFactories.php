<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Support\Files;

use OCA\Mcp\Modules\Files\FilesToolCatalog;

final class FilesFactories {
    /** @param array<string, \Closure(): \OCA\Mcp\Mcp\Tools\Tool> $selected */
    public static function complete(array $selected): array {
        $factories = [];
        foreach (FilesToolCatalog::definitions() as $name => $_definition) {
            $factories[$name] = $selected[$name] ?? static fn () => throw new \LogicException('Unexpected tool in focused fixture: ' . $name);
        }
        return $factories;
    }
}
