<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Deck;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\Deck\DeckModule;
use OCA\Mcp\Modules\Deck\DeckToolCatalog;
use OCA\Mcp\Modules\Deck\Tools\ListBoards;
use PHPUnit\Framework\TestCase;

final class DeckDispatchTest extends TestCase {
    private function factories(): array {
        return array_fill_keys(array_keys(DeckToolCatalog::definitions()), static fn () => throw new \LogicException('Unexpected tool resolution'));
    }

    public function testDefinitionsDoNotResolveTools(): void {
        $module = new DeckModule($this->factories(), $this->createMock(\OCA\Mcp\Modules\Deck\Access\DeckAvailability::class));
        self::assertSame(json_encode(array_values(DeckToolCatalog::definitions())), json_encode($module->definitions()));
        self::assertCount(13, $module->definitions());
        self::assertNotEmpty($module->guideNotes());
    }

    public function testOnlySelectedToolIsResolved(): void {
        $resolved = 0;
        $tool = new class implements Tool {
            public static function definition(): ToolDefinition { return ListBoards::definition(); }
            public function execute(array $arguments, ToolContext $context): array { return [$arguments, $context->userId]; }
        };
        $factories = $this->factories();
        $factories['deck_list_boards'] = static function () use (&$resolved, $tool): Tool { $resolved++; return $tool; };
        $module = new DeckModule($factories, $this->createMock(\OCA\Mcp\Modules\Deck\Access\DeckAvailability::class));
        self::assertSame([['path' => '/'], 'alice'], $module->call('deck_list_boards', ['path' => '/'], 'alice'));
        self::assertSame(1, $resolved);
    }

    public function testFactoryAndCatalogNamesAgree(): void {
        $factories = $this->factories();
        unset($factories['deck_list_boards']);
        $this->expectException(\LogicException::class);
        new DeckModule($factories, $this->createMock(\OCA\Mcp\Modules\Deck\Access\DeckAvailability::class));
    }

    public function testUnknownNamesDoNotResolveTools(): void {
        $module = new DeckModule($this->factories(), $this->createMock(\OCA\Mcp\Modules\Deck\Access\DeckAvailability::class));
        $this->expectException(\InvalidArgumentException::class);
        $module->call('deck_unknown', [], 'alice');
    }

    public function testMetadataDoesNotRequireAConstructedModule(): void {
        $module = (new \ReflectionClass(DeckModule::class))->newInstanceWithoutConstructor();
        self::assertCount(13, $module->definitions());
    }
    public function testResolvedToolMustMatchItsFactoryName(): void {
        $factories = $this->factories();
        $factories['deck_read_card'] = static fn () => new class implements Tool {
            public static function definition(): ToolDefinition { return ListBoards::definition(); }
            public function execute(array $arguments, ToolContext $context): array { throw new \LogicException('must not execute'); }
        };
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Deck factory resolved a different tool');
        (new DeckModule($factories, $this->createMock(\OCA\Mcp\Modules\Deck\Access\DeckAvailability::class)))->call('deck_read_card', [], 'alice');
    }

    public function testCatalogWriteRequiresWriteCapabilityAtResolution(): void {
        $factories = $this->factories();
        $factories['deck_edit_card'] = static fn () => new class implements Tool {
            public static function definition(): ToolDefinition { return \OCA\Mcp\Modules\Deck\Tools\EditCard::definition(); }
            public function execute(array $arguments, ToolContext $context): array { throw new \LogicException('must not execute'); }
        };
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Writing tools must implement WriteTool');
        (new DeckModule($factories, $this->createMock(\OCA\Mcp\Modules\Deck\Access\DeckAvailability::class)))->call('deck_edit_card', [], 'alice');
    }

    public function testPreviewResolvesOnlyTheSelectedWriterAndPassesIdentity(): void {
        $resolved = 0;
        $factories = $this->factories();
        $factories['deck_edit_card'] = static function () use (&$resolved) {
            $resolved++;
            return new class implements \OCA\Mcp\Mcp\Tools\WriteTool {
                public static function definition(): ToolDefinition { return \OCA\Mcp\Modules\Deck\Tools\EditCard::definition(); }
                public function execute(array $arguments, ToolContext $context): array { throw new \LogicException('Preview must not execute'); }
                public function preview(array $arguments, ToolContext $context): array { return [$arguments, $context->userId]; }
            };
        };
        $availability = $this->createMock(\OCA\Mcp\Modules\Deck\Access\DeckAvailability::class);
        $availability->expects(self::never())->method('assertFor');
        self::assertSame([['path' => '/a'], 'alice'], (new DeckModule($factories, $availability))->preview('deck_edit_card', ['path' => '/a'], 'alice'));
        self::assertSame(1, $resolved);
    }
    public function testUnknownAndReadPreviewKeepTheOriginalRefusalWithoutResolvingTools(): void {
        $module = new DeckModule($this->factories(), $this->createMock(\OCA\Mcp\Modules\Deck\Access\DeckAvailability::class));
        foreach (['deck_unknown', 'deck_list_boards'] as $name) {
            try {
                $module->preview($name, [], 'alice');
                self::fail('A non-writing tool cannot produce a write plan');
            } catch (\InvalidArgumentException $e) {
                self::assertSame(\OCA\Mcp\Modules\Deck\Execution\ErrorMessages::errorUnknownTool(), $e->getMessage());
            }
        }
    }
}
