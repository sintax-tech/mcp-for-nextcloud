<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Mcp\Guide;

use OCA\Mcp\Mcp\Guide\GuideSchema;
use PHPUnit\Framework\TestCase;
final class GuideSchemaTest extends TestCase {
    public function testNestedTypesConstraintsAndFalseDefaultsStayReadable(): void {
        $parameters = GuideSchema::parameters(['properties' => [
            'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]], 'minItems' => 1, 'maxItems' => 4],
            'flag' => ['type' => 'boolean', 'default' => false],
        ], 'required' => ['items']]);
        self::assertSame('- `items` (array of objects (name), required, at least 1 items, at most 4 items)', GuideSchema::parameterLine($parameters[0]));
        self::assertSame('- `flag` (boolean, optional, default false)', GuideSchema::parameterLine($parameters[1]));
    }
}
