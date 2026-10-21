<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Mcp\Tools\Execution;

use OCA\Mcp\Mcp\Tools\Execution\ToolExecutor;
use OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Tests\Support\Mcp\FakeRestrictedModule;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore;
use PHPUnit\Framework\TestCase;
final class ToolExecutorTest extends TestCase {
    public function testAuditUsesCurrentArgumentsAndPreservesFailureBoundaries(): void {
        $policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new InMemoryOAuthStore());
        $policy->setGrant('root', 'logs', 'read', true);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->method('error')->willThrowException(new \RuntimeException('logger unavailable'));
        $executor = new ToolExecutor(new ToolAvailability($policy, $this->createMock(\OCP\App\IAppManager::class), $this->createMock(\OCP\IUserManager::class)), $logger);
        $module = new FakeRestrictedModule();
        $definition = $module->definitions()[0];
        self::assertSame('ok', $executor->call($module, $definition, [], 'root')['content'][0]['text']);
        self::assertSame([['logs_list', 'root', 'success', ['n' => 5]]], $module->audits);
        $module->auditThrow = new \RuntimeException('audit unavailable');
        self::assertTrue($executor->call($module, $definition, [], 'root')['isError']);
        $module->throw = new ToolFailure('Synthetic refusal');
        self::assertSame('Synthetic refusal', $executor->call($module, $definition, [], 'root')['content'][0]['text']);
        $module->audits = [];
        $module->throw = new \RuntimeException('private');
        try { $executor->call($module, $definition, [], 'root'); self::fail('Expected logger propagation'); }
        catch (\RuntimeException $e) { self::assertSame('logger unavailable', $e->getMessage()); }
        self::assertSame([], $module->audits);
    }
}
