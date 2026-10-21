<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Mcp\Protocol;
use PHPUnit\Framework\TestCase;

use OCA\Mcp\Mcp\Protocol\ToolRequests;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
final class ToolRequestsTest extends TestCase {
    public function testDiagnosticAndValidationEnvelopesMatchBothEras(): void {
        $registry = $this->createMock(ToolRegistry::class);
        $registry->expects(self::never())->method('call');
        $requests = new ToolRequests($registry);
        self::assertSame('mcp_status', $requests->list('alice')[0]['name']);
        foreach ([false, true] as $modern) {
            $result = $requests->call('id', ['name' => 'mcp_status'], 'alice', $modern);
            self::assertSame('MCP endpoint available', $result['body']['result']['content'][0]['text']);
            self::assertSame($modern, isset($result['body']['result']['resultType']));
            self::assertSame(['status' => 200, 'body' => ['jsonrpc' => '2.0', 'id' => 4, 'error' => ['code' => -32602, 'message' => 'Invalid arguments']]],
                $requests->call(4, ['name' => 'mcp_status', 'arguments' => ['private' => 'secret']], 'alice', $modern));
        }
    }
}
