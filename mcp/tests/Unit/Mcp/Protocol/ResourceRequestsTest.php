<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Mcp\Protocol;
use PHPUnit\Framework\TestCase;

use OCA\Mcp\Mcp\Protocol\ResourceRequests;
use OCA\Mcp\Mcp\Resources\ResourceRegistry;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
final class ResourceRequestsTest extends TestCase {
    public function testMissingHiddenAndInvalidResourcesKeepEraSpecificErrors(): void {
        $registry = $this->createMock(ResourceRegistry::class);
        $registry->method('read')->willThrowException(new ToolFailure('Synthetic resource refusal'));
        $registry->method('list')->willThrowException(new \InvalidArgumentException('/private/path'));
        foreach ([false, true] as $modern) {
            foreach (['list', 'templates', 'read'] as $method) {
                $response = (new ResourceRequests(null))->$method(1, ['uri' => 0, 'cursor' => 'invalid'], 'alice', $modern);
                self::assertSame([$modern ? 404 : 200, -32601], [$response['status'], $response['body']['error']['code']]);
            }
            $requests = new ResourceRequests($registry);
            self::assertSame(-32602, $requests->read(1, ['uri' => 0], 'alice', $modern)['body']['error']['code']);
            self::assertSame($modern ? -32602 : -32002, $requests->read(1, ['uri' => 'nc://files/synthetic'], 'alice', $modern)['body']['error']['code']);
            self::assertSame('Invalid arguments', $requests->list(1, [], 'alice', $modern)['body']['error']['message']);
        }
    }
}
