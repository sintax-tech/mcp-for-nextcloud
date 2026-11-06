<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Compat;

use OCA\Mcp\OAuth\Tokens\AccessTokenAuthenticator;
use OCA\Mcp\Platform\Backups\BackupLocation;
use OCP\IAppConfig;
use OCP\Server;

use PHPUnit\Framework\Assert;

/** Checks the existing upgrade contract without adding a test identity. */
final class ReleaseUpgradeProof {
	/** @return void Asserts preservation of the real baseline checkpoint. */
	public static function assertPreserved(): void {
		$before = UpgradeBaseline::forRelease();
		$version = $before['baseline'];
		Assert::assertNotEmpty(getenv('MCP_IT_EXPECTED_VERSION'));
		Assert::assertSame(getenv('MCP_IT_EXPECTED_VERSION'), Server::get(IAppConfig::class)->getValueString('mcp', 'installed_version'));
		Assert::assertSame($before['snapshot'], ReleaseUpgradeProbe::snapshot(false, $version));
		Assert::assertGreaterThan(0, $before['snapshot']['rows']['preferences']['count']);
		Assert::assertSame(1, $before['snapshot']['rows']['mcp_oauth_tokens']['count']);
		if ($version !== '1.2.0') {
			Assert::assertGreaterThanOrEqual(1, $before['snapshot']['receipts']['count']);
		}
		$credentialPath = (string)getenv('MCP_IT_LEGACY') . ($version === '1.2.0' ? '' : '.release.json');
		$credential = json_decode((string)file_get_contents($credentialPath), true, 512, JSON_THROW_ON_ERROR);
		Assert::assertSame($version, $credential['baseline']);
		$user = Server::get(AccessTokenAuthenticator::class)->authenticate('Bearer ' . $credential['token'], ReleaseUpgradeProbe::RESOURCE);
		Assert::assertSame(ReleaseUpgradeProbe::UID, $user?->getUID(), 'Baseline OAuth credential must still authenticate');
		Assert::assertSame(BackupLocation::defaults(), Server::get(BackupLocation::class)->current());
		Assert::assertTrue(BackupLocation::contains('/' . ReleaseUpgradeProbe::BACKUP, Server::get(BackupLocation::class)));
	}
}
