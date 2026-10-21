<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Logs\Audit;

use OCA\Mcp\Modules\Logs\Audit\LogAudit;
use PHPUnit\Framework\TestCase;

final class LogAuditTest extends TestCase {
    public function testRawArgumentsAreBoundedBeforeNativeRecording(): void {
        self::assertTrue(method_exists(LogAudit::class, 'recordCall'), 'Audit argument ownership has not moved');
        $events = [];
        $dispatcher = $this->createMock(\OCP\EventDispatcher\IEventDispatcher::class);
        $dispatcher->method('dispatchTyped')->willReturnCallback(static function ($event) use (&$events): void { $events[] = $event; });
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with('MCP logs request denied', self::callback(static fn ($context): bool => $context['user'] === 'alice' && $context['outcome'] === 'denied'));
        $audit = new LogAudit($dispatcher, $logger);
        $audit->recordCall('logs_list', 'alice', 'denied', ['limit' => false, 'offset' => 2, 'contains' => 'synthetic-secret', 'app' => ['ignored'], 'user' => str_repeat('u', 201), 'unknown' => 'ignored']);
        self::assertSame('MCP logs request %s for %s: tool=%s filters=%s', $events[0]->getLogMessage());
        self::assertSame(['outcome', 'user', 'tool', 'filters'], array_keys($events[0]->getParameters()));
        self::assertSame(['user' => str_repeat('u', 200).'…', 'contains' => '[hidden]', 'offset' => 2], json_decode($events[0]->getParameters()['filters'], true));
    }
}
