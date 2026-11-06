<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Compat;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Db\PolicyEventsMapper;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Server;

/** Compares stable baseline columns and complete receipt rows across the real upgrade. */
final class ReleaseUpgradeSnapshot {
	/**
	 * @param string $version Recognized baseline whose data is being compared.
	 * @param bool $baseline Whether that baseline is still installed.
	 * @return array<string, mixed> Stable values and hashes, without OAuth plaintext.
	 */
	public static function capture(string $version, bool $baseline): array {
		$database = Server::get(IDBConnection::class);
		$tables = ['preferences' => 'userid', 'mcp_oauth_tokens' => 'user_id'];
		$hashes = [];
		foreach ($tables as $table => $column) {
			$query = $database->getQueryBuilder();
			$result = $query->select('*')->from($table)->where($query->expr()->eq($column, $query->createNamedParameter(ReleaseUpgradeProbe::UID)))->executeQuery();
			try {
				$rows = $result->fetchAll();
			} finally {
				$result->closeCursor();
			}
			foreach ($rows as &$row) {
				if ($table === 'mcp_oauth_tokens' && $version !== '1.2.0') {
					if ((!$baseline || str_starts_with($version, '1.3.0-rc.')) && (string)($row['client_gen'] ?? '') !== '1') {
						throw new \UnexpectedValueException('The trusted probe token must retain client generation 1');
					}
					unset($row['client_gen']);
				}
				ksort($row);
				$row = array_map('strval', $row);
			}
			unset($row);
			sort($rows);
			$hashes[$table] = ['count' => count($rows), 'sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
		}
		$policy = Server::get($baseline ? LegacyPolicyName::get($version) : GrantPolicy::class);
		$root = Server::get(IRootFolder::class)->getUserFolder(ReleaseUpgradeProbe::UID);
		$snapshot = ['auth' => Server::get(\OCA\Mcp\Db\AuthUsersMapper::class)->generations(ReleaseUpgradeProbe::UID),
			'epoch' => Server::get(\OCA\Mcp\Db\PolicyStateMapper::class)->state()['auth_epoch'], 'rows' => $hashes, 'marker' => Server::get(IAppConfig::class)->getValueString('mcp', 'rc_upgrade_marker'),
			'eligible' => $policy->eligible(ReleaseUpgradeProbe::UID), 'connected' => $policy->connected(ReleaseUpgradeProbe::UID),
			'edit' => $policy->granted(ReleaseUpgradeProbe::UID, 'files', 'edit'), 'share' => $policy->granted(ReleaseUpgradeProbe::UID, 'files', 'share'),
			'backup_sha256' => hash('sha256', $root->get(ReleaseUpgradeProbe::BACKUP)->getContent())];
		if ($version !== '1.2.0') {
			$snapshot['receipts'] = self::receipts($database);
		}
		return $snapshot;
	}

	/** @param IDBConnection $database Real cell database. @return array{count:int, sha256:string} Probe-user receipt rows. */
	private static function receipts(IDBConnection $database): array {
		$query = $database->getQueryBuilder();
		$result = $query->select('*')->from(PolicyEventsMapper::TABLE)
			->where($query->expr()->eq('kind', $query->createNamedParameter(PolicyEventsMapper::KIND_RECEIPT)))->executeQuery();
		try {
			$rows = $result->fetchAll();
		} finally {
			$result->closeCursor();
		}
		$rows = array_values(array_filter($rows, static function (array $row): bool {
			$payload = json_decode((string)$row['payload'], true, 512, JSON_THROW_ON_ERROR);
			return in_array(ReleaseUpgradeProbe::UID, array_column($payload['changes'] ?? [], 'uid'), true);
		}));
		foreach ($rows as &$row) {
			ksort($row);
			$row = array_map('strval', $row);
		}
		unset($row);
		sort($rows);
		return ['count' => count($rows), 'sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
	}
}
