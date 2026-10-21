<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Compat;

use OCA\Mcp\Db\OAuthClientsMapper;
use OCA\Mcp\OAuth\ClientResolver;
use OCA\Mcp\OAuth\NativeClient;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\IConfig;
use OCP\Server;

/**
 * The upgrade from the released baseline carries the native client's switch, which 1.0.8 kept in the app configuration
 * (ci/legacy-credentials.php turned it on through the baseline before `occ upgrade`), into the app's own table of
 * clients, which is the only source of admission from 1.1.0 on: on, generation 1, with no terminal step. The legacy
 * value is left where it was. Runs in the compat suite, before any other test can touch the switch.
 */
final class NativeClientMigrationTest extends IntegrationTestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!in_array(getenv('MCP_IT_MODE'), ['upgrade', 'upgrade-local'], true)) {
            $this->markTestSkipped('Only an upgrade cell has the switch the baseline wrote');
        }
    }

    public function testTheNativeSwitchOfTheBaselineIsInTheTableAfterTheUpgrade(): void {
        $row = (new OAuthClientsMapper($this->openIndependentConnection()))->find(NativeClient::CLIENT_ID);
        $this->assertNotNull($row, 'the migration imported the switch');
        $this->assertSame([OAuthClientsMapper::KIND_NATIVE, true, '1'], [$row['kind'], $row['enabled'], $row['gen']]);
        $this->assertSame('1', ClientResolver::nativeGeneration(Server::get(OAuthClientsMapper::class)));
        $this->assertSame(NativeClient::CLIENT_ID, Server::get(ClientResolver::class)->resolve(NativeClient::CLIENT_ID)->clientId);
        $this->assertSame('1', Server::get(IConfig::class)->getAppValue('mcp', NativeClient::CONFIG_KEY), 'the legacy value is left as it was');
    }
}
