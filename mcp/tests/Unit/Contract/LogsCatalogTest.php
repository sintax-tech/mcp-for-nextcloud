<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract;

use OCA\Mcp\Modules\Logs\{LogsModule, LogsToolCatalog};
use OCA\Mcp\Modules\Logs\Tools\{ListLogs, AnalyzeLogs};
use OCA\Mcp\Mcp\Tools\{Tool, ToolContext, ToolDefinition};
use PHPUnit\Framework\TestCase;

final class LogsCatalogTest extends TestCase {
    protected function setUp(): void { self::assertTrue(class_exists(LogsModule::class), 'Final Logs publisher is missing'); }

    private static function stop(): \Closure { return static fn () => throw new \LogicException('Must not resolve'); }
    private static function factories(): array { return ['logs_list' => self::stop(), 'logs_analyze' => self::stop()]; }
    private function wrongTool(): Tool {
        return new class implements Tool {
            public static function definition(): ToolDefinition { return new ToolDefinition('wrong_tool', 'logs', 'write', 'Invalid fixture', []); }
            public function execute(array $arguments, ToolContext $context): array { throw new \LogicException('Must not execute'); }
        };
    }

    public function testLogsPublicationIsLazyRestrictedAndReadOnly(): void {
        $module = new LogsModule(self::factories(), self::stop(), self::stop());
        self::assertSame(['logs_list', 'logs_analyze'], array_column($module->definitions(), 'name'));
        self::assertSame([ListLogs::class, AnalyzeLogs::class], LogsToolCatalog::classes());
        self::assertCount(6, $module->guideNotes());
        self::assertInstanceOf(\OCA\Mcp\Mcp\Tools\RestrictedModule::class, $module);
        self::assertNotInstanceOf(\OCA\Mcp\Mcp\Confirmation\PreviewsWrites::class, $module);
        self::assertNotInstanceOf(\OCA\Mcp\Mcp\Confirmation\RendersPlans::class, $module);
        foreach (LogsToolCatalog::classes() as $class) { self::assertFalse(is_a($class, \OCA\Mcp\Mcp\Tools\WriteTool::class, true)); }
    }

    public function testLogsInvalidFactoryMapsAreRejected(): void {
        foreach ([[], ['logs_list' => self::stop()], ['logs_list' => 'not a closure', 'logs_analyze' => self::stop()], self::factories() + ['extra' => self::stop()]] as $map) {
            try { new LogsModule($map, self::stop(), self::stop()); self::fail('Invalid map accepted'); }
            catch (\LogicException) { self::addToAssertionCount(1); }
        }
    }

    public function testLogsUnknownNamesResolveNoFactory(): void {
        $module = new LogsModule(self::factories(), self::stop(), self::stop());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown tool');
        $module->call('unknown', [], 'alice');
    }

    public function testLogsMismatchedFactoryResultsNeverExecute(): void {
        foreach ([new \stdClass(), $this->wrongTool()] as $wrong) {
            $module = new LogsModule(['logs_list' => static fn () => $wrong, 'logs_analyze' => self::stop()], self::stop(), self::stop());
            try { $module->call('logs_list', [], 'alice'); self::fail('Wrong tool accepted'); }
            catch (\LogicException $e) { self::assertSame('Factory returned a different tool', $e->getMessage()); }
        }
    }

    public function testLogsCatalogRejectsDuplicatesNonToolsAndNonReads(): void {
        foreach ([[ListLogs::class, ListLogs::class], [\stdClass::class], [$this->wrongTool()::class]] as $classes) {
            try { LogsToolCatalog::definitions($classes); self::fail('Invalid catalog accepted'); }
            catch (\LogicException) { self::addToAssertionCount(1); }
        }
    }

    public function testLogsRoleAndAuditFactoriesAreIndependentAndTyped(): void {
        foreach (['permits', 'audit'] as $method) {
            $module = new LogsModule(self::factories(), static fn () => new \stdClass(), static fn () => new \stdClass());
            try {
                $method === 'permits' ? $module->permits('alice') : $module->audit('logs_list', 'alice', 'denied', []);
                self::fail('Wrong role/audit dependency accepted');
            } catch (\LogicException) { self::addToAssertionCount(1); }
        }
        $access = $this->createMock(\OCA\Mcp\Access\Logs\LogsAccess::class);
        $access->expects(self::once())->method('permits')->with('alice')->willReturn(true);
        self::assertTrue((new LogsModule(self::factories(), static fn () => $access, self::stop()))->permits('alice'));
        $audit = $this->createMock(\OCA\Mcp\Modules\Logs\Audit\LogAudit::class);
        $audit->expects(self::once())->method('recordCall')->with('logs_list', 'alice', 'denied', ['contains' => 'synthetic']);
        (new LogsModule(self::factories(), self::stop(), static fn () => $audit))->audit('logs_list', 'alice', 'denied', ['contains' => 'synthetic']);
    }
}
