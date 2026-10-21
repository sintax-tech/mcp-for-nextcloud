<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract;

use OCA\Mcp\Mcp\Tools\Tool;
use OCA\Mcp\Mcp\Tools\ToolContext;
use OCA\Mcp\Mcp\Tools\ToolDefinition;
use OCA\Mcp\Modules\People\PeopleModule;
use OCA\Mcp\Modules\People\PeopleToolCatalog;
use OCA\Mcp\Modules\People\Tools\SearchUsers;
use PHPUnit\Framework\TestCase;

final class PeopleCatalogTest extends TestCase {
    protected function setUp(): void {
        self::assertTrue(class_exists(PeopleModule::class), 'Final People publisher is missing');
    }

    private function wrongTool(): Tool {
        return new class implements Tool {
            public static function definition(): ToolDefinition {
                return new ToolDefinition('wrong_tool', 'people', 'write', 'Invalid fixture', []);
            }
            public function execute(array $arguments, ToolContext $context): array { throw new \LogicException('Must not execute'); }
        };
    }

    public function testPublicationIsLazyAndReadOnly(): void {
        $module = new PeopleModule(['users_search' => static fn () => throw new \LogicException('Must not resolve')]);
        self::assertSame(['users_search'], array_column($module->definitions(), 'name'));
        self::assertSame([SearchUsers::class], PeopleToolCatalog::classes());
        self::assertCount(3, $module->guideNotes());
        self::assertNotInstanceOf(\OCA\Mcp\Mcp\Confirmation\PreviewsWrites::class, $module);
        self::assertNotInstanceOf(\OCA\Mcp\Mcp\Confirmation\RendersPlans::class, $module);
        self::assertFalse(is_a(SearchUsers::class, \OCA\Mcp\Mcp\Tools\WriteTool::class, true));
    }

    public function testInvalidFactoryMapsAreRejected(): void {
        foreach ([[], ['users_search' => 'not a closure'], ['users_search' => static fn () => null, 'extra' => static fn () => null]] as $factories) {
            try { new PeopleModule($factories); self::fail('Invalid map accepted'); }
            catch (\LogicException) { self::addToAssertionCount(1); }
        }
    }

    public function testUnknownNameDoesNotResolveTheFactory(): void {
        $module = new PeopleModule(['users_search' => static fn () => throw new \LogicException('Must not resolve')]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown tool');
        $module->call('unknown', [], 'alice');
    }

    public function testMismatchedResultsNeverExecute(): void {
        foreach ([new \stdClass(), $this->wrongTool()] as $wrong) {
            $module = new PeopleModule(['users_search' => static fn () => $wrong]);
            try { $module->call('users_search', ['query' => 'ok'], 'alice'); self::fail('Mismatched tool accepted'); }
            catch (\LogicException $e) { self::assertSame('Factory returned a different tool', $e->getMessage()); }
        }
    }

    public function testCatalogRejectsDuplicatesNonToolsAndNonReads(): void {
        foreach ([[SearchUsers::class, SearchUsers::class], [\stdClass::class], [$this->wrongTool()::class]] as $classes) {
            try { PeopleToolCatalog::definitions($classes); self::fail('Invalid catalog accepted'); }
            catch (\LogicException) { self::addToAssertionCount(1); }
        }
    }
}
