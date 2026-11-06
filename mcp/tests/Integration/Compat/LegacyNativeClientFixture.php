<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Compat;

use OCA\Mcp\Db\OAuthClientsMapper;
use OCA\Mcp\Db\PolicyStateMapper;
use OCP\IConfig;
use OCP\Server;

require_once __DIR__ . '/UpgradeBaseline.php';

/** Prepares the native switch through the installed release's own admission command. */
final class LegacyNativeClientFixture {
	/**
	 * @param string $version Installed baseline version.
	 * @param string $output Private credential checkpoint; modern generation is added to its release sidecar.
	 * @return void The 1.0.8 migration input is retained exactly.
	 */
	public static function enable(string $version, string $output): void {
		Server::get(IConfig::class)->setAppValue('mcp', 'oauth_native_client_enabled', '1');
		if (!UpgradeBaseline::isModern($version)) {
			return;
		}
		$revision = Server::get(PolicyStateMapper::class)->state()['revision'];
		Server::get(LegacyOAuthNames::clients($version))->update($revision, null, true, null);
		$row = Server::get(OAuthClientsMapper::class)->find('nextcloud-mcp-native');
		if ($row === null || !$row['enabled']) {
			throw new \RuntimeException('The baseline did not enable the native client');
		}
		$path = $output . '.release.json';
		$data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		if (($data['baseline'] ?? null) !== $version) {
			throw new \UnexpectedValueException('The native client sidecar must belong to this baseline');
		}
		$data['native_generation'] = $row['gen'];
		file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
	}
}
