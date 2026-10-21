<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Mcp\Tools;

use OCA\Mcp\Mcp\Tools\ToolDefinition;
use PHPUnit\Framework\TestCase;

final class ToolContractTest extends TestCase {
    public function testDefinitionPreservesSchemaAndOmitsAbsentApp(): void {
        $schema = ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer', 'default' => 10]], 'additionalProperties' => false];
        $definition = new ToolDefinition('files_read', 'files', 'read', 'Read text.', $schema);
        $this->assertSame([
            'name' => 'files_read', 'module' => 'files', 'operation' => 'read',
            'description' => 'Read text.', 'inputSchema' => $schema,
        ], $definition->toArray());
        $this->assertSame('notes', (new ToolDefinition('notes_read', 'notes', 'read', 'Read a note.', $schema, 'notes'))->toArray()['app']);
    }
    public function testOptionalPublicationMetadataSurvivesRoundTrip(): void {
        $definition = new ToolDefinition('files_share', 'files', 'share', 'Share.', [], grantAnyOf: ['share', 'link'], destructiveHint: false);
        self::assertSame([
            'name' => 'files_share', 'module' => 'files', 'operation' => 'share',
            'grantAnyOf' => ['share', 'link'], 'destructiveHint' => false,
            'description' => 'Share.', 'inputSchema' => [],
        ], $definition->toArray());
        self::assertTrue((new ToolDefinition('files_share', 'files', 'share', 'Share.', [], destructiveHint: true))->toArray()['destructiveHint']);
    }
}
