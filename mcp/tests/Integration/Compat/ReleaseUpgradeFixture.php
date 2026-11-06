<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Compat;

use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IUserManager;
use OCP\Server;

/** Creates the probe's data using only the installed baseline's public services. */
final class ReleaseUpgradeFixture {
	/** @param string $version Recognized installed baseline. @return array<string, string> Private credential sidecar. */
	public static function create(string $version): array {
		$users = Server::get(IUserManager::class);
		$users->createUser(ReleaseUpgradeProbe::UID, bin2hex(random_bytes(24)));
		$policy = Server::get(LegacyPolicyName::get($version));
		$policy->setGlobalEnabled(true);
		$policy->setEligible(ReleaseUpgradeProbe::UID, true);
		$policy->setConnected(ReleaseUpgradeProbe::UID, true);
		if ($version === '1.2.0') {
			$policy->setGrant(ReleaseUpgradeProbe::UID, 'files', 'edit', true);
		} else {
			$revision = Server::get(\OCA\Mcp\Db\PolicyStateMapper::class)->state()['revision'];
			$result = Server::get(LegacyPolicyName::writer($version))->applyCells($revision, ReleaseUpgradeProbe::UID,
				'grants.update', [['uid' => ReleaseUpgradeProbe::UID, 'key' => 'grant_files_edit', 'value' => '1']]);
			if ($result['receiptId'] === null) {
				throw new \RuntimeException('The baseline did not produce the policy receipt');
			}
		}
		$policy->setGrant(ReleaseUpgradeProbe::UID, 'files', 'share', false);
		Server::get(IAppConfig::class)->setValueString('mcp', 'rc_upgrade_marker', 'preserve-1.2.0');
		$root = Server::get(IRootFolder::class)->getUserFolder(ReleaseUpgradeProbe::UID);
		$root->newFolder('MCP backups')->newFile('rc-upgrade-original.bak', 'original preserved by 1.2.0');
		$hasher = Server::get(LegacyOAuthNames::hasher($version));
		$token = $hasher->generate('at');
		$now = time();
		$store = Server::get(LegacyOAuthNames::store($version));
		$store->insertToken(['user_id' => ReleaseUpgradeProbe::UID, 'client_id' => $version === '1.2.0' ? 'https://mcp-it.example/rc-client' : 'https://claude.ai/mcp-it-client.json',
			'resource' => ReleaseUpgradeProbe::RESOURCE, 'scope' => 'mcp', 'access_hash' => $hasher->hash($token), 'access_expires' => $now + 3600,
			'refresh_hash' => $hasher->hash($hasher->generate('rt')), 'refresh_expires' => $now + 3600, 'created_at' => $now], $now);
		return ['baseline' => $version, 'token' => $token];
	}
}
