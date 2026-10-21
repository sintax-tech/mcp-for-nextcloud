<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Mcp\Tools\Discovery;

use OCA\Mcp\Mcp\Tools\Discovery\ToolPublication;
use PHPUnit\Framework\TestCase;
final class ToolPublicationTest extends TestCase {
    public function testProjectionKeepsOrderObjectsAndConfirmation(): void {
        $definition = ['name' => 'files_edit', 'description' => 'Synthetic', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()], 'module' => 'files', 'operation' => 'edit', 'app' => 'files', 'grantAnyOf' => ['edit']];
        $out = ToolPublication::present($definition);
        self::assertSame(['name', 'title', 'description', 'inputSchema', 'annotations', 'module', 'operation', 'app', 'grantAnyOf'], array_keys($out));
        self::assertSame('boolean', $out['inputSchema']['properties']['confirm']['type']);
        self::assertFalse($out['annotations']['readOnlyHint']);
        $definition['operation'] = 'read';
        self::assertInstanceOf(\stdClass::class, ToolPublication::present($definition)['inputSchema']['properties']);
    }
}
