<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Mcp\Protocol;
use PHPUnit\Framework\TestCase;

use OCA\Mcp\Mcp\Protocol\PromptRequests;
use OCA\Mcp\Mcp\Prompts\PromptCatalog;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore;
final class PromptRequestsTest extends TestCase {
    public function testGrantChangesAndArgumentAcceptanceAreUnchanged(): void {
        $policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new InMemoryOAuthStore());
        $policy->setGrant('alice', 'files', 'edit', true);
        $requests = new PromptRequests(new PromptCatalog(), $policy);
        $list = $requests->list('alice');
        self::assertNotEmpty($list);
        foreach ([false, true] as $modern) {
            $name = $list[0]['name'];
            self::assertArrayHasKey('result', $requests->get(1, ['name' => $name, 'arguments' => []], 'alice', $modern)['body']);
            self::assertArrayHasKey('result', $requests->get(1, ['name' => $name, 'arguments' => null], 'alice', $modern)['body']);
            self::assertSame(-32602, $requests->get(1, ['name' => $name, 'arguments' => ['extra' => true]], 'alice', $modern)['body']['error']['code']);
        }
        foreach (\OCA\Mcp\Access\Grants\GrantPolicy::CATALOG as $module => $operations) {
            foreach ($operations as $operation) { $policy->setGrant('alice', $module, $operation, false); }
        }
        self::assertSame([], $requests->list('alice'));
        self::assertSame(-32602, $requests->get(1, ['name' => $list[0]['name']], 'alice', false)['body']['error']['code']);
    }
}
