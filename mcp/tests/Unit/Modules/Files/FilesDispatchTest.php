<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Files;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Files\FilesModule;
use OCA\Mcp\Modules\Files\FilesToolCatalog;
use OCA\Mcp\Modules\Files\Tools\ListFiles;
use PHPUnit\Framework\TestCase;

final class FilesDispatchTest extends TestCase {
    private function factories(): array {
        return array_fill_keys(array_keys(FilesToolCatalog::definitions()), static fn () => throw new \LogicException('Unexpected tool resolution'));
    }

    public function testDefinitionsDoNotResolveTools(): void {
        $module = new FilesModule($this->factories(), (\OCA\Mcp\Tests\Support\Files\FilesGuards::unrestricted($this))->assertScopeReadable(...));
        self::assertSame(array_values(FilesToolCatalog::definitions()), $module->definitions());
        self::assertCount(23, $module->definitions());
        self::assertNotEmpty($module->guideNotes());
    }

    public function testOnlySelectedToolIsResolved(): void {
        $resolved = 0;
        $tool = new class implements Tool {
            public static function definition(): ToolDefinition { return ListFiles::definition(); }
            public function execute(array $arguments, ToolContext $context): array { return [$arguments, $context->userId]; }
        };
        $factories = $this->factories();
        $factories['files_list'] = static function () use (&$resolved, $tool): Tool { $resolved++; return $tool; };
        $module = new FilesModule($factories, (\OCA\Mcp\Tests\Support\Files\FilesGuards::unrestricted($this))->assertScopeReadable(...));
        self::assertSame([['path' => '/'], 'alice'], $module->call('files_list', ['path' => '/'], 'alice'));
        self::assertSame(1, $resolved);
    }

    public function testFactoryAndCatalogNamesAgree(): void {
        $factories = $this->factories();
        unset($factories['files_list']);
        $this->expectException(\LogicException::class);
        new FilesModule($factories, (\OCA\Mcp\Tests\Support\Files\FilesGuards::unrestricted($this))->assertScopeReadable(...));
    }

    public function testUnknownNamesDoNotResolveTools(): void {
        $module = new FilesModule($this->factories(), (\OCA\Mcp\Tests\Support\Files\FilesGuards::unrestricted($this))->assertScopeReadable(...));
        $this->expectException(\InvalidArgumentException::class);
        $module->call('files_unknown', [], 'alice');
    }

    public function testMetadataDoesNotRequireAConstructedModule(): void {
        $module = (new \ReflectionClass(FilesModule::class))->newInstanceWithoutConstructor();
        self::assertCount(23, $module->definitions());
    }
    public function testResolvedToolMustMatchItsFactoryName(): void {
        $factories = $this->factories();
        $factories['files_read'] = static fn () => new class implements Tool {
            public static function definition(): ToolDefinition { return ListFiles::definition(); }
            public function execute(array $arguments, ToolContext $context): array { throw new \LogicException('must not execute'); }
        };
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Files factory resolved a different tool');
        (new FilesModule($factories, (\OCA\Mcp\Tests\Support\Files\FilesGuards::unrestricted($this))->assertScopeReadable(...)))->call('files_read', [], 'alice');
    }

    public function testCatalogWriteRequiresWriteCapabilityAtResolution(): void {
        $factories = $this->factories();
        $factories['files_edit'] = static fn () => new class implements Tool {
            public static function definition(): ToolDefinition { return \OCA\Mcp\Modules\Files\Tools\EditFile::definition(); }
            public function execute(array $arguments, ToolContext $context): array { throw new \LogicException('must not execute'); }
        };
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Writing tools must implement WriteTool');
        (new FilesModule($factories, (\OCA\Mcp\Tests\Support\Files\FilesGuards::unrestricted($this))->assertScopeReadable(...)))->call('files_edit', [], 'alice');
    }

    public function testPreviewResolvesOnlyTheSelectedWriterAndPassesIdentity(): void {
        $resolved = 0;
        $factories = $this->factories();
        $factories['files_edit'] = static function () use (&$resolved) {
            $resolved++;
            return new class implements \OCA\Mcp\Mcp\Tools\WriteTool {
                public static function definition(): ToolDefinition { return \OCA\Mcp\Modules\Files\Tools\EditFile::definition(); }
                public function execute(array $arguments, ToolContext $context): array { throw new \LogicException('Preview must not execute'); }
                public function preview(array $arguments, ToolContext $context): array { return [$arguments, $context->userId]; }
            };
        };
        self::assertSame([['path' => '/a'], 'alice'], (new FilesModule($factories, (\OCA\Mcp\Tests\Support\Files\FilesGuards::unrestricted($this))->assertScopeReadable(...)))->preview('files_edit', ['path' => '/a'], 'alice'));
        self::assertSame(1, $resolved);
    }
}
