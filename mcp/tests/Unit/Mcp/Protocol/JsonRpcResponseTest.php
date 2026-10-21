<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Mcp\Protocol;
use PHPUnit\Framework\TestCase;

use OCA\Mcp\Mcp\Protocol\JsonRpcResponse;
final class JsonRpcResponseTest extends TestCase {
    public function testSafeFieldsNeverExposeServerState(): void {
        foreach (['Invalid argument: items[1].boardId', 'Unknown tool'] as $message) {
            self::assertSame($message, JsonRpcResponse::safeMessage($message));
        }
        foreach (['/private/file.txt', 'Invalid argument: /secret', 'Invalid argument: password value', "\xFF"] as $message) {
            self::assertSame('Invalid arguments', JsonRpcResponse::safeMessage($message));
        }
        $empty = new \stdClass();
        self::assertSame($empty, JsonRpcResponse::result(1, $empty)['body']['result']);
        self::assertSame([], JsonRpcResponse::result(1, [])['body']['result']);
        self::assertSame(404, JsonRpcResponse::error(null, -32601, 'Method not found', 404)['status']);
    }
}
