<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Compat;

use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\OAuth\TokenHasher;
use OCA\Mcp\Service\GrantPolicy;
use OCP\App\IAppManager;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Server;

/** Snapshot written by the real 1.2.0 baseline; leaves the older 1.0.8 scenario untouched. */
final class ReleaseUpgradeProbe {
	public const UID = 'mcp-it-rc-upgrade';
	public const BACKUP = 'MCP backups/rc-upgrade-original.bak';
	public const RESOURCE = 'http://localhost/index.php/apps/mcp/';

	/**
	 * @param string $kind credentials or policy, the existing harness checkpoint
	 * @param string $output harness-owned snapshot inside the disposable container
	 * @return void returns for all other baselines, exits after the 1.2.0 checkpoint
	 */
	public static function checkpoint(string $kind, string $output): void {
		if (Server::get(IAppConfig::class)->getValueString('mcp', 'installed_version') !== '1.2.0') {
			return;
		}
		Server::get(IAppManager::class)->loadApp('mcp');
		if ($kind === 'credentials') {
			$users = Server::get(IUserManager::class);
			$users->createUser(self::UID, bin2hex(random_bytes(24)));
			$policy = Server::get(GrantPolicy::class);
			$policy->setGlobalEnabled(true);
			$policy->setEligible(self::UID, true);
			$policy->setConnected(self::UID, true);
			$policy->setGrant(self::UID, 'files', 'edit', true);
			$policy->setGrant(self::UID, 'files', 'share', false);
			Server::get(IAppConfig::class)->setValueString('mcp', 'rc_upgrade_marker', 'preserve-1.2.0');
			$root = Server::get(IRootFolder::class)->getUserFolder(self::UID);
			$root->newFolder('MCP backups')->newFile('rc-upgrade-original.bak', 'original preserved by 1.2.0');
			$hasher = Server::get(TokenHasher::class);
			$token = $hasher->generate('at');
			$now = time();
			$store = Server::get(OAuthStore::class);
			$store->insertToken(['user_id' => self::UID, 'client_id' => 'https://mcp-it.example/rc-client',
				'resource' => self::RESOURCE, 'scope' => 'mcp', 'access_hash' => $hasher->hash($token), 'access_expires' => $now + 3600,
				'refresh_hash' => $hasher->hash($hasher->generate('rt')), 'refresh_expires' => $now + 3600, 'created_at' => $now], $now);
			file_put_contents($output, json_encode(['baseline' => '1.2.0', 'token' => $token], JSON_THROW_ON_ERROR));
			chmod($output, 0600);
		} else {
			file_put_contents($output, json_encode(['baseline' => '1.2.0', 'snapshot' => self::snapshot()], JSON_THROW_ON_ERROR));
		}
		echo "Release upgrade checkpoint: 1.2.0 / $kind (no credential values logged)\n";
		exit(0);
	}

	/** @return array<string, mixed> stable values/hashes only; OAuth plaintext never enters diagnostic output */
	public static function snapshot(): array {
		$database = Server::get(IDBConnection::class);
		$tables = ['preferences' => 'userid', 'mcp_oauth_tokens' => 'user_id'];
		$hashes = [];
		foreach ($tables as $table => $column) {
			$query = $database->getQueryBuilder();
			$result = $query->select('*')->from($table)->where($query->expr()->eq($column, $query->createNamedParameter(self::UID)))->executeQuery();
			try {
				$rows = $result->fetchAll();
			} finally {
				$result->closeCursor();
			}
			foreach ($rows as &$row) { ksort($row); $row = array_map('strval', $row); }
			unset($row);
			sort($rows);
			$hashes[$table] = ['count' => count($rows), 'sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
		}
		$policy = Server::get(GrantPolicy::class);
		$root = Server::get(IRootFolder::class)->getUserFolder(self::UID);
		return ['auth' => Server::get(\OCA\Mcp\Db\AuthUsersMapper::class)->generations(self::UID),
			'epoch' => Server::get(\OCA\Mcp\Db\PolicyStateMapper::class)->state()['auth_epoch'], 'rows' => $hashes, 'marker' => Server::get(IAppConfig::class)->getValueString('mcp', 'rc_upgrade_marker'),
			'eligible' => $policy->eligible(self::UID), 'connected' => $policy->connected(self::UID),
			'edit' => $policy->granted(self::UID, 'files', 'edit'), 'share' => $policy->granted(self::UID, 'files', 'share'),
			'backup_sha256' => hash('sha256', $root->get(self::BACKUP)->getContent())];
	}
}
