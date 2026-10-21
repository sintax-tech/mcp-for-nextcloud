<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Files;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Files\FilesToolCatalog;
use OCA\Mcp\Modules\Files\Tools\EditFile;
use OCA\Mcp\Modules\Files\Tools\ReadFile;
use PHPUnit\Framework\TestCase;

final class FilesToolCatalogTest extends TestCase {
    public function testExplicitSubsetCanBeEnumeratedWithoutConstruction(): void {
        self::assertSame(['files_read', 'files_edit'], array_keys(FilesToolCatalog::definitions([ReadFile::class, EditFile::class])));
    }

    public function testDuplicateNamesAreRejectedWithoutConstruction(): void {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Duplicate tool name');
        FilesToolCatalog::definitions([ReadFile::class, ReadFile::class]);
    }

    public function testWritesMustImplementPreview(): void {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must implement WriteTool');
        FilesToolCatalog::definitions([UnpreviewableEdit::class]);
    }

    public function testUnrelatedClassesAreRejected(): void {
        $this->expectException(\LogicException::class);
        FilesToolCatalog::definitions([\stdClass::class]);
    }
}

final class UnpreviewableEdit implements Tool {
    public function __construct() {
        throw new \LogicException('Metadata enumeration must not instantiate services');
    }

    public static function definition(): ToolDefinition {
        return new ToolDefinition('invalid_edit', 'files', 'edit', 'Invalid fixture', []);
    }

    public function execute(array $arguments, ToolContext $context): array {
        throw new \LogicException('Not callable');
    }
}
