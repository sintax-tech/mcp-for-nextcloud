<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract;

use OCA\Mcp\Modules\Notes\NotesModule;
use OCA\Mcp\Modules\Notes\NotesToolCatalog;
use PHPUnit\Framework\TestCase;
final class NotesCatalogTest extends TestCase
{

    public function testStaticPublicationAndSavedPlansNeverResolveTools(): void
    {
        self::assertTrue(class_exists(NotesToolCatalog::class), 'Final Notes catalog is missing');
        $names = ['notes_list', 'notes_search', 'notes_read', 'notes_create', 'notes_edit', 'notes_move', 'notes_delete'];
        $factories = array_fill_keys($names, static fn() => throw new \LogicException('Tool factory was invoked'));
        $module = new NotesModule($factories);
        self::assertSame($names, array_column($module->definitions(), 'name'));
        self::assertCount(7, $module->guideNotes());
        self::assertNull($module->renderPlan('notes_edit', []));
        self::assertNull($module->renderPlan('unknown', []));
        $this->expectException(\InvalidArgumentException::class);
        $module->preview('notes_read', [], 'alice');
    }

    public function testCatalogRejectsDuplicateTools(): void
    {
        self::assertTrue(class_exists(NotesToolCatalog::class));
        $this->expectException(\LogicException::class);
        NotesToolCatalog::definitions([\OCA\Mcp\Modules\Notes\Tools\ReadNote::class, \OCA\Mcp\Modules\Notes\Tools\ReadNote::class]);
    }

    public function testCatalogRejectsWritesWithoutPreviewContract(): void
    {
        $tool = new class implements \OCA\Mcp\Mcp\Tools\Tool
        {
            public static function definition(): \OCA\Mcp\Mcp\Tools\ToolDefinition
            {
                return new \OCA\Mcp\Mcp\Tools\ToolDefinition('notes_bad', 'notes', 'write', 'Invalid write', []);
            }
            public function execute(array $arguments, \OCA\Mcp\Mcp\Tools\ToolContext $context): array
            {
                return [];
            }
        };
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Writes must implement WriteTool');
        NotesToolCatalog::definitions([$tool::class]);
    }

    public function testModuleRejectsMissingFactory(): void
    {
        $this->expectException(\LogicException::class);
        new NotesModule([]);
    }

    public function testModuleRejectsMismatchedFactoryResult(): void
    {
        $factories = array_fill_keys(array_keys(NotesToolCatalog::definitions()), static fn() => new \stdClass());
        $module = new NotesModule($factories);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Factory returned a different tool');
        $module->call('notes_read', ['id' => 1], 'alice');
    }

    public function testUnknownNameIsRejectedWithoutResolvingTools(): void
    {
        $factories = array_fill_keys(array_keys(NotesToolCatalog::definitions()), static fn() => throw new \LogicException('Unexpected resolution'));
        $module = new NotesModule($factories);
        $this->expectException(\InvalidArgumentException::class);
        $module->call('unknown', [], 'alice');
    }
}
