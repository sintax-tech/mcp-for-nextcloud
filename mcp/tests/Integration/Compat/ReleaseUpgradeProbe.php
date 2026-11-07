<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Compat;

use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Server;

require_once __DIR__ . '/LegacyOAuthNames.php';
require_once __DIR__ . '/LegacyPolicyName.php';
require_once __DIR__ . '/UpgradeBaseline.php';
require_once __DIR__ . '/ReleaseUpgradeFixture.php';
require_once __DIR__ . '/ReleaseUpgradeSnapshot.php';

/** Routes recognized container-era baseline checkpoints, preserving the 1.0.8 path. */
final class ReleaseUpgradeProbe {
	public const UID = 'mcp-it-rc-upgrade';
	public const BACKUP = 'MCP backups/rc-upgrade-original.bak';
	public const RESOURCE = 'http://localhost/index.php/apps/mcp/';

	/**
	 * @param string $kind credentials or policy, the existing harness checkpoint.
	 * @param string $output Harness-owned snapshot inside the disposable container.
	 * @return void Modern credentials return so the OAuth fixture also runs; policy checkpoints exit.
	 */
	public static function checkpoint(string $kind, string $output): void {
		$version = Server::get(IAppConfig::class)->getValueString('mcp', 'installed_version');
		if (!UpgradeBaseline::isRelease($version)) {
			return;
		}
		Server::get(IAppManager::class)->loadApp('mcp');
		if ($kind === 'credentials') {
			$data = ReleaseUpgradeFixture::create($version);
			$path = $version === '1.2.0' ? $output : $output . '.release.json';
			file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
			chmod($path, 0600);
		} elseif ($kind === 'policy') {
			file_put_contents($output, json_encode(['baseline' => $version, 'snapshot' => self::snapshot(true, $version)], JSON_THROW_ON_ERROR));
		} else {
			throw new \InvalidArgumentException('Unknown release upgrade checkpoint');
		}
		echo "Release upgrade checkpoint: $version / $kind (no credential values logged)\n";
		if ($kind === 'credentials' && $version !== '1.2.0') {
			return;
		}
		exit(0);
	}

	/**
	 * @param bool $baseline Whether the released baseline is still installed.
	 * @param string $version Recognized baseline version.
	 * @return array<string, mixed> Stable snapshot without OAuth plaintext.
	 */
	public static function snapshot(bool $baseline = false, string $version = '1.2.0'): array {
		return ReleaseUpgradeSnapshot::capture($version, $baseline);
	}
}
