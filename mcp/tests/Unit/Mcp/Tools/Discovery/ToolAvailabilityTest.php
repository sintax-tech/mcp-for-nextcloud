<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Mcp\Tools\Discovery;

use OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability;
use OCA\Mcp\Tests\Support\Mcp\FakeRestrictedModule;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore;
use PHPUnit\Framework\TestCase;
final class ToolAvailabilityTest extends TestCase {
    public function testRoleGrantAndAppChecksKeepTheirOrder(): void {
        $policy = InMemoryConfig::policy((new InMemoryConfig())->mock($this), new InMemoryOAuthStore());
        $apps = $this->createMock(\OCP\App\IAppManager::class);
        $apps->expects(self::never())->method('isEnabledForUser');
        $users = $this->createMock(\OCP\IUserManager::class);
        $users->expects(self::never())->method('get');
        $availability = new ToolAvailability($policy, $apps, $users);
        $module = new FakeRestrictedModule();
        self::assertFalse($availability->permits($module, 'alice'));
        self::assertTrue($availability->permits($module, 'root'));
        self::assertFalse($availability->allowed(['module' => 'logs', 'operation' => 'read', 'app' => 'notes'], 'root'));
        $policy->setGrant('root', 'files', 'link', true);
        self::assertTrue($availability->allowed(['module' => 'files', 'operation' => 'share', 'grantAnyOf' => ['share', 'link']], 'root'));
        $policy->setGrant('root', 'files', 'link', false);
        self::assertFalse($availability->allowed(['module' => 'files', 'operation' => 'share', 'grantAnyOf' => ['share', 'link']], 'root'));
    }
}
