<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Compat;

use OCA\Mcp\OAuth\AccessTokenAuthenticator;
use OCA\Mcp\Service\BackupLocation;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\IAppConfig;
use OCP\Server;

/** The release upgrade retains real baseline data and its still-live credential. */
final class ReleaseUpgradeTest extends IntegrationTestCase {
	/** @return void compare the 1.2.0 checkpoint before any later suite mutates policy or fixtures */
	public function testUpgradePreservesConfigurationGrantsCredentialAndLegacyBackup(): void {
		$before = UpgradeBaseline::forVersion('1.2.0');
		self::assertSame('1.2.0', $before['baseline']);
		self::assertSame('1.2.1-rc.2', Server::get(IAppConfig::class)->getValueString('mcp', 'installed_version'));
		self::assertSame($before['snapshot'], ReleaseUpgradeProbe::snapshot());
		self::assertGreaterThan(0, $before['snapshot']['rows']['preferences']['count']);
		self::assertSame(1, $before['snapshot']['rows']['mcp_oauth_tokens']['count']);
		$credential = json_decode((string)file_get_contents((string)getenv('MCP_IT_LEGACY')), true, 512, JSON_THROW_ON_ERROR);
		$user = Server::get(AccessTokenAuthenticator::class)->authenticate('Bearer ' . $credential['token'], ReleaseUpgradeProbe::RESOURCE);
		self::assertSame(ReleaseUpgradeProbe::UID, $user?->getUID(), 'Baseline OAuth credential must still authenticate');
		self::assertSame(BackupLocation::defaults(), Server::get(BackupLocation::class)->current());
		self::assertTrue(BackupLocation::contains('/' . ReleaseUpgradeProbe::BACKUP, Server::get(BackupLocation::class)));
	}
}
